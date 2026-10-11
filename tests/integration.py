"""End-to-end HTTP tests in isolated private storage; never sends real email."""
import copy
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parent.parent


class Client:
    def __init__(self, base):
        self.base = base
        self.csrf = ''
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, data=None, headers=None, raw=None):
        request_headers = {'Origin': self.base, **(headers or {})}
        if data is not None:
            raw = json.dumps(data).encode()
            request_headers['Content-Type'] = 'application/json'
        if raw is not None:
            request_headers.setdefault('X-CSRF-Token', self.csrf)
        request = urllib.request.Request(self.base + path, data=raw, headers=request_headers)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        value = json.loads(body) if 'application/json' in response.headers.get('Content-Type', '') else body.decode('utf-8', errors='replace')
        return response.status, value, response.headers

    def action(self, action, data=None, status=200):
        code, value, headers = self.request('/api/admin.php?action=' + action, data)
        assert code == status, (action, code, value)
        return value


def run():
    uploads = []
    with tempfile.TemporaryDirectory(prefix='rkt-cms-test-') as private:
        env = dict(os.environ, RKT_STORAGE=private, RKT_TEST_MAIL='1')
        token = subprocess.check_output(['php', 'tools/provision.php'], cwd=ROOT, env=env, text=True).strip()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(ROOT), str(ROOT/'tools/router.php')], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        base = f'http://127.0.0.1:{port}'
        client = Client(base)
        password = secrets.token_urlsafe(24)
        try:
            for attempt in range(40):
                try:
                    status = client.action('status')
                    break
                except urllib.error.URLError:
                    time.sleep(.1)
            else:
                raise RuntimeError('PHP server did not start')
            client.csrf = status['csrf']
            assert status['setupAvailable'] and not status['authenticated']
            client.action('state', status=401)
            result = client.action('setup', {'token':token, 'username':'qa-admin', 'password':password})
            client.csrf = result['csrf']
            client.action('setup', {'token':token, 'username':'qa-admin', 'password':password}, status=403)
            state = client.action('state')
            data = state['content']
            baseline = copy.deepcopy(data)
            assert len(data['products']) == 12 and 'password' not in state['mail']
            print('PASS: one-time setup, authenticated state, SMTP secret redaction')
            for path in ['/app/content.seed.json', '/tools/provision.php', '/.git/config']:
                assert client.request(path)[0] == 403, path
            _, _, headers = client.request('/admin/')
            assert 'noindex' in headers['X-Robots-Tag'] and "frame-ancestors 'none'" in headers['Content-Security-Policy']
            assert any(cookie.name == 'RKTADMIN' and cookie._rest.get('SameSite') == 'Strict' and 'HttpOnly' in cookie._rest for cookie in client.cookies)
            assert client.request('/api/admin.php?action=save', {}, {'X-CSRF-Token':'bad'})[0] == 403
            assert client.request('/api/admin.php?action=save', {}, {'Origin':'https://evil.example'})[0] == 403
            print('PASS: protected paths, secure session policy, CSRF and Origin checks')

            def upload(body, name, mime, expected=201):
                boundary = 'rkt' + secrets.token_hex(12)
                raw = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + body + f'\r\n--{boundary}--\r\n'.encode())
                code, result, _ = client.request('/api/admin.php?action=upload', headers={'Content-Type':'multipart/form-data; boundary='+boundary}, raw=raw)
                assert code == expected, (code, result)
                if code == 201:
                    uploads.append(result['path'])
                return result

            upload(b'<?php echo "unsafe";', 'unsafe.php.jpg', 'image/jpeg', 422)
            photo = upload((ROOT/'assets/kat-monitor.jpg').read_bytes(), '../../unsafe.php.jpg', 'image/jpeg')['path']
            assert re.fullmatch(r'uploads/[a-f0-9]{32}\.jpg', photo)
            doc = upload((ROOT/'assets/certificate-wernox.pdf').read_bytes(), 'certificate.pdf', 'application/pdf')['path']
            assert (ROOT/doc).read_bytes() == (ROOT/'assets/certificate-wernox.pdf').read_bytes()
            print('PASS: MIME validation, randomized filenames, image re-encoding, original PDF bytes')
            product = copy.deepcopy(data['products']['kat-monitor'])
            product.update(title='Проверка <script>alert(1)</script>', brand='TEST-BRAND', price='20 000 ₽', photos=[{'src':photo,'alt':'Тестовый снимок'}], specs=[['Напряжение','24 В']], published=True)
            data['products']['qa-new'] = product
            data['settings'].update(phone='+7 (900) 000-00-00', email='qa@example.com', recipient='qa@example.com', address='Тестовый адрес', webmasterCode='<meta name="yandex-verification" content="1234567890abcdef">', metricaCode='evil(); ym(12345678, "init", {});', mailEnabled=True)
            field = next(key for key, value in data['pages']['index'].items() if value.startswith('Мы предлагаем услуги выездного сервиса'))
            data['pages']['index'][field] = 'Новый текст <b>не HTML</b>'
            saved = client.action('save', {'revision':data['revision'], 'content':data})['content']
            assert saved['settings']['metricaCode'] == '12345678'
            client.action('save', {'revision':data['revision'], 'content':data}, status=409)
            data = saved
            code, home, _ = client.request('/index.html')
            assert code == 200 and 'Новый текст &lt;b&gt;не HTML&lt;/b&gt;' in home and 'tel:+79000000000' in home
            assert 'yandex-verification' in home and 'content="1234567890abcdef"' in home and 'ym(12345678,' in home and 'evil();' not in home
            code, product_page, _ = client.request('/product.html?part=qa-new')
            assert code == 200 and '20 000 ₽' in product_page and '&lt;script&gt;alert(1)&lt;/script&gt;' in product_page and '<script>alert(1)</script>' not in product_page
            assert '24 В' in product_page and 'name="consent"' in product_page
            assert client.request('/catalog.html')[0] == 200 and 'qa-new' in client.request('/catalog.html')[1]
            assert client.request('/product.html?part=does-not-exist')[0] == 404
            client.action('delete-media', {'path':photo}, status=409)
            bad = copy.deepcopy(data)
            bad['products']['qa-new']['photos'] = [{'src':'../app/auth.json','alt':'bad'}]
            client.action('save', {'revision':data['revision'], 'content':bad}, status=422)
            print('PASS: product create/edit/SSR, text and contacts, safe Yandex snippets, XSS escaping, concurrent-write protection')
            backups = client.action('backups')['backups']
            assert backups
            restored = client.action('restore', {'id':backups[-1]['id'],'revision':data['revision']})['content']
            assert 'qa-new' not in restored['products'] and restored['settings']['email'] == baseline['settings']['email']
            for path in uploads:
                client.action('delete-media', {'path':path})
                assert not (ROOT/path).exists()
            print('PASS: product removal via restore, revision backups, recovery archive for unused media')
            client.action('save-mail', {'mode':'smtp','host':'127.0.0.1','port':465,'security':'ssl','username':'x','password':'x'}, status=422)
            assert client.action('test-mail', {})['status'] == 'accepted'
            visitor = Client(base)
            code, result, _ = visitor.request('/api/lead.php')
            assert code == 200
            visitor.csrf = result['csrf']
            time.sleep(2.2)
            lead = {'name':'Тестовая заявка','email':'qa@example.com','phone':'+79000000000','comment':'Проверка карточки','website':'','consent':True}
            assert visitor.request('/api/lead.php', dict(lead, consent=False))[0] == 422
            assert visitor.request('/api/lead.php', dict(lead, website='spam'))[0] == 422
            code, result, _ = visitor.request('/api/lead.php', lead)
            assert code == 201 and result['ok']
            leads = client.action('leads')['leads']
            assert len(leads) == 1 and leads[0]['mailStatus'] == 'accepted'
            client.action('update-lead', {'id':leads[0]['id'],'status':'in_progress'})
            assert client.action('leads')['leads'][0]['status'] == 'in_progress'
            visitor.action('leads', status=401)
            print('PASS: consent and spam checks, private lead persistence, notification transport, lead status workflow')
            second = Client(base)
            second.csrf = second.action('status')['csrf']
            second.csrf = second.action('login', {'username':'qa-admin','password':password})['csrf']
            new_password = secrets.token_urlsafe(24)
            client.action('password', {'oldPassword':'wrong','password':new_password}, status=403)
            client.csrf = client.action('password', {'oldPassword':password,'password':new_password})['csrf']
            second.action('state', status=401)
            client.action('logout', {})
            client.action('state', status=401)
            print('PASS: password verification, session invalidation, logout')
        finally:
            server.terminate()
            server.wait(timeout=10)
            for path in uploads:
                target = ROOT/path
                if target.is_file() and re.fullmatch(r'uploads/[a-f0-9]{32}\.(jpg|pdf)', path):
                    target.unlink()
    print('All integration tests passed; isolated customer data removed.')


if __name__ == '__main__':
    run()

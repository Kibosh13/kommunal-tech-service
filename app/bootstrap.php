<?php
declare(strict_types=1);

const SITE_ROOT = __DIR__ . '/..';
const CONTENT_PAGES = ['index', 'hidromak', 'katmerciler', 'catalog', 'product'];
const PUBLIC_LABELS = ['details'=>'Подробнее','order'=>'Запросить цену','submit'=>'Отправить заявку','sku'=>'Артикул','brand'=>'Техника','skuMissing'=>'Артикул уточняется при подборе','photo'=>'Фото','diagram'=>'Каталожная схема','emptyCatalog'=>'В этом разделе пока нет опубликованных запчастей. Свяжитесь с нами для подбора.','liveFormNote'=>'Заявка сохранится у владельца сайта. Для срочного вопроса позвоните нам.'];

final class HttpError extends RuntimeException {
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

function storage_dir(): string {
    $dir = getenv('RKT_STORAGE') ?: dirname(realpath(SITE_ROOT)) . '/rkt1-private';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Private storage is unavailable');
    $real = realpath($dir);
    $root = realpath(SITE_ROOT);
    if ($real === $root || str_starts_with($real . '/', $root . '/')) throw new RuntimeException('Storage must be outside the public root');
    return $real;
}

function read_json(string $name, array $default = []): array {
    $path = storage_dir() . '/' . $name;
    if (!is_file($path)) return $default;
    $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Invalid private data');
    return $value;
}

function atomic_json(string $name, array $value): void {
    $dir = dirname(storage_dir() . '/' . $name);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $temporary = tempnam($dir, '.write-');
    try {
        chmod($temporary, 0600);
        if (file_put_contents($temporary, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot save data');
        if (!rename($temporary, storage_dir() . '/' . $name)) throw new RuntimeException('Cannot commit data');
    } finally { if (is_file($temporary)) unlink($temporary); }
}

function transaction(callable $callback): mixed {
    $handle = fopen(storage_dir() . '/store.lock', 'c');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock data');
    try { return $callback(); } finally { flock($handle, LOCK_UN); fclose($handle); }
}

function seed_content(): array {
    return json_decode(file_get_contents(__DIR__ . '/content.seed.json'), true, 512, JSON_THROW_ON_ERROR);
}

function content(): array { return read_json('content.json', seed_content()); }
function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function text_value(mixed $value, int $limit = 1000): string {
    if (!is_string($value) || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) throw new HttpError(422, 'Недопустимое значение или слишком длинный текст.');
    return trim($value);
}

function email_value(mixed $value): string {
    $value = text_value($value, 254);
    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $value)) throw new HttpError(422, 'Укажите корректный адрес электронной почты.');
    return $value;
}

function local_media(mixed $value, bool $allowPdf = false): string {
    $value = text_value($value, 300);
    if (!preg_match('~^(assets|uploads)/[a-zA-Z0-9_.-]+\.(jpe?g|png|webp|gif' . ($allowPdf ? '|pdf' : '') . ')$~i', $value)) throw new HttpError(422, 'Выберите изображение или документ из медиатеки.');
    $path = realpath(SITE_ROOT . '/' . $value);
    if (!$path || !str_starts_with($path, realpath(SITE_ROOT) . '/') || !is_file($path)) throw new HttpError(422, 'Файл не найден в медиатеке.');
    return $value;
}

function safe_link(mixed $value): string {
    $value = text_value($value, 1000);
    if (preg_match('/[\r\n\\\\]/', $value) || str_starts_with($value, '//') || str_contains($value, '..')) throw new HttpError(422, 'Недопустимая ссылка.');
    if ($value === '#' || preg_match('~^(#[\w-]+|(?:index|hidromak|katmerciler|catalog|product)\.(?:html|php)(?:[?#][a-zA-Z0-9_%&=.#-]*)?|assets/[a-zA-Z0-9_.-]+\.pdf|uploads/[a-zA-Z0-9_.-]+\.pdf|https://[^\s<>"\x27]+|tel:\+?[0-9 ()-]+|mailto:[^\s<>"\x27]+)$~', $value)) return $value;
    throw new HttpError(422, 'Допустимы ссылки HTTPS, разделы сайта и PDF из медиатеки.');
}

function schema(): array { return json_decode(file_get_contents(__DIR__ . '/fields.schema.json'), true, 512, JSON_THROW_ON_ERROR); }

function validate_content(array $input): array {
    $settings = $input['settings'] ?? [];
    $out = ['revision' => bin2hex(random_bytes(12)), 'updatedAt' => gmdate('c'), 'settings' => [], 'pages' => [], 'products' => []];
    foreach (['brand' => 180, 'company' => 180, 'phone' => 40, 'address' => 500, 'leadSuccess' => 1000, 'consentText' => 1000] as $key => $limit) $out['settings'][$key] = text_value($settings[$key] ?? '', $limit);
    if (!preg_match('/^\+?[0-9 ()-]{7,40}$/', $out['settings']['phone'])) throw new HttpError(422, 'Проверьте номер телефона.');
    if ($out['settings']['brand'] === '') throw new HttpError(422, 'Укажите название сайта.');
    $out['settings']['email'] = email_value($settings['email'] ?? '');
    $out['settings']['recipient'] = email_value($settings['recipient'] ?? '');
    $out['settings']['sender'] = email_value($settings['sender'] ?? '');
    $out['settings']['mailEnabled'] = (bool) ($settings['mailEnabled'] ?? false);
    $out['settings']['indexingEnabled'] = (bool) ($settings['indexingEnabled'] ?? false);
    $metric = text_value($settings['metricaCode'] ?? '', 16000);
    $metricId = '';
    if ($metric !== '') {
        if (preg_match('/^\d{1,12}$/', $metric)) $metricId = $metric;
        elseif (preg_match('/(?:ym\s*\(\s*|id\s*:\s*|watch\/)(\d{1,12})/', $metric, $match)) $metricId = $match[1];
        else throw new HttpError(422, 'Вставьте код счётчика Яндекс Метрики или его числовой номер.');
    }
    $out['settings']['metricaCode'] = $metricId;
    $out['settings']['webvisor'] = (bool) ($settings['webvisor'] ?? false);
    $verification = text_value($settings['webmasterCode'] ?? '', 1500);
    if (preg_match('/content\s*=\s*["\x27]([a-f0-9]{16,64})["\x27]/i', $verification, $match)) $verification = $match[1];
    if ($verification !== '' && !preg_match('/^[a-f0-9]{16,64}$/i', $verification)) throw new HttpError(422, 'Вставьте метатег yandex-verification или его значение content.');
    $out['settings']['webmasterCode'] = $verification;
    $out['settings']['labels'] = [];
    foreach (PUBLIC_LABELS as $key=>$default) $out['settings']['labels'][$key]=text_value($settings['labels'][$key]??$default,1000);
    foreach (schema() as $page => $fields) {
        $out['pages'][$page] = [];
        foreach ($fields as $key => $field) {
            $value = text_value($input['pages'][$page][$key] ?? '', $field['type'] === 'text' ? 12000 : 1000);
            if ($field['type'] === 'image') $value = local_media($value);
            elseif ($field['type'] === 'link') $value = safe_link($value);
            $out['pages'][$page][$key] = $value;
        }
    }
    $products = $input['products'] ?? [];
    if (!is_array($products) || count($products) > 2000) throw new HttpError(422, 'Слишком много товаров.');
    foreach ($products as $id => $p) {
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $id) || !is_array($p)) throw new HttpError(422, 'Идентификатор товара: латинские буквы, цифры и дефис.');
        $item = [];
        foreach (['title'=>250, 'brand'=>100, 'category'=>150, 'sku'=>120, 'summary'=>2000, 'selection'=>2000, 'price'=>100, 'availability'=>100, 'photoNote'=>1000] as $key => $limit) $item[$key] = text_value($p[$key] ?? '', $limit);
        if ($item['title'] === '' || $item['brand'] === '') throw new HttpError(422, 'У товара должны быть название и марка техники.');
        $item['published'] = (bool) ($p['published'] ?? false);
        $item['diagram'] = (bool) ($p['diagram'] ?? false);
        $item['order'] = max(0, min(99999, (int) ($p['order'] ?? 0)));
        $item['description'] = [];
        if (!is_array($p['description'] ?? null) || count($p['description']) > 40) throw new HttpError(422, 'Проверьте описание товара.');
        foreach ($p['description'] as $paragraph) $item['description'][] = text_value($paragraph, 12000);
        $item['specs'] = [];
        if (!is_array($p['specs'] ?? null) || count($p['specs']) > 80) throw new HttpError(422, 'Не больше 80 характеристик товара.');
        foreach ($p['specs'] as $pair) {
            if (!is_array($pair) || count($pair) !== 2) throw new HttpError(422, 'Характеристика должна содержать название и значение.');
            $item['specs'][] = [text_value($pair[0], 200), text_value($pair[1], 1000)];
        }
        $item['photos'] = [];
        if (!is_array($p['photos'] ?? null) || ($item['published'] && count($p['photos']) < 1) || count($p['photos']) > 20) throw new HttpError(422, 'Для публикации добавьте от 1 до 20 фотографий товара.');
        foreach ($p['photos'] as $photo) $item['photos'][] = ['src'=>local_media($photo['src'] ?? ''), 'alt'=>text_value($photo['alt'] ?? '', 300)];
        $out['products'][$id] = $item;
    }
    return $out;
}

function save_content(array $input, string $expected): array {
    $validated = validate_content($input);
    return transaction(function() use ($validated, $expected) {
        $old = content();
        if (!hash_equals($old['revision'], $expected)) throw new HttpError(409, 'Сайт изменён в другом окне. Обновите данные перед сохранением.');
        atomic_json('revisions/' . gmdate('Ymd-His') . '-' . $old['revision'] . '.json', $old);
        atomic_json('content.json', $validated);
        $files = glob(storage_dir() . '/revisions/*.json') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files)-30)) as $file) unlink($file);
        return $validated;
    });
}

function security_headers(bool $admin = false): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    if ($admin) {
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }
}

function local_request(): bool { return in_array($_SERVER['SERVER_NAME'] ?? '', ['127.0.0.1', 'localhost'], true); }
function secure_request(): bool { return ($_SERVER['HTTPS'] ?? '') === 'on'; }

function session_begin(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (!secure_request() && !local_request() && PHP_SAPI !== 'cli') throw new HttpError(400, 'Используйте защищённое соединение HTTPS.');
    $sessions = storage_dir() . '/sessions';
    if (!is_dir($sessions)) mkdir($sessions, 0700, true);
    session_save_path($sessions);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('RKTADMIN');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!local_request(),'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}

function require_admin(): void {
    session_begin();
    $auth = read_json('auth.json');
    if (!isset($_SESSION['admin']) || $_SESSION['version'] !== ($auth['version'] ?? '') || time()-($_SESSION['lastSeen'] ?? 0)>1800 || time()-($_SESSION['loginAt'] ?? 0)>28800) {
        unset($_SESSION['admin']);
        throw new HttpError(401, 'Войдите в админку.');
    }
    $_SESSION['lastSeen'] = time();
}

function require_csrf(): void {
    session_begin();
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new HttpError(403, 'Обновите страницу: защитный токен устарел.');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $expected = (local_request() ? 'http://' : 'https://') . ($_SERVER['HTTP_HOST'] ?? '');
        if (!hash_equals($expected, $origin)) throw new HttpError(403, 'Запрос с другого сайта запрещён.');
    }
}

function request_json(): array {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2000000) throw new HttpError(413, 'Слишком большой запрос.');
    $data = json_decode(file_get_contents('php://input', false, null, 0, 2000001), true);
    if (!is_array($data)) throw new HttpError(400, 'Некорректный запрос.');
    return $data;
}

function rate_limit(string $kind, int $limit, int $period): void {
    $auth = read_json('auth.json');
    $key = hash_hmac('sha256', $kind . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $auth['rateKey'] ?? 'uninitialized');
    transaction(function() use ($key,$limit,$period) {
        $file = 'rates/' . $key . '.json';
        $data = read_json($file, ['start'=>time(), 'count'=>0]);
        if (time()-$data['start']>$period) $data=['start'=>time(),'count'=>0];
        if ($data['count'] >= $limit) throw new HttpError(429, 'Слишком много попыток. Попробуйте позже.');
        $data['count']++;
        atomic_json($file,$data);
        foreach (glob(storage_dir() . '/rates/*.json') ?: [] as $path) if (filemtime($path) < time()-86400) unlink($path);
    });
}

function json_response(array $value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function handle_error(Throwable $error): never {
    if ($error instanceof HttpError) json_response(['error'=>$error->getMessage()], $error->status);
    error_log('RKT CMS error: ' . get_class($error));
    json_response(['error'=>'Не удалось выполнить запрос. Повторите позже или обратитесь к администратору хостинга.'],500);
}

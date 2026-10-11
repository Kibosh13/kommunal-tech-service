<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function mail_config(): array {
    return read_json('mail-secret.json',['mode'=>'mail','host'=>'mail.hosting.reg.ru','port'=>465,'security'=>'ssl','username'=>'','password'=>'']);
}

function send_notification(array $lead, bool $test=false): string {
    if (getenv('RKT_TEST_MAIL')==='1') return 'accepted'; // CLI integration-test fixture only.
    $settings=content()['settings'];
    if (!$settings['mailEnabled']&&!$test) return 'disabled';
    $config=mail_config();
    try {
        require_once SITE_ROOT.'/vendor/autoload.php';
        $mail=new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet='UTF-8';$mail->Timeout=15;$mail->SMTPDebug=0;
        if ($config['mode']==='smtp') {
            $mail->isSMTP();$mail->Host=$config['host'];$mail->Port=$config['port'];
            $mail->SMTPSecure=$config['security']==='ssl'?PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS:PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth=true;$mail->Username=$config['username'];$mail->Password=$config['password'];
            // Never disable certificate verification or downgrade to plaintext SMTP.
            $mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
        } else $mail->isMail();
        $mail->setFrom($settings['sender'],$settings['company']);
        $mail->addAddress($settings['recipient']);
        if (!$test) $mail->addReplyTo($lead['email'],$lead['name']);
        $mail->Subject=$test?'rkt1.ru — проверка уведомлений':'Новая заявка rkt1.ru — '.$lead['id'];
        $mail->Body=$test?'Это проверка отправки уведомлений с rkt1.ru. Если письмо получено, настройка работает.':'Заявка: '.$lead['id']."\nДата: ".$lead['createdAt']."\nИмя: ".$lead['name']."\nПочта: ".$lead['email']."\nТелефон: ".$lead['phone']."\n\n".$lead['comment']."\n\nЗаявка также сохранена в админке: https://rkt1.ru/admin/";
        $mail->send();
        return 'accepted'; // Transport accepted; inbox delivery cannot be guaranteed.
    } catch (Throwable $error) { return 'failed'; }
}

function public_mail_config(): array {
    $config=mail_config();unset($config['password']);$config['hasPassword']=mail_config()['password']!=='';
    return $config;
}

function save_mail_config(array $input): array {
    $mode=$input['mode']??'mail';
    if (!in_array($mode,['mail','smtp'],true)) throw new HttpError(422,'Выберите способ отправки писем.');
    $host=strtolower(text_value($input['host']??'mail.hosting.reg.ru',253));
    $port=(int)($input['port']??465);
    $security=$input['security']??'ssl';
    if (!preg_match('/^(?=.{1,253}$)[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/',$host) || !in_array($port,[465,587],true) || !in_array($security,['ssl','tls'],true)) throw new HttpError(422,'SMTP: укажите имя внешнего сервера и защищённый порт 465 или 587.');
    if ($mode==='smtp') {
        $addresses=gethostbynamel($host);
        if (!$addresses) throw new HttpError(422,'Адрес SMTP-сервера не найден.');
        foreach ($addresses as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new HttpError(422,'Внутренний адрес SMTP-сервера запрещён.');
    }
    $username=text_value($input['username']??'',254);
    $password=$input['password']??'';
    if (!is_string($password)||strlen($password)>1000||str_contains($password,"\0")) throw new HttpError(422,'Проверьте пароль SMTP.');
    $old=mail_config();
    if ($password==='') $password=$old['password'];
    if ($mode==='smtp'&&($username===''||$password==='')) throw new HttpError(422,'Укажите логин и пароль почтового ящика для SMTP.');
    atomic_json('mail-secret.json',['mode'=>$mode,'host'=>$host,'port'=>$port,'security'=>$security,'username'=>$username,'password'=>$password]);
    return public_mail_config();
}

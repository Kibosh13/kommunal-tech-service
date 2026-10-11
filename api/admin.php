<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/mail.php';
security_headers(true);

function valid_password(mixed $password): string {
    if (!is_string($password)||mb_strlen($password)<12||strlen($password)>72||str_contains($password,"\0")) throw new HttpError(422,'Пароль: от 12 символов и не более 72 байт.');
    return $password;
}

function login_session(array $auth): void {
    session_regenerate_id(true);
    $_SESSION=['admin'=>$auth['username'],'version'=>$auth['version'],'loginAt'=>time(),'lastSeen'=>time(),'csrf'=>bin2hex(random_bytes(24))];
}

function media_library(): array {
    $files=[];
    foreach (['assets','uploads'] as $folder) foreach (glob(SITE_ROOT.'/'.$folder.'/*') ?: [] as $path) {
        if (!is_file($path)||is_link($path)||!preg_match('/\.(jpe?g|png|gif|webp|pdf)$/i',$path)) continue;
        $files[]=['path'=>$folder.'/'.basename($path),'name'=>basename($path),'size'=>filesize($path),'kind'=>str_ends_with(strtolower($path),'.pdf')?'pdf':'image','deletable'=>$folder==='uploads'];
    }
    return $files;
}

try {
    session_begin();
    $action=$_GET['action']??'status';$method=$_SERVER['REQUEST_METHOD'];
    if ($method==='POST') require_csrf();
    elseif ($method!=='GET') throw new HttpError(405,'Метод не поддерживается.');
    if ($action==='status'&&$method==='GET') {
        $auth=read_json('auth.json');$signed=false;
        try { require_admin();$signed=true; } catch (HttpError $error) {}
        json_response(['authenticated'=>$signed,'username'=>$signed?$_SESSION['admin']:null,'csrf'=>$_SESSION['csrf'],'setupAvailable'=>isset($auth['setupHash'])&&$auth['setupHash']!==''&&($auth['setupExpires']??0)>time()&&empty($auth['passwordHash'])]);
    }
    if ($action==='login'&&$method==='POST') {
        rate_limit('login',10,900);$input=request_json();$auth=read_json('auth.json');
        $username=text_value($input['username']??'',50);$password=$input['password']??'';
        if (!is_string($password)||strlen($password)>1000) throw new HttpError(401,'Неверный логин или пароль.');
        $hash=$auth['passwordHash']??'$2y$12$JHBHovF2NZeeuqgqPXNLyO5KTWYZO5QrXeakIOBJyuVLfuEcFagkS';
        $matches=password_verify($password,$hash);
        if (!$matches||empty($auth['passwordHash'])||!hash_equals($auth['username'],$username)) throw new HttpError(401,'Неверный логин или пароль.');
        login_session($auth);json_response(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    if ($action==='setup'&&$method==='POST') {
        rate_limit('setup',10,3600);$input=request_json();$password=valid_password($input['password']??null);
        $username=text_value($input['username']??'',50);
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/',$username)) throw new HttpError(422,'Логин: от 3 символов, латинские буквы, цифры, точка, дефис.');
        $token=text_value($input['token']??'',200);
        $auth=transaction(function() use ($token,$password,$username) {
            $auth=read_json('auth.json');
            if (!empty($auth['passwordHash'])||empty($auth['setupHash'])||($auth['setupExpires']??0)<time()||!hash_equals($auth['setupHash'],hash('sha256',$token))) throw new HttpError(403,'Код активации недействителен или уже использован.');
            $auth['username']=$username;$auth['passwordHash']=password_hash($password,PASSWORD_DEFAULT);$auth['version']=bin2hex(random_bytes(16));$auth['setupHash']='';$auth['setupExpires']=0;
            atomic_json('auth.json',$auth);return $auth;
        });
        login_session($auth);json_response(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    require_admin();
    if ($action==='logout'&&$method==='POST') { $_SESSION=[];session_destroy();json_response(['ok'=>true]); }
    if ($action==='state'&&$method==='GET') json_response(['content'=>content(),'schema'=>schema(),'media'=>media_library(),'mail'=>public_mail_config(),'username'=>$_SESSION['admin']]);
    if ($action==='save'&&$method==='POST') { $input=request_json();$saved=save_content($input['content']??[],text_value($input['revision']??'',100));json_response(['content'=>$saved]); }
    if ($action==='upload'&&$method==='POST') {
        $upload=$_FILES['file']??null;
        if (!$upload||$upload['error']!==UPLOAD_ERR_OK||$upload['size']>10*1024*1024||!is_uploaded_file($upload['tmp_name'])) throw new HttpError(422,'Загрузите JPG, PNG, WebP или PDF размером не более 10 МБ.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
        if (!isset($allowed[$mime])) throw new HttpError(422,'Этот тип файла запрещён. Разрешены JPG, PNG, WebP и PDF.');
        if ($mime==='application/pdf'&&file_get_contents($upload['tmp_name'],false,null,0,5)!=='%PDF-') throw new HttpError(422,'Повреждённый PDF.');
        $folder=SITE_ROOT.'/uploads';if (!is_dir($folder)) mkdir($folder,0755,true);
        $name=bin2hex(random_bytes(16)).'.'.$allowed[$mime];$destination=$folder.'/'.$name;
        if ($mime==='application/pdf') { if (!move_uploaded_file($upload['tmp_name'],$destination)) throw new RuntimeException('Cannot save PDF'); }
        else {
            $size=getimagesize($upload['tmp_name']);
            if (!$size||$size[0]*$size[1]>16000000||$size[0]>12000||$size[1]>12000) throw new HttpError(422,'Изображение слишком большое или повреждено (максимум 16 мегапикселей).');
            $image=match($mime) {'image/jpeg'=>imagecreatefromjpeg($upload['tmp_name']),'image/png'=>imagecreatefrompng($upload['tmp_name']),'image/webp'=>imagecreatefromwebp($upload['tmp_name'])};
            if (!$image) throw new HttpError(422,'Не удалось прочитать изображение.');
            imagesavealpha($image,true);
            $ok=match($mime) {'image/jpeg'=>imagejpeg($image,$destination,90),'image/png'=>imagepng($image,$destination,6),'image/webp'=>imagewebp($image,$destination,90)};
            imagedestroy($image);if (!$ok) throw new RuntimeException('Cannot save image');
        }
        chmod($destination,0644);
        json_response(['path'=>'uploads/'.$name,'media'=>media_library()],201);
    }
    if ($action==='delete-media'&&$method==='POST') {
        $input=request_json();$path=text_value($input['path']??'',200);
        if (!preg_match('~^uploads/[a-f0-9]{32}\.(jpg|png|webp|pdf)$~',$path)) throw new HttpError(422,'Можно удалить только пользовательскую загрузку.');
        transaction(function() use ($path) {
            $data=json_encode(content(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (str_contains($data,'"'.$path.'"')) throw new HttpError(409,'Файл используется на сайте. Сначала замените его в тексте или карточке товара.');
            $file=SITE_ROOT.'/'.$path;
            if (!is_file($file)||is_link($file)) throw new HttpError(404,'Файл не найден.');
            $trash=storage_dir().'/media-trash';if (!is_dir($trash)) mkdir($trash,0700,true);
            if (!rename($file,$trash.'/'.gmdate('YmdHis').'-'.basename($file))) throw new RuntimeException('Cannot archive file');
        });
        json_response(['media'=>media_library()]);
    }
    if ($action==='leads'&&$method==='GET') json_response(['leads'=>array_reverse(read_json('leads.json'))]);
    if ($action==='update-lead'&&$method==='POST') {
        $input=request_json();$id=text_value($input['id']??'',100);$status=$input['status']??'';
        if (!in_array($status,['new','in_progress','done','archived'],true)) throw new HttpError(422,'Неверный статус заявки.');
        transaction(function() use ($id,$status) { $leads=read_json('leads.json');$found=false;foreach ($leads as &$lead) if ($lead['id']===$id) {$lead['status']=$status;$found=true;}unset($lead);if (!$found) throw new HttpError(404,'Заявка не найдена.');atomic_json('leads.json',$leads); });
        json_response(['ok'=>true]);
    }
    if ($action==='backups'&&$method==='GET') {
        $backups=[];foreach (glob(storage_dir().'/revisions/*.json') ?: [] as $file) $backups[]=['id'=>basename($file),'createdAt'=>gmdate('c',filemtime($file))];
        json_response(['backups'=>array_reverse($backups)]);
    }
    if ($action==='restore'&&$method==='POST') {
        $input=request_json();$id=text_value($input['id']??'',100);
        if (!preg_match('/^\d{8}-\d{6}-[a-zA-Z0-9-]+\.json$/',$id)||!is_file(storage_dir().'/revisions/'.$id)) throw new HttpError(404,'Резервная копия не найдена.');
        $restored=save_content(read_json('revisions/'.$id),text_value($input['revision']??'',100));json_response(['content'=>$restored]);
    }
    if ($action==='download'&&$method==='GET') { header('Content-Disposition: attachment; filename="rkt1-content-backup.json"');json_response(content()); }
    if ($action==='save-mail'&&$method==='POST') json_response(['mail'=>save_mail_config(request_json())]);
    if ($action==='test-mail'&&$method==='POST') { rate_limit('test-mail',5,3600);json_response(['status'=>send_notification([],true)]); }
    if ($action==='password'&&$method==='POST') {
        $input=request_json();$password=valid_password($input['password']??null);$old=$input['oldPassword']??'';
        if (!is_string($old)||strlen($old)>1000) throw new HttpError(403,'Проверьте текущий пароль.');
        $auth=transaction(function() use ($old,$password) { $auth=read_json('auth.json');if (!password_verify($old,$auth['passwordHash'])) throw new HttpError(403,'Проверьте текущий пароль.');$auth['passwordHash']=password_hash($password,PASSWORD_DEFAULT);$auth['version']=bin2hex(random_bytes(16));atomic_json('auth.json',$auth);return $auth; });
        login_session($auth);json_response(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    throw new HttpError(404,'Действие не найдено.');
} catch (Throwable $error) { handle_error($error); }

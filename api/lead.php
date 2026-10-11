<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/mail.php';
security_headers();
try {
    session_begin();
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $_SESSION['leadAt']=time();
        json_response(['csrf'=>$_SESSION['csrf']]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new HttpError(405,'Метод не поддерживается.');
    require_csrf();rate_limit('lead',5,3600);$input=request_json();
    if (!empty($input['website']) || time()-($_SESSION['leadAt']??time())<2) throw new HttpError(422,'Пожалуйста, подождите пару секунд и повторите отправку.');
    if (($input['consent']??false)!==true) throw new HttpError(422,'Нужно согласие на использование контактных данных для ответа.');
    $lead=['id'=>gmdate('Ymd').'-'.bin2hex(random_bytes(5)), 'createdAt'=>gmdate('c'), 'name'=>text_value($input['name']??'',150), 'email'=>email_value($input['email']??''), 'phone'=>text_value($input['phone']??'',60), 'comment'=>text_value($input['comment']??'',6000), 'status'=>'new', 'mailStatus'=>'pending', 'consentAt'=>gmdate('c')];
    if ($lead['name']==='') throw new HttpError(422,'Укажите имя.');
    transaction(function() use ($lead) {
        $leads=read_json('leads.json');if (count($leads)>=5000) throw new HttpError(503,'Свяжитесь с нами по телефону или почте.');
        $leads[]=$lead;atomic_json('leads.json',$leads);
    });
    session_write_close();
    $status=send_notification($lead);
    transaction(function() use ($lead,$status) {
        $leads=read_json('leads.json');foreach ($leads as &$item) if ($item['id']===$lead['id']) $item['mailStatus']=$status;unset($item);atomic_json('leads.json',$leads);
    });
    json_response(['ok'=>true,'id'=>$lead['id'],'message'=>content()['settings']['leadSuccess']],201);
} catch (Throwable $error) { handle_error($error); }

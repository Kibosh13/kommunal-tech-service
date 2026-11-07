<?php
declare(strict_types=1);
require __DIR__.'/../app/leads.php';
require __DIR__.'/../app/mail.php';

function check(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$data=seed_content();
$card='https://rkt1.ru/product.html?part=kat-monitor';
$context=lead_context(['productId'=>'kat-monitor','sourcePage'=>'/catalog.html?utm_email=private@example.com','product'=>['title'=>'FORGED','url'=>'https://evil.example/']],$data);
check($context['product']['url']===$card && $context['product']['sku']==='50KM015','Resolve the real product, never the submitted title or link');
check($context['sourceUrl']==='https://rkt1.ru/catalog.html','Discard tracking parameters');
check(lead_context(['sourcePage'=>'/product.php?part=kat-monitor&x=discarded'],$data)['product']['url']===$card,'Product URL fallback');
check(lead_context([],$data,'https://www.rkt1.ru/product.html?part=kat-monitor')['sourceUrl']===$card,'Cached client: same-site Referer fallback');
check(lead_context([],$data,'https://evil.example/product.html?part=kat-monitor')['product']===null,'Reject third-party Referer');
check(lead_context(['sourcePage'=>'https://evil.example/product.html?part=kat-monitor'],$data)['sourceUrl']==='','Reject external source URL');
check(lead_context(['sourcePage'=>'//evil.example/product.html?part=kat-monitor'],$data)['product']===null,'Reject protocol-relative source');
check(lead_context(['sourcePage'=>'/product.html?part[]=kat-monitor'],$data)['product']===null,'Reject array parameters');
check(lead_context(['sourcePage'=>'/product.html?part=missing'],$data)['product']===null,'Do not invent unknown products');
foreach (['missing','../kat-monitor',[],"kat-monitor\n"] as $id) {
    try {lead_context(['productId'=>$id],$data);throw new RuntimeException('Invalid product accepted');}
    catch (HttpError $error) {check($error->status===422,'Invalid product validation');}
}
$data['products']['kat-monitor']['published']=false;
try {lead_context(['productId'=>'kat-monitor'],$data);throw new RuntimeException('Draft product accepted');}
catch (HttpError $error) {check($error->status===422,'Unpublished product validation');}
$lead=['id'=>'QA-ONLY','createdAt'=>'2026-11-07T00:00:00Z','name'=>'Тест','email'=>'qa@example.com','phone'=>'','comment'=>'Переписанный комментарий, без названия товара.']+$context;
$message=notification_message($lead);
check(str_contains($message['body'],$card) && str_contains($message['body'],'Артикул: 50KM015') && str_contains($message['body'],$lead['comment']),'Email includes product independently of comment');
$data['products']['kat-monitor']['title']='CHANGED AFTER SUBMISSION';
check(!str_contains(notification_message($lead)['body'],'CHANGED AFTER SUBMISSION'),'Lead stores an immutable product snapshot');
check(str_contains(notification_message(array_replace($lead,['product'=>null]))['body'],'Общая заявка'),'General requests stay general');
unset($lead['product'],$lead['sourceUrl']);
check(str_contains(notification_message($lead)['body'],'QA-ONLY'),'Old leads remain compatible');
echo "PASS: trusted product resolution, source URLs, cached clients, immutable snapshots, email formatting and old leads\n";

<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

// Resolve against our catalogue, never trust a submitted title or arbitrary URL.
// Store a snapshot so later product edits do not change an existing enquiry.
function lead_context(array $input, array $data, string $referer = ''): array {
    $source = $input['sourcePage'] ?? '';
    if (!is_string($source) || strlen($source)>1500) throw new HttpError(422,'Не удалось определить страницу заявки. Обновите страницу.');
    if ($source === '' && $referer !== '') {
        $ref = parse_url($referer);
        if (is_array($ref) && ($ref['scheme']??'')==='https' && in_array(strtolower($ref['host']??''),['rkt1.ru','www.rkt1.ru'],true)) {
            $source=($ref['path']??'/').(isset($ref['query'])?'?'.$ref['query']:'');
        }
    }
    $path='';$query=[];$url='';
    if ($source!=='' && !str_starts_with($source,'//') && !preg_match('~[\r\n\\\\]~',$source)) {
        $parts=parse_url($source);
        if (is_array($parts) && !isset($parts['scheme']) && !isset($parts['host'])) {
            $path=$parts['path']??'';
            if (str_starts_with($path,'/')) $path=substr($path,1);
            if ($path==='') $path='index.html';
            if (preg_match('/^(index|hidromak|katmerciler|catalog|product)\.(?:html|php)$/',$path,$match)) {
                $path=$match[1].'.html';$url='https://rkt1.ru/'.$path;
                parse_str($parts['query']??'',$query);
            } else $path='';
        }
    }
    $id=$input['productId']??'';
    if (!is_string($id) || ($id!=='' && !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/',$id))) throw new HttpError(422,'Не удалось определить товар. Обновите карточку товара.');
    $explicit=$id!=='';
    // Older cached scripts still supply the browser's same-site Referer.
    if ($id==='' && $path==='product.html' && is_string($query['part']??null)) $id=$query['part'];
    $product=$data['products'][$id]??null;
    if (!$product || !$product['published']) {
        if ($explicit) throw new HttpError(422,'Выбранный товар больше недоступен. Обновите страницу или свяжитесь с нами по телефону.');
        return ['product'=>null,'sourceUrl'=>$url];
    }
    $card='https://rkt1.ru/product.html?part='.rawurlencode($id);
    if ($path==='product.html' && ($query['part']??null)===$id) $url=$card;
    return ['product'=>['id'=>$id,'title'=>$product['title'],'sku'=>$product['sku'],'brand'=>$product['brand'],'url'=>$card],'sourceUrl'=>$url];
}

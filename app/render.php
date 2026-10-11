<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function published_products(array $data): array {
    $products=array_filter($data['products'],fn($p)=>$p['published']);
    uasort($products,fn($a,$b)=>$a['order']<=>$b['order']);
    return $products;
}

function product_list(array $products, array $labels): string {
    $html='';
    foreach ($products as $id=>$p) {
        $url='product.html?part='.rawurlencode($id); $photo=$p['photos'][0];
        $html.='<article class="part-item" data-brand="'.e($p['brand']).'"><a class="part-image" href="'.$url.'"><img src="'.e($photo['src']).'" alt="'.e($photo['alt']).'" loading="lazy"></a><div class="part-content"><p class="part-code">'.e($p['category'].($p['sku']?' · '.$p['sku']:'')).'</p><h2><a href="'.$url.'">'.e($p['title']).'</a></h2><p>'.e($p['summary']).'</p><p class="catalog-price">'.e($p['price']).' · '.e($p['availability']).'</p><div class="part-actions"><a class="button button-outline" href="'.$url.'">'.e($labels['details']).'</a><a class="button" href="#request" data-order="'.e($p['title'].($p['sku']?' · '.$p['sku']:'').' ('.$p['brand'].')').'">'.e($labels['order']).'</a></div></div></article>';
    }
    return $html;
}

function replace_inner(DOMNode $node,string $html,DOMDocument $dom): void {
    while ($node->firstChild) $node->removeChild($node->firstChild);
    $fragment=new DOMDocument('1.0','UTF-8');
    $fragment->loadHTML('<?xml encoding="UTF-8"><div id="fragment">'.$html.'</div>',LIBXML_NONET);
    $xp=new DOMXPath($fragment);
    foreach (iterator_to_array($xp->query('//*[@id="fragment"]')->item(0)->childNodes) as $child) $node->appendChild($dom->importNode($child,true));
}

function render_page(string $page,array $data,bool $demo=false,?string $part=null): string {
    if (!in_array($page,CONTENT_PAGES,true)) throw new HttpError(404,'Страница не найдена.');
    libxml_use_internal_errors(true);
    $dom=new DOMDocument('1.0','UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">'.file_get_contents(__DIR__.'/templates/'.$page.'.html'),LIBXML_NONET);
    $dom->encoding='UTF-8';
    foreach (iterator_to_array($dom->childNodes) as $child) if ($child instanceof DOMProcessingInstruction) $dom->removeChild($child);
    $xp=new DOMXPath($dom);
    foreach (schema()[$page] as $key=>$field) foreach ($field['bindings'] as $binding) {
        $node=$xp->query($binding['path'])->item(0);
        if (!$node) continue;
        $value=$data['pages'][$page][$key];
        if ($binding['attribute']) $node->nodeValue=$value;
        else $node->nodeValue=$binding['prefix'].$value.$binding['suffix'];
    }
    $settings=$data['settings'];
    $labels=$settings['labels']??PUBLIC_LABELS;
    foreach ($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," brand ")]') as $node) $node->textContent=$settings['brand'];
    foreach ($xp->query('//a[starts-with(@href,"tel:")]') as $node) {
        $node->setAttribute('href','tel:'.preg_replace('/[^+0-9]/','',$settings['phone']));
        if (!str_contains($node->getAttribute('class'),'button')) $node->textContent=$settings['phone'];
    }
    foreach ($xp->query('//a[starts-with(@href,"mailto:")]') as $node) { $node->setAttribute('href','mailto:'.$settings['email']); $node->textContent=$settings['email']; }
    foreach ($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," contact-list ")]/span[1]') as $node) $node->textContent=$settings['address'];
    foreach ($xp->query('//footer//text()') as $node) if (str_contains($node->textContent,'ИП Блинов С.Е.')) $node->nodeValue=' '.$settings['company'];
    $products=published_products($data);
    if ($page==='product') {
        $price=$xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," product-offer ")]/strong')->item(0);
        $price->setAttribute('id','product-price');
        $availability=$dom->createElement('p');$availability->setAttribute('class','product-availability');$availability->setAttribute('id','product-availability');$price->parentNode->insertBefore($availability,$price->nextSibling);
    }
    foreach ($xp->query('//*[@data-products]') as $list) {
        $brand=$list->getAttribute('data-products');
        $filtered=$brand==='all'?$products:array_filter($products,fn($p)=>$p['brand']===$brand);
        replace_inner($list,product_list($filtered,$labels),$dom);
        $empty=$dom->createElement('p',e($labels['emptyCatalog'])); $empty->setAttribute('class','catalog-empty'); $empty->setAttribute('id','catalog-empty');
        if ($filtered) $empty->setAttribute('hidden','');
        $list->parentNode->appendChild($empty);
    }
    foreach ($xp->query('//form[contains(@class,"request-form")]') as $form) {
        $form->setAttribute('data-live',$demo?'false':'true');
        $form->setAttribute('method','post');
        $form->setAttribute('action','api/lead.php');
        $note=$xp->query('.//*[contains(@class,"form-note")]',$form)->item(0);
        $note->textContent=$demo?'Демонстрационная форма: данные не отправляются. Для заказа используйте телефон или почту.':$labels['liveFormNote'];
        $xp->query('.//button[@type="submit"]',$form)->item(0)->textContent=$demo?'Проверить заявку (демо)':$labels['submit'];
        if (!$demo) {
            $actions=$xp->query('.//*[contains(@class,"form-actions")]',$form)->item(0);
            $label=$dom->createElement('label'); $label->setAttribute('class','form-consent');
            $box=$dom->createElement('input'); $box->setAttribute('type','checkbox');$box->setAttribute('name','consent');$box->setAttribute('required','');
            $label->appendChild($box);$label->appendChild($dom->createTextNode(' '.$settings['consentText']));$form->insertBefore($label,$actions);
            $honey=$dom->createElement('input');foreach (['name'=>'website','type'=>'text','tabindex'=>'-1','autocomplete'=>'off','class'=>'honeypot','aria-hidden'=>'true'] as $key=>$value) $honey->setAttribute($key,$value);$form->appendChild($honey);
        }
    }
    $head=$xp->query('//head')->item(0);
    if (!$demo && $settings['webmasterCode']) { $meta=$dom->createElement('meta');$meta->setAttribute('name','yandex-verification');$meta->setAttribute('content',$settings['webmasterCode']);$head->appendChild($meta); }
    $xp->query('//meta[@name="robots"]')->item(0)->setAttribute('content',!$demo&&$settings['indexingEnabled']?'index, follow':'noindex, nofollow, noarchive, nosnippet');
    $version=substr(hash('sha256',file_get_contents(SITE_ROOT.'/script.js').file_get_contents(SITE_ROOT.'/style.css').file_get_contents(SITE_ROOT.'/product.js')),0,12);
    foreach ($xp->query('//script[@src] | //link[@rel="stylesheet"]') as $node) {
        $attr=$node->tagName==='script'?'src':'href';$src=explode('?',$node->getAttribute($attr))[0];
        if ($src==='products.js'&&!$demo) $src='catalog-data.php';
        $node->setAttribute($attr,$src.'?v='.$version);
    }
    if ($page==='catalog') { $script=$dom->createElement('script');$script->setAttribute('src','catalog-view.js?v='.$version);$script->setAttribute('defer','');$head->appendChild($script); }
    if ($page==='product'&&$part!==null) {
        if (isset($products[$part])) {
            $p=$products[$part];$xp->query('//title')->item(0)->textContent=$p['title'].($p['sku']?' '.$p['sku']:'').' — '.$settings['brand'];
            $xp->query('//meta[@name="description"]')->item(0)->setAttribute('content',$p['summary']);
            $dom->getElementById('product-detail')->removeAttribute('hidden');
            foreach (['product-title'=>$p['title'],'product-breadcrumb'=>$p['title'],'product-category'=>$p['category'],'product-summary'=>$p['summary'],'product-sku'=>$p['sku']?$labels['sku'].': '.$p['sku']:$labels['skuMissing'],'product-brand'=>$labels['brand'].': '.$p['brand'],'product-selection'=>$p['selection'],'product-price'=>$p['price'],'product-availability'=>$p['availability']] as $id=>$text) $dom->getElementById($id)->textContent=$text;
            $dom->getElementById('product-image')->setAttribute('src',$p['photos'][0]['src']);$dom->getElementById('product-image')->setAttribute('alt',$p['photos'][0]['alt']);
            foreach ($p['description'] as $paragraph) $dom->getElementById('product-description')->appendChild($dom->createElement('p',e($paragraph)));
            foreach ($p['specs'] as [$label,$value]) { $row=$dom->createElement('div');$row->appendChild($dom->createElement('dt',e($label)));$row->appendChild($dom->createElement('dd',e($value)));$dom->getElementById('product-specs')->appendChild($row); }
        } elseif (!$demo) http_response_code(404);
    }
    $body=$xp->query('//body')->item(0);
    $runtime=$dom->createElement('script');$runtime->setAttribute('type','application/json');$runtime->setAttribute('id','cms-runtime');
    $runtime->appendChild($dom->createTextNode(json_encode(['mode'=>$demo?'demo':'live','phone'=>$settings['phone'],'email'=>$settings['email'],'brand'=>$settings['brand'],'labels'=>$labels],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
    $body->appendChild($runtime);
    if (!$demo&&$settings['metricaCode']) {
        $id=(int)$settings['metricaCode'];$webvisor=$settings['webvisor']?'true':'false';
        $code='(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");ym('.$id.',"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true,webvisor:'.$webvisor.'});';
        $script=$dom->createElement('script');$script->appendChild($dom->createTextNode($code));$body->appendChild($script);
        $no=$dom->createElement('noscript');$img=$dom->createElement('img');$img->setAttribute('src','https://mc.yandex.ru/watch/'.$id);$img->setAttribute('alt','');$img->setAttribute('class','metrica-pixel');$no->appendChild($img);$body->appendChild($no);
    }
    return "<!doctype html>\n".$dom->saveHTML($dom->documentElement);
}

function serve_page(string $page): void {
    security_headers(); header('Content-Type: text/html; charset=utf-8');
    echo render_page($page,content(),false,isset($_GET['part'])?text_value($_GET['part'],80):null);
}

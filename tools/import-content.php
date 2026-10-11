<?php
// One-time mechanical migration of the original static website into editable fields.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
if (is_file(__DIR__ . '/../app/content.seed.json')) throw new RuntimeException('Seed already exists; never re-import customer edits.');
$products = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
foreach ($products as &$product) {
    $product += ['published'=>true, 'order'=>count($products), 'price'=>'По запросу', 'availability'=>'Уточняйте наличие', 'photoNote'=>''];
}
unset($product);
$i=0; foreach ($products as &$product) $product['order']=$i++; unset($product);
$content = ['revision'=>'initial-cms-v1','updatedAt'=>gmdate('c'), 'settings'=>[
    'brand'=>'Выездной Сервис Коммунальной Техники','company'=>'ИП Блинов С.Е.',
    'phone'=>'+7 (967) 189-51-77','email'=>'emg-technics@mail.ru', 'address'=>'Московская обл., г. Подольск, ул. Центральная, д. 10, офис 25',
    'recipient'=>'emg-technics@mail.ru', 'sender'=>'noreply@rkt1.ru','mailEnabled'=>false,'indexingEnabled'=>false,
    'metricaCode'=>'','webmasterCode'=>'','webvisor'=>false,
    'leadSuccess'=>'Заявка сохранена. Мы свяжемся с вами по указанным контактам.',
    'consentText'=>'Разрешаю использовать указанные контактные данные для ответа на заявку. Данные сохраняются у владельца сайта и могут передаваться ему по электронной почте.'
], 'pages'=>[], 'products'=>$products];
$schemas=[];
@mkdir(__DIR__ . '/../app/templates', 0755, true);
foreach (CONTENT_PAGES as $page) {
    $source = file_get_contents(SITE_ROOT . '/' . ($page === 'catalog' ? 'katmerciler' : $page) . '.html');
    $dom = new DOMDocument('1.0','UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $source, LIBXML_NONET);
    $dom->encoding='UTF-8';
    foreach (iterator_to_array($dom->childNodes) as $child) if ($child instanceof DOMProcessingInstruction) $dom->removeChild($child);
    $xp = new DOMXPath($dom);
    if ($page === 'catalog') {
        $xp->query('//title')->item(0)->textContent='Каталог всех запчастей';
        $xp->query('//h1')->item(0)->textContent='Каталог всех запчастей';
        $xp->query('//meta[@name="description"]')->item(0)->setAttribute('content','Каталог запчастей для коммунальной техники. Фотографии, описание, характеристики, цена и наличие.');
    }
    if (in_array($page,['hidromak','katmerciler','catalog'],true)) {
        $list = $xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," parts-list ") or contains(concat(" ",normalize-space(@class)," ")," hidro-grid ")]')->item(0);
        while ($list->firstChild) $list->removeChild($list->firstChild);
        $list->setAttribute('class','parts-list');
        $list->setAttribute('data-products', $page === 'hidromak' ? 'HIDRO-MAK' : ($page === 'katmerciler' ? 'KATMERCILER' : 'all'));
    }
    if ($page === 'index') {
        foreach ($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," js-modal ")]') as $link) {
            foreach (['FAUN','CINAR','KADEME','пылесосов'] as $brand) if (str_contains($link->getAttribute('data-title'),$brand)) {
                $link->setAttribute('href','catalog.html?brand=' . rawurlencode($brand === 'пылесосов' ? 'Вакуумные машины' : $brand));
                $link->setAttribute('class',trim(str_replace('js-modal','',$link->getAttribute('class'))));
                foreach (['data-title','data-description','data-image'] as $attr) $link->removeAttribute($attr);
            }
        }
        $grid = $xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," catalog-grid ")]')->item(0);
        $p=$dom->createElement('p'); $p->setAttribute('class','catalog-all-link');
        $link=$dom->createElement('a','Весь каталог запчастей'); $link->setAttribute('href','catalog.html'); $link->setAttribute('class','button');
        $p->appendChild($link); $grid->parentNode->appendChild($p);
    }
    // A stable XPath targets a text node or safe attribute; no customer HTML is executed.
    $fields=[]; $values=[]; $dedupe=[];
    $nodes=iterator_to_array($xp->query('//body//text()[normalize-space()] | //head/title/text() | //head/meta[@name="description"]/@content | //body//img[@src and string-length(@src)>0]/@src | //body//img[@src and string-length(@src)>0]/@alt | //body//a/@href'));
    foreach ($nodes as $node) {
        $parent=$node instanceof DOMAttr ? $node->ownerElement : $node->parentNode;
        $skip=false; $section='Общие элементы';
        for ($ancestor=$parent; $ancestor instanceof DOMElement; $ancestor=$ancestor->parentNode) {
            if (in_array($ancestor->tagName,['script','style','noscript','textarea'],true) || in_array($ancestor->getAttribute('class'),['year','form-note'],true) || $ancestor->getAttribute('id')==='request-form' && $parent->tagName==='button') $skip=true;
            if ($ancestor->tagName==='section') $section=$ancestor->getAttribute('id') ?: 'Главный баннер';
            if ($ancestor->tagName==='header') $section='Меню';
            if ($ancestor->tagName==='footer') $section='Подвал';
        }
        if ($skip) continue;
        $value=trim($node->nodeValue);
        if ($parent->getAttribute('class')==='brand' || in_array($value,[$content['settings']['phone'],$content['settings']['email'],$content['settings']['company'],$content['settings']['address'],'Адрес: '.$content['settings']['address']],true)) continue;
        if ($node instanceof DOMAttr && (str_starts_with($value,'tel:') || str_starts_with($value,'mailto:'))) continue;
        if ($value==='') continue;
        $type=$node instanceof DOMAttr ? ($node->name==='src'?'image':($node->name==='href'?'link':'text')) : 'text';
        $signature=$section . ':' . $type . ':' . $value;
        $key=$dedupe[$signature] ?? 'f' . (count($fields)+1);
        $dedupe[$signature]=$key;
        $fields[$key] ??= ['label'=>($node instanceof DOMAttr ? ($node->name==='src'?'Изображение':($node->name==='alt'?'Описание изображения':($node->name==='href'?'Ссылка':'Описание страницы'))) : $parent->tagName) . ': ' . mb_strimwidth($value,0,95,'…'), 'section'=>$section, 'type'=>$type, 'bindings'=>[]];
        $fields[$key]['bindings'][]=['path'=>$node->getNodePath(),'attribute'=>$node instanceof DOMAttr ? $node->name : null,'prefix'=>$node instanceof DOMText && preg_match('/^\s/',$node->nodeValue)?' ':'','suffix'=>$node instanceof DOMText && preg_match('/\s$/',$node->nodeValue)?' ':''];
        $values[$key]=$value;
    }
    $content['pages'][$page]=$values; $schemas[$page]=$fields;
    file_put_contents(__DIR__ . '/../app/templates/' . $page . '.html',"<!doctype html>\n".$dom->saveHTML($dom->documentElement));
}
foreach (['content.seed.json'=>$content,'fields.schema.json'=>$schemas] as $name=>$value) file_put_contents(__DIR__ . '/../app/' . $name,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'Imported '.count($products).' products and '.array_sum(array_map('count',$schemas))." editable fields.\n";

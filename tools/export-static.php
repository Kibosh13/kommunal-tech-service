<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/render.php';
$destination=$argv[1]??SITE_ROOT;
if (!is_dir($destination)) mkdir($destination,0755,true);
$data=isset($argv[2])?json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR):seed_content();
foreach (CONTENT_PAGES as $page) file_put_contents($destination.'/'.$page.'.html',render_page($page,$data,true));
file_put_contents($destination.'/products.js','// Public static snapshot; edit the production catalogue in /admin/.'."\nwindow.partsCatalog = ".json_encode((object)published_products($data),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).";\n");
if (realpath($destination)!==realpath(SITE_ROOT)) {
    foreach (['style.css','script.js','product.js','catalog-view.js','robots.txt','.nojekyll'] as $file) copy(SITE_ROOT.'/'.$file,$destination.'/'.$file);
    mkdir($destination.'/assets',0755,true);
    foreach (glob(SITE_ROOT.'/assets/*') as $file) if(is_file($file))copy($file,$destination.'/assets/'.basename($file));
}
echo "Static demo exported: $destination\n";

<?php
// Local development only: reproduce Apache routes without exposing private app files.
if (PHP_SAPI!=='cli-server') exit;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (preg_match('~^/(app|tools|tests|vendor)(/|$)|^/\.~',$path)) { http_response_code(403);exit('Forbidden'); }
if ($path==='/') { require __DIR__.'/../index.php';return true; }
if ($path==='/admin/'||$path==='/admin') { require __DIR__.'/../admin/index.php';return true; }
if ($path==='/robots.txt') { require __DIR__.'/../robots.php';return true; }
if (preg_match('~^/(index|hidromak|katmerciler|catalog|product)\.html$~',$path,$match)) { require __DIR__.'/../'.$match[1].'.php';return true; }
return false;

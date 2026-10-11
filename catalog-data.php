<?php
require __DIR__.'/app/render.php';
security_headers();header('Content-Type: application/javascript; charset=utf-8');
echo 'window.partsCatalog = '.json_encode((object)published_products(content()),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).';';

<?php
require __DIR__.'/app/bootstrap.php';
security_headers();header('Content-Type: text/plain; charset=utf-8');
echo content()['settings']['indexingEnabled']?"User-agent: *\nDisallow: /admin/\nDisallow: /api/\nDisallow: /app/\nDisallow: /tools/\n":"User-agent: *\nDisallow: /\n";

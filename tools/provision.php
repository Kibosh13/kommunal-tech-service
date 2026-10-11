<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/bootstrap.php';
if (is_file(storage_dir().'/auth.json')) throw new RuntimeException('Already provisioned; will not overwrite existing access.');
$token=bin2hex(random_bytes(24));
atomic_json('auth.json',['username'=>'','passwordHash'=>'','setupHash'=>hash('sha256',$token),'setupExpires'=>time()+7*86400,'version'=>bin2hex(random_bytes(16)),'rateKey'=>bin2hex(random_bytes(32))]);
echo $token."\n";

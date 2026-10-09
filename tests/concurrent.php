<?php
require dirname(__DIR__).'/app/bootstrap.php';
try{$id=App\services\Checkout::create((int)$argv[1],[2=>'pickup'],0,bin2hex(random_bytes(32)));echo 'OK:'.$id;}
catch(RuntimeException $e){echo 'REJECTED:'.$e->getMessage();exit(2);}

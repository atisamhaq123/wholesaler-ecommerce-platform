<?php
require dirname(__DIR__).'/app/bootstrap.php';
App\services\Checkout::expire();
echo "Expired reservations released.\n";

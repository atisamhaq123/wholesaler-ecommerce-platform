<?php
require dirname(__DIR__).'/app/bootstrap.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
try {
    $controller=new App\controllers\Marketplace;
    if($path==='/webhooks/stripe' && $_SERVER['REQUEST_METHOD']==='POST'){
        App\services\Payment::webhook(file_get_contents('php://input'),$_SERVER['HTTP_STRIPE_SIGNATURE']??'');header('Content-Type: application/json');echo '{"received":true}';
    } elseif($_SERVER['REQUEST_METHOD']==='POST')$controller->post($path);
    elseif($_SERVER['REQUEST_METHOD']==='GET')$controller->get($path);
    else {http_response_code(405);header('Allow: GET, POST');echo 'Method not allowed.';}
} catch(Throwable $error) {
    $code=$error instanceof RuntimeException?(int)$error->getCode():500;
    if($code<400||$code>599)$code=$error instanceof RuntimeException?422:500;
    http_response_code($code);
    if(!is_dir(ROOT.'/storage/logs'))mkdir(ROOT.'/storage/logs',0700,true);
    // Log class and route only, never SQL, tokens, credentials, addresses or message bodies.
    error_log(gmdate('c').' '.get_class($error).' '.$code.' '.$path."\n",3,ROOT.'/storage/logs/application.log');
    $message=$error instanceof RuntimeException?$error->getMessage():'Something went wrong. Please try again or contact the administrator.';
    if($path==='/webhooks/stripe' || $path==='/messages/poll'){header('Content-Type: application/json');echo json_encode(['error'=>$message]);}
    else {try{view('error',['title'=>'Unable to complete this request','message'=>$message,'code'=>$code]);}catch(Throwable){http_response_code(503);echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>My Store unavailable</title><h1>My Store is temporarily unavailable</h1><p>Please check the database configuration or try again shortly.</p></html>';}}
}

<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
$GLOBALS['app_config']=require ROOT.'/config/app.php';
spl_autoload_register(function ($name) {
    if (str_starts_with($name, 'App\\')) {
        $path = ROOT . '/app/' . str_replace('\\', '/', substr($name, 4)) . '.php';
        if (is_file($path)) require $path;
    }
});
if (is_file(ROOT . '/.env')) {
    foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}
date_default_timezone_set('UTC');
function env(string $key, string $default = ''): string { return getenv($key) === false ? $default : getenv($key); }
function config(string $key): mixed { return $GLOBALS['app_config'][$key]??null; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(int|string|null $cents): string { return App\Models\DB::setting('currency', 'USD') . ' ' . number_format((int)$cents / 100, 2); }
function url(string $path = '/'): string { return rtrim(env('APP_URL', 'http://localhost:8080'), '/') . $path; }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = [$message, $type]; }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function view(string $name, array $data = []): void { $user=App\middleware\Auth::user();$role=$user['role']??'guest';$unread=$user?(int)App\Models\DB::one('SELECT COUNT(*) n FROM messages m JOIN conversation_participants cp ON cp.conversation_id=m.conversation_id WHERE cp.user_id=? AND m.sender_id<>? AND (cp.last_read_at IS NULL OR m.created_at>cp.last_read_at)',[$user['id'],$user['id']])['n']:0;extract($data); ob_start(); require ROOT . '/app/views/' . $name . '.php'; $content = ob_get_clean(); require ROOT . '/app/views/layout.php'; }
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors','0');
    $production = env('APP_ENV') === 'production';
    if ($production && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) { http_response_code(400); exit('HTTPS is required.'); }
    session_set_cookie_params(['httponly' => true, 'secure' => $production, 'samesite' => 'Lax', 'path' => '/']);
    if(!is_dir(ROOT.'/storage/sessions')) mkdir(ROOT.'/storage/sessions',0700,true);
    session_save_path(ROOT.'/storage/sessions');
    ini_set('session.use_strict_mode','1');
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

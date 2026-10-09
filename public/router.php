<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
// Explicit static allowlist: never execute an arbitrary file based on its presence.
if(preg_match('~^/(assets/[a-zA-Z0-9_./-]+\.(css|js|svg|png|webp|woff2)|manifest.webmanifest|sw.js)$~',$path) && !str_contains($path,'..') && is_file(__DIR__.$path)) return false;
require __DIR__.'/index.php';

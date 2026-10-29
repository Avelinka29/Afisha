<?php
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/favicon.svg') {
    header('Content-Type: image/svg+xml');
    readfile(__DIR__ . '/public/favicon.svg');
    return;
}
if (preg_match('~^/src/[a-zA-Z0-9_/-]+\.(js|css|ttf)$~D', $path, $match) && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
    header('Content-Type: ' . ['js'=>'text/javascript; charset=utf-8','css'=>'text/css; charset=utf-8','ttf'=>'font/ttf'][$match[1]]);
    readfile(__DIR__ . $path);
    return;
}
if (str_contains($path, '.') && !str_starts_with($path, '/events/')) {
    http_response_code(404);
    return;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');

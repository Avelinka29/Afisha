<?php
// Только изображения доступны как файлы; .env, SQL и журналы находятся вне public.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';
if (preg_match('~^/uploads/[a-zA-Z0-9_-]+\.(?:png|jpg|webp|svg)$~D',$path)) {
    $file=__DIR__.'/public'.$path;
    if (is_file($file)) {
        $mime=['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp','svg'=>'image/svg+xml'][pathinfo($file,PATHINFO_EXTENSION)];
        header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff'); readfile($file); return;
    }
}
require __DIR__.'/public/index.php';

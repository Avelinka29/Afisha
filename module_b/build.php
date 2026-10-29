<?php
declare(strict_types=1);
// JavaScript работает без сборщика. Скрипт просто готовит отдельную копию для сдачи.
$destination=__DIR__.'/dist';
if (!is_dir($destination)) mkdir($destination,0755,true);
foreach (['index.html','router.php'] as $name) copy(__DIR__.'/'.$name,$destination.'/'.$name);
foreach (['src','public'] as $directory) {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/'.$directory,FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile()) continue;
        $relative=substr($file->getPathname(),strlen(__DIR__)+1);
        $target=$destination.'/'.$relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target),0755,true);
        copy($file->getPathname(),$target);
    }
}
echo "Готовая версия клиента: module_b/dist\n";

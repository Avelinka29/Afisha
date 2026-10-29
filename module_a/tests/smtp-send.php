<?php
require __DIR__.'/../src/bootstrap.php';
try {
    $transport=sendMail('recipient@example.com','Проверка SMTP','Письмо с подтверждением: https://example.com/verify?token=test');
    echo $transport.PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR,"Ошибка SMTP\n");exit(1); }

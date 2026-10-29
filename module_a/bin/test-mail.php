<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/http.php';
require __DIR__.'/../src/mailer.php';
$recipient=$argv[1] ?? (getenv('MAIL_FROM_ADDRESS') ?: '');
if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) exit("Укажите корректный email получателя.\n");
if (mailTransport()==='log') exit("Включите настоящую отправку через Gmail API или SMTP.\n");
try {
    $transport=sendMail($recipient,'Проверка почты — На связи','Это проверка отправки писем для афиши МРК «На связи». SMTP-подключение работает. Письма подтверждения регистрации и восстановления пароля отправляются этим же способом.');
    if ($transport==='log') throw new RuntimeException('Письмо не было отправлено.');
    echo "Почтовый сервис принял тестовое письмо. Проверьте входящие и папку «Спам».\n";
} catch (Throwable $error) {fwrite(STDERR,"Письмо не отправлено: ".$error->getMessage().PHP_EOL);exit(1);}

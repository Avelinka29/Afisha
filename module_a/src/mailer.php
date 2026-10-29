<?php
declare(strict_types=1);

function mailTransport(): string {
    $mode=getenv('MAIL_TRANSPORT') ?: 'auto';
    if ($mode==='auto') return is_file(storage().'/gmail-connection.json')?'gmail_api':'smtp';
    if (!in_array($mode,['smtp','gmail_api','log'],true)) throw new RuntimeException('MAIL_TRANSPORT должен быть auto, smtp, gmail_api или log');
    return $mode;
}

function smtpRead($connection, array $expected): void {
    $code=0;
    for ($lineNumber=0;$lineNumber<100;$lineNumber++) {
        $line=fgets($connection,4096);
        if ($line===false) throw new RuntimeException('Почтовый сервер не ответил вовремя');
        $code=(int)substr($line,0,3);
        if (isset($line[3]) && $line[3]===' ') break;
    }
    if (!in_array($code,$expected,true)) throw new RuntimeException('SMTP отклонил запрос, код '.$code);
}

function smtpWrite($connection, string $data): void {
    $length=strlen($data); $offset=0;
    while ($offset<$length) {
        $written=fwrite($connection,substr($data,$offset));
        if ($written===false || $written===0) throw new RuntimeException('Соединение с почтовым сервером прервано');
        $offset+=$written;
    }
}

function smtpCommand($connection, string $command, array $expected): void {
    smtpWrite($connection,$command."\r\n");
    smtpRead($connection,$expected);
}

function sendSmtpMail(string $recipient, string $subject, string $text): void {
    $host=getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $port=(int)(getenv('SMTP_PORT') ?: 587);
    $encryption=getenv('SMTP_ENCRYPTION') ?: 'tls';
    $username=getenv('SMTP_USERNAME') ?: '';
    $password=getenv('SMTP_PASSWORD') ?: '';
    if ($host==='smtp.gmail.com') $password=preg_replace('/\s/','',$password);
    $from=getenv('MAIL_FROM_ADDRESS') ?: $username;
    $fromName=getenv('MAIL_FROM_NAME') ?: 'На связи — МРК';
    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || !filter_var($from,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$from.$recipient.$subject.$fromName)) throw new RuntimeException('Некорректные данные письма');
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D',$host) || $port<1 || $port>65535) throw new RuntimeException('Проверьте SMTP_HOST и SMTP_PORT');
    if (!in_array($encryption,['tls','ssl','none'],true)) throw new RuntimeException('SMTP_ENCRYPTION должен быть tls или ssl');
    // Незашифрованное соединение разрешено только для локального тестового SMTP.
    if ($encryption==='none' && !in_array($host,['localhost','127.0.0.1'],true)) throw new RuntimeException('Для внешнего SMTP требуется TLS');
    if ($encryption!=='none' && ($username==='' || $password==='')) throw new RuntimeException('Не заданы учётные данные SMTP');
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
    $connection=stream_socket_client(($encryption==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errorCode,$errorMessage,8,STREAM_CLIENT_CONNECT,$context);
    if (!$connection) throw new RuntimeException('Не удалось подключиться к SMTP');
    stream_set_timeout($connection,20);
    try {
        smtpRead($connection,[220]);
        smtpCommand($connection,'EHLO mrk.local',[250]);
        if ($encryption==='tls') {
            smtpCommand($connection,'STARTTLS',[220]);
            if (stream_socket_enable_crypto($connection,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true) throw new RuntimeException('Не удалось установить защищённое соединение');
            smtpCommand($connection,'EHLO mrk.local',[250]);
        }
        if ($username!=='' && $password!=='') {
            smtpCommand($connection,'AUTH LOGIN',[334]);
            smtpCommand($connection,base64_encode($username),[334]);
            smtpCommand($connection,base64_encode($password),[235]);
        }
        smtpCommand($connection,'MAIL FROM:<'.$from.'>',[250]);
        smtpCommand($connection,'RCPT TO:<'.$recipient.'>',[250,251]);
        smtpCommand($connection,'DATA',[354]);
        $headers=[
            'Date: '.gmdate('D, d M Y H:i:s').' +0000',
            'Message-ID: <'.bin2hex(random_bytes(16)).'@mrk.local>',
            'From: '.mb_encode_mimeheader($fromName,'UTF-8','B',"\r\n",6).' <'.$from.'>',
            'To: <'.$recipient.'>',
            'Subject: '.mb_encode_mimeheader($subject,'UTF-8','B',"\r\n",9),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $message=implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($text),76,"\r\n");
        smtpWrite($connection,$message.".\r\n");
        smtpRead($connection,[250]);
        // После принятия DATA письмо уже отправлено; ошибка закрытия не отменяет успех.
        @fwrite($connection,"QUIT\r\n");
    } finally { fclose($connection); }
}

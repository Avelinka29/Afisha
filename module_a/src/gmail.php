<?php
declare(strict_types=1);

function gmailConnectionFile(): string {return storage().'/gmail-connection.json';}
function gmailConnection(): ?array {
    $file=gmailConnectionFile();
    if (!is_file($file)) return null;
    $data=json_decode(file_get_contents($file),true);
    return is_array($data) && !empty($data['refresh_token'])?$data:null;
}
function saveGmailConnection(array $data): void {
    $file=gmailConnectionFile();
    $temporary=$file.'.tmp-'.bin2hex(random_bytes(6));
    file_put_contents($temporary,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
    chmod($temporary,0600);rename($temporary,$file);
}
function gmailStatus(): array {
    $data=gmailConnection();
    return ['connected'=>(bool)$data,'sender'=>$data['email'] ?? (getenv('SMTP_USERNAME') ?: ''),
        'delivery_tested_at'=>$data['delivery_tested_at'] ?? null,'transport'=>mailTransport(),'smtp_host'=>getenv('SMTP_HOST') ?: 'smtp.gmail.com'];
}
function gmailRequest(string $url, array $body, ?string $accessToken=null): array {
    $curl=curl_init($url);
    $headers=['Accept: application/json'];
    if ($accessToken!==null) {
        $headers[]='Authorization: Bearer '.$accessToken;
        $headers[]='Content-Type: application/json';
        $encoded=json_encode($body,JSON_THROW_ON_ERROR);
    } else {
        $headers[]='Content-Type: application/x-www-form-urlencoded';
        $encoded=http_build_query($body);
    }
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);unset($curl);
    if ($raw===false) fail(503,'MAIL_UNAVAILABLE','Нет связи с сервисом отправки Gmail');
    $response=json_decode($raw,true);
    if ($status!==200 || !is_array($response)) {
        file_put_contents(storage().'/gmail-error.json',json_encode(['status'=>$status,'error'=>$response['error'] ?? 'invalid_response','time'=>gmdate('c')],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX);
        if ($status===403) fail(503,'GMAIL_PERMISSION_REQUIRED','Разрешите приложению отправку писем и включите Gmail API в Google Cloud.');
        if ($status===400 || $status===401) fail(503,'GMAIL_RECONNECT_REQUIRED','Нужно повторно подключить отправку Gmail в панели администратора.');
        fail(503,'MAIL_UNAVAILABLE','Gmail не принял письмо. Повторите позже.');
    }
    return $response;
}
function gmailMime(string $recipient, string $subject, string $text, string $sender): string {
    $name=getenv('MAIL_FROM_NAME') ?: 'На связи — МРК';
    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || !filter_var($sender,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$recipient.$sender.$subject.$name)) throw new RuntimeException('Некорректные данные письма');
    $headers=['Date: '.gmdate('D, d M Y H:i:s').' +0000','Message-ID: <'.bin2hex(random_bytes(16)).'@mrk.local>',
        'From: '.mb_encode_mimeheader($name,'UTF-8','B',"\r\n",6).' <'.$sender.'>','To: <'.$recipient.'>',
        'Subject: '.mb_encode_mimeheader($subject,'UTF-8','B',"\r\n",9),'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];
    return implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($text),76,"\r\n");
}
function sendGmailMail(string $recipient, string $subject, string $text): void {
    $connection=gmailConnection();
    if (!$connection) fail(503,'GMAIL_NOT_CONNECTED','Администратору нужно подключить отправку Gmail.');
    if (($connection['client_id'] ?? '')!==getenv('GOOGLE_CLIENT_ID')) fail(503,'GMAIL_RECONNECT_REQUIRED','OAuth-приложение изменилось. Подключите Gmail заново.');
    $tokens=gmailRequest('https://oauth2.googleapis.com/token',['client_id'=>getenv('GOOGLE_CLIENT_ID'),'client_secret'=>getenv('GOOGLE_CLIENT_SECRET'),
        'refresh_token'=>$connection['refresh_token'],'grant_type'=>'refresh_token']);
    if (!is_string($tokens['access_token'] ?? null)) fail(503,'GMAIL_RECONNECT_REQUIRED','Google не выдал доступ к отправке писем');
    $message=gmailMime($recipient,$subject,$text,$connection['email']);
    $result=gmailRequest('https://gmail.googleapis.com/gmail/v1/users/me/messages/send',['raw'=>rtrim(strtr(base64_encode($message),'+/','-_'),'=')],$tokens['access_token']);
    if (empty($result['id'])) fail(503,'MAIL_UNAVAILABLE','Gmail не подтвердил приём письма');
}
function testGmailDelivery(): void {
    $connection=gmailConnection();
    if (!$connection) fail(503,'GMAIL_NOT_CONNECTED','Сначала подключите Gmail');
    sendGmailMail($connection['email'],'Проверка отправки — На связи','Это настоящее тестовое письмо афиши МРК. Подключение Gmail API работает. Подтверждение регистрации и восстановление пароля отправляются этим же способом.');
    $connection['delivery_tested_at']=gmdate('c');saveGmailConnection($connection);
}
function gmailAdminRoutes(string $method, string $path): void {
    if (!str_starts_with($path,'/api/admin/mail')) return;
    $admin=requireAdmin();
    if ($method==='GET' && $path==='/api/admin/mail') json(['data'=>gmailStatus()]);
    if ($method==='GET' && $path==='/api/admin/mail/google/url') {
        if (!googleConfigured()) fail(503,'GOOGLE_NOT_CONFIGURED','Сначала настройте OAuth Client ID и Client Secret');
        $flow=['state'=>bin2hex(random_bytes(32)),'nonce'=>bin2hex(random_bytes(32)),'verifier'=>bin2hex(random_bytes(32)),
            'mode'=>'mail','user_id'=>$admin['id'],'next'=>'/admin?tab=mail','expires_at'=>time()+600];
        $_SESSION['google_flow']=$flow;
        $params=['client_id'=>getenv('GOOGLE_CLIENT_ID'),'redirect_uri'=>googleRedirectUri(),'response_type'=>'code',
            'scope'=>'openid email https://www.googleapis.com/auth/gmail.send','state'=>$flow['state'],'nonce'=>$flow['nonce'],
            'access_type'=>'offline','prompt'=>'consent select_account','login_hint'=>getenv('SMTP_USERNAME') ?: '',
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$flow['verifier'],true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
        json(['data'=>['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params)]]);
    }
    if ($method==='POST' && $path==='/api/admin/mail/test') {testGmailDelivery();json(['message'=>'Gmail принял настоящее письмо. Проверьте входящие и папку «Спам».','data'=>gmailStatus()]);}
}

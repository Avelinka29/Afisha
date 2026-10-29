<?php
declare(strict_types=1);

function googleConfigured(): bool {
    return (bool)(getenv('GOOGLE_CLIENT_ID') && getenv('GOOGLE_CLIENT_SECRET'));
}
function googleRedirectUri(): string {
    return getenv('GOOGLE_REDIRECT_URI') ?: (getenv('APP_URL').'/api/auth/google/callback');
}
function googleRequest(string $url, ?array $form=null): array {
    $curl=curl_init($url);
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']];
    if ($form!==null) {
        $options[CURLOPT_POST]=true;
        $options[CURLOPT_POSTFIELDS]=http_build_query($form);
        $options[CURLOPT_HTTPHEADER][]='Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($curl,$options);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);unset($curl);
    if ($raw===false || $status!==200) fail(502,'GOOGLE_UNAVAILABLE','Google не ответил или отклонил запрос. Попробуйте войти ещё раз.');
    try {$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);} catch (JsonException) {fail(502,'GOOGLE_UNAVAILABLE','Некорректный ответ Google');}
    if (!is_array($data)) fail(502,'GOOGLE_UNAVAILABLE','Некорректный ответ Google');
    return $data;
}
function googleCertificates(bool $refresh=false): array {
    $file=storage().'/google-certificates.json';
    if (!$refresh && is_file($file)) {
        $cache=json_decode(file_get_contents($file),true);
        if (is_array($cache) && ($cache['expires_at'] ?? 0)>time()) return $cache['certificates'];
    }
    $certificates=googleRequest('https://www.googleapis.com/oauth2/v1/certs');
    file_put_contents($file,json_encode(['expires_at'=>time()+300,'certificates'=>$certificates]),LOCK_EX);
    return $certificates;
}
function googleBase64(string $value): string {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$value)) fail(401,'GOOGLE_INVALID_TOKEN','Некорректный токен Google');
    $decoded=base64_decode(strtr($value,'-_','+/'),true);
    if ($decoded===false) fail(401,'GOOGLE_INVALID_TOKEN','Некорректный токен Google');
    return $decoded;
}
function googleClaims(string $token, string $nonce, ?array $certificates=null): array {
    $parts=explode('.',$token);
    if (count($parts)!==3) fail(401,'GOOGLE_INVALID_TOKEN','Некорректный токен Google');
    try {
        $header=json_decode(googleBase64($parts[0]),true,32,JSON_THROW_ON_ERROR);
        $claims=json_decode(googleBase64($parts[1]),true,32,JSON_THROW_ON_ERROR);
    } catch (JsonException) {fail(401,'GOOGLE_INVALID_TOKEN','Некорректный токен Google');}
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '')!=='RS256' || !is_string($header['kid'] ?? null)) fail(401,'GOOGLE_INVALID_TOKEN','Некорректная подпись Google');
    $keys=$certificates ?? googleCertificates();
    if (!isset($keys[$header['kid']]) && $certificates===null) $keys=googleCertificates(true);
    $key=$keys[$header['kid']] ?? null;
    if (!is_string($key) || openssl_verify($parts[0].'.'.$parts[1],googleBase64($parts[2]),$key,OPENSSL_ALGO_SHA256)!==1) fail(401,'GOOGLE_INVALID_TOKEN','Подпись Google не прошла проверку');
    $audiences=is_array($claims['aud'] ?? null)?$claims['aud']:[$claims['aud'] ?? null];
    if (!in_array(getenv('GOOGLE_CLIENT_ID'),$audiences,true) || (isset($claims['azp']) && $claims['azp']!==getenv('GOOGLE_CLIENT_ID'))) fail(401,'GOOGLE_INVALID_TOKEN','Токен выдан другому приложению');
    if (!in_array($claims['iss'] ?? '',['https://accounts.google.com','accounts.google.com'],true) || !is_numeric($claims['exp'] ?? null) || (int)$claims['exp']<=time() || (int)($claims['iat'] ?? 0)>time()+60) fail(401,'GOOGLE_INVALID_TOKEN','Токен Google устарел или имеет неверного издателя');
    if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce,$claims['nonce'])) fail(401,'GOOGLE_INVALID_TOKEN','Попытка входа устарела');
    if (!is_string($claims['sub'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,255}$/D',$claims['sub'])) fail(401,'GOOGLE_INVALID_TOKEN','Нет идентификатора Google');
    if (!filter_var($claims['email'] ?? '',FILTER_VALIDATE_EMAIL) || !in_array($claims['email_verified'] ?? false,[true,'true'],true)) fail(403,'NOT_VERIFIED','Google не подтвердил этот email');
    return $claims;
}
function googleReturn(string $path): never {
    header('Location: '.rtrim(getenv('CLIENT_URL') ?: 'http://localhost:5173','/').$path);
    exit;
}
function googleRoutes(string $method, string $path): void {
    if ($method==='GET' && $path==='/api/auth/google/config') json(['data'=>['enabled'=>googleConfigured()]]);
    if ($method==='GET' && $path==='/api/auth/google/url') {
        if (!googleConfigured()) fail(503,'GOOGLE_NOT_CONFIGURED','Вход через Google ещё не настроен');
        $mode=$_GET['mode'] ?? 'login';
        if (!in_array($mode,['login','link'],true)) fail(422,'VALIDATION_ERROR','Некорректное действие Google');
        $next=$_GET['next'] ?? '/';
        if (!is_string($next) || !preg_match('~^/(?!/)[a-zA-Z0-9_/?=&%#.-]*$~D',$next)) $next='/';
        $user=$mode==='link'?requireUser():null;
        $flow=['state'=>bin2hex(random_bytes(32)),'nonce'=>bin2hex(random_bytes(32)),'verifier'=>bin2hex(random_bytes(32)),
            'expires_at'=>time()+600,'mode'=>$mode,'user_id'=>$user['id'] ?? null,'next'=>$next];
        $_SESSION['google_flow']=$flow;
        unset($_SESSION['google_pending']);
        $params=['client_id'=>getenv('GOOGLE_CLIENT_ID'),'redirect_uri'=>googleRedirectUri(),'response_type'=>'code',
            'scope'=>'openid email profile','state'=>$flow['state'],'nonce'=>$flow['nonce'],'prompt'=>'select_account',
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$flow['verifier'],true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
        json(['data'=>['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params)]]);
    }
    if ($method==='GET' && $path==='/api/auth/google/callback') {
        $flow=$_SESSION['google_flow'] ?? null;unset($_SESSION['google_flow']);
        try {
            $state=$_GET['state'] ?? null;
            if (!$flow || $flow['expires_at']<time() || !is_string($state) || !hash_equals($flow['state'],$state)) fail(401,'GOOGLE_INVALID_STATE','Попытка входа устарела');
            if (isset($_GET['error'])) fail(401,'GOOGLE_CANCELLED','Вход через Google отменён');
            $code=text($_GET,'code',4096);
            $tokens=googleRequest('https://oauth2.googleapis.com/token',['client_id'=>getenv('GOOGLE_CLIENT_ID'),'client_secret'=>getenv('GOOGLE_CLIENT_SECRET'),
                'code'=>$code,'code_verifier'=>$flow['verifier'],'grant_type'=>'authorization_code','redirect_uri'=>googleRedirectUri()]);
            if (!is_string($tokens['id_token'] ?? null)) fail(401,'GOOGLE_INVALID_TOKEN','Google не вернул токен входа');
            $claims=googleClaims($tokens['id_token'],$flow['nonce']);
            if ($flow['mode']==='mail') {
                $admin=requireAdmin();
                if ((int)$admin['id']!==(int)$flow['user_id']) fail(403,'GOOGLE_INVALID_STATE','Аккаунт администратора изменился');
                if (mb_strtolower($claims['email'])!==mb_strtolower(getenv('SMTP_USERNAME') ?: '')) fail(403,'GMAIL_WRONG_ACCOUNT','Выберите почту отправителя, указанную в настройках сайта');
                $scopes=explode(' ',(string)($tokens['scope'] ?? ''));
                if (!in_array('https://www.googleapis.com/auth/gmail.send',$scopes,true)) fail(403,'GMAIL_PERMISSION_REQUIRED','Не выдано разрешение отправлять письма');
                if (empty($tokens['refresh_token'])) fail(403,'GMAIL_RECONNECT_REQUIRED','Повторите подключение с разрешением постоянного доступа');
                saveGmailConnection(['email'=>mb_strtolower($claims['email']),'refresh_token'=>$tokens['refresh_token'],'client_id'=>getenv('GOOGLE_CLIENT_ID'),'connected_at'=>gmdate('c'),'delivery_tested_at'=>null]);
                testGmailDelivery();
                googleReturn('/admin?tab=mail&mail_connected=true');
            }
            if ($flow['mode']==='link') {
                $user=requireUser();
                if ((int)$user['id']!==(int)$flow['user_id']) fail(403,'GOOGLE_INVALID_STATE','Аккаунт изменился во время привязки');
                transaction(function () use ($claims,$user) {
                    $identity=one('SELECT user_id FROM google_accounts WHERE google_sub=? FOR UPDATE',[$claims['sub']]);
                    if ($identity && (int)$identity['user_id']!==(int)$user['id']) fail(409,'GOOGLE_ACCOUNT_LINKED','Этот Google уже связан с другим аккаунтом');
                    $existing=one('SELECT google_sub FROM google_accounts WHERE user_id=? FOR UPDATE',[$user['id']]);
                    if ($existing && $existing['google_sub']!==$claims['sub']) fail(409,'GOOGLE_ACCOUNT_LINKED','К профилю уже привязан другой Google');
                    if (!$identity) query('INSERT INTO google_accounts (user_id,google_sub) VALUES (?,?)',[$user['id'],$claims['sub']]);
                });
                googleReturn('/me?google_linked=true');
            }
            // Идентификатор sub постоянный. Совпадение email само по себе не даёт доступ к чужому профилю.
            $user=one('SELECT u.* FROM users u JOIN google_accounts g ON g.user_id=u.id WHERE g.google_sub=?',[$claims['sub']]);
            if ($user) {
                if ($user['blocked']) fail(403,'USER_BLOCKED','Аккаунт заблокирован');
                if (!$user['email_verified_at']) fail(403,'NOT_VERIFIED','Подтвердите email профиля');
                loginUser($user);googleReturn($flow['next']);
            }
            $email=mb_strtolower($claims['email']);
            if (one('SELECT id FROM users WHERE email=?',[$email])) fail(409,'GOOGLE_ACCOUNT_EXISTS','Сначала войдите по паролю и привяжите Google в профиле');
            $_SESSION['google_pending']=['sub'=>$claims['sub'],'email'=>$email,'name'=>mb_substr($claims['name'] ?? '',0,120),
                'picture'=>isset($claims['picture']) && str_starts_with($claims['picture'],'https://')?$claims['picture']:null,'expires_at'=>time()+600,'next'=>$flow['next']];
            googleReturn('/google-complete');
        } catch (ApiError $error) {
            if (($flow['mode'] ?? '')==='mail') googleReturn('/admin?tab=mail&google_error='.rawurlencode($error->errorCode));
            googleReturn('/login?google_error='.rawurlencode($error->errorCode));
        } catch (Throwable $error) {
            error_log('Ошибка Google OAuth: '.$error->getMessage());
            googleReturn((($flow['mode'] ?? '')==='mail'?'/admin?tab=mail&':'/login?').'google_error=GOOGLE_UNAVAILABLE');
        }
    }
    if (in_array($method,['GET','POST'],true) && in_array($path,['/api/auth/google/pending','/api/auth/google/complete'],true)) {
        $pending=$_SESSION['google_pending'] ?? null;
        if (!$pending || $pending['expires_at']<time()) fail(401,'GOOGLE_INVALID_STATE','Войдите через Google заново');
        if ($method==='GET' && $path==='/api/auth/google/pending') json(['data'=>array_intersect_key($pending,array_flip(['email','name','picture']))]);
        if ($method!=='POST' || $path!=='/api/auth/google/complete') return;
        $d=body();$name=text($d,'name',120);$faculty=text($d,'faculty',190);$course=integer($d['course'] ?? null,'course',1,4);$password=passwordValue($d);
        $user=transaction(function () use ($pending,$name,$faculty,$course,$password) {
            if (one('SELECT id FROM users WHERE email=?',[$pending['email']])) fail(409,'GOOGLE_ACCOUNT_EXISTS','Email уже зарегистрирован. Войдите по паролю и привяжите Google в профиле.');
            query('INSERT INTO users (name,email,password_hash,faculty,course,avatar_url,email_verified_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',[$name,$pending['email'],password_hash($password,PASSWORD_BCRYPT),$faculty,$course,$pending['picture']]);
            $id=(int)db()->lastInsertId();query('INSERT INTO google_accounts (user_id,google_sub) VALUES (?,?)',[$id,$pending['sub']]);
            return one('SELECT * FROM users WHERE id=?',[$id]);
        });
        unset($_SESSION['google_pending']);loginUser($user);json(['data'=>profile($user),'next'=>$pending['next']],201);
    }
}

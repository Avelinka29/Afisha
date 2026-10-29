<?php
declare(strict_types=1);
function issueToken(int $userId, string $purpose): string {
    // В письмо попадёт сам токен, а в базе будет только его хеш.
    $token = bin2hex(random_bytes(32));
    query('DELETE FROM auth_tokens WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
    query('INSERT INTO auth_tokens (user_id, token_hash, purpose, expires_at) VALUES (?, ?, ?, ?)', [
        $userId, hash('sha256', $token), $purpose, gmdate('Y-m-d H:i:s', time() + ($purpose === 'verify' ? 86400 : 3600)),
    ]);
    return $token;
}
function emailValue(array $data): string {
    $email = mb_strtolower(text($data, 'email', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'VALIDATION_ERROR', 'Ошибка валидации', ['email' => ['Некорректный email']]);
    return $email;
}
function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['auth_version'] = (int)$user['auth_version'];
    $_SESSION['last_activity']=time();
}
function authRoutes(string $method, string $path): void {
    if ($method==='POST' && $path==='/api/auth/refresh') {
        $user=requireUser();loginUser($user);
        json(['data'=>profile($user),'message'=>'Сессия продлена']);
    }
    if ($method==='POST' && $path==='/api/auth/resend-verification') {
        $email=emailValue(body());
        $user=one('SELECT * FROM users WHERE email=?',[$email]);
        if ($user && !$user['email_verified_at'] && !$user['blocked']) {
            try {
                transaction(function () use ($user,$email) {
                    $token=issueToken((int)$user['id'],'verify');
                    sendMail($email,'Подтверждение email',getenv('APP_URL').'/api/auth/verify-email?token='.$token);
                });
            } catch (Throwable $error) { error_log('Не удалось повторить письмо подтверждения'); }
        }
        json(['message'=>'Запрос принят. Для неподтверждённой почты подготовлено новое письмо']);
    }
    if ($method === 'POST' && $path === '/api/auth/register') {
        $d = body();
        validate($d, ['name','email','password','faculty','course']);
        $name = text($d, 'name', 120); $email = emailValue($d); $password = passwordValue($d);
        $faculty = text($d, 'faculty', 190); $course = integer($d['course'], 'course', 1, 4);
        if (one('SELECT id FROM users WHERE email = ?', [$email])) fail(422, 'VALIDATION_ERROR', 'Ошибка валидации', ['email' => ['Email уже зарегистрирован']]);
        $deliveryMode=transaction(function () use ($name, $email, $password, $faculty, $course) {
            query('INSERT INTO users (name,email,password_hash,faculty,course) VALUES (?,?,?,?,?)', [$name,$email,password_hash($password, PASSWORD_BCRYPT),$faculty,$course]);
            $id = (int)db()->lastInsertId();
            $token = issueToken($id, 'verify');
            return sendMail($email, 'Подтверждение email', getenv('APP_URL') . '/api/auth/verify-email?token=' . $token);
        });
        json(['message' => $deliveryMode==='smtp'?'Письмо подтверждения отправлено':'Письмо сохранено в тестовом журнале', 'mail_mode'=>$deliveryMode], 201);
    }
    if (in_array($method, ['GET','POST'], true) && $path === '/api/auth/verify-email') {
        $token = text($method === 'GET' ? $_GET : body(), 'token', 128);
        $user = transaction(function () use ($token) {
            $entry = one("SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = 'verify' AND expires_at > UTC_TIMESTAMP() FOR UPDATE", [hash('sha256',$token)]);
            if (!$entry) fail(422, 'VALIDATION_ERROR', 'Ссылка недействительна или устарела', ['token' => ['Некорректный токен']]);
            $user = one('SELECT * FROM users WHERE id = ? FOR UPDATE', [$entry['user_id']]);
            if ($user['blocked']) fail(403, 'USER_BLOCKED', 'Аккаунт заблокирован');
            query('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
            query('DELETE FROM auth_tokens WHERE id = ?', [$entry['id']]);
            return one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        });
        loginUser($user);
        if ($method === 'GET') { header('Location: ' . (getenv('CLIENT_URL') ?: 'http://localhost:5173') . '/'); exit; }
        json(['data' => profile($user)]);
    }
    if ($method === 'POST' && $path === '/api/auth/login') {
        $d = body(); validate($d, ['email','password']);
        $user = one('SELECT * FROM users WHERE email = ?', [emailValue($d)]);
        $password = text($d, 'password', 200);
        if (!$user || !password_verify($password, $user['password_hash'])) fail(401, 'UNAUTHORIZED', 'Неверный email или пароль');
        if ($user['blocked']) fail(403, 'USER_BLOCKED', 'Аккаунт заблокирован');
        if (!$user['email_verified_at']) fail(403, 'NOT_VERIFIED', 'Подтвердите email');
        loginUser($user);
        json(['data' => profile($user)]);
    }
    if ($method === 'POST' && $path === '/api/auth/logout') {
        requireUser();
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', ['expires' => time()-3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        json(['message' => 'Вы вышли из аккаунта']);
    }
    if ($method === 'POST' && $path === '/api/auth/password/forgot') {
        $email = emailValue(body());
        $user = one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user && !$user['blocked']) {
            try {
                transaction(function () use ($user,$email) {
                    $token = issueToken((int)$user['id'], 'reset');
                    sendMail($email, 'Восстановление пароля', (getenv('CLIENT_URL') ?: 'http://localhost:5173') . '/password-reset?token=' . $token);
                });
            } catch (Throwable $error) { error_log('Не удалось отправить письмо восстановления'); }
        }
        json(['message' => 'Запрос принят. Если аккаунт существует, письмо восстановления подготовлено']);
    }
    if ($method === 'POST' && $path === '/api/auth/password/reset') {
        $d = body(); $token = text($d, 'token', 128); $password = passwordValue($d);
        if (isset($d['password_confirmation']) && $d['password_confirmation'] !== $password) fail(422,'VALIDATION_ERROR','Пароли не совпадают',['password_confirmation'=>['Пароли не совпадают']]);
        transaction(function () use ($token, $password) {
            $entry = one("SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = 'reset' AND expires_at > UTC_TIMESTAMP() FOR UPDATE", [hash('sha256',$token)]);
            if (!$entry) fail(422,'VALIDATION_ERROR','Ссылка недействительна или устарела',['token'=>['Некорректный токен']]);
            $user = one('SELECT * FROM users WHERE id = ? FOR UPDATE', [$entry['user_id']]);
            if ($user['blocked']) fail(403,'USER_BLOCKED','Аккаунт заблокирован');
            query('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT),$user['id']]);
            query("DELETE FROM auth_tokens WHERE user_id = ? AND purpose = 'reset'", [$user['id']]);
        });
        json(['message'=>'Пароль изменён. Войдите заново']);
    }
    if ($method === 'POST' && $path === '/api/me/password') {
        $user = requireUser(); $d = body(); validate($d,['current_password','password','password_confirmation']);
        $password = passwordValue($d);
        if (!password_verify(text($d,'current_password',200), $user['password_hash'])) fail(422,'VALIDATION_ERROR','Неверный текущий пароль',['current_password'=>['Неверный пароль']]);
        if ($d['password_confirmation'] !== $password) fail(422,'VALIDATION_ERROR','Пароли не совпадают',['password_confirmation'=>['Пароли не совпадают']]);
        transaction(function () use ($user, $password) {
            query('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?', [password_hash($password,PASSWORD_BCRYPT),$user['id']]);
            query("DELETE FROM auth_tokens WHERE user_id = ? AND purpose = 'reset'", [$user['id']]);
        });
        loginUser(one('SELECT * FROM users WHERE id = ?',[$user['id']]));
        json(['message'=>'Пароль изменён']);
    }
}

<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value));
}
function db(bool $withDatabase = true): PDO {
    static $connections = [];
    $key = (int)$withDatabase;
    if (!isset($connections[$key])) {
        $dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306');
        if ($withDatabase) $dsn .= ';dbname=' . (getenv('DB_NAME') ?: 'profskills');
        $connections[$key] = new PDO($dsn . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connections[$key]->exec("SET time_zone = '+00:00'");
    }
    return $connections[$key];
}
function query(string $sql, array $params = []): PDOStatement {
    // Значения передаём отдельно от SQL, чтобы введённый текст не стал частью команды.
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function one(string $sql, array $params = []): ?array { return query($sql, $params)->fetch() ?: null; }
function storage(): string {
    $path = __DIR__ . '/../storage';
    if (!is_dir($path)) mkdir($path, 0700, true);
    return $path;
}
function startSession(): void {
    $path = storage() . '/sessions';
    if (!is_dir($path)) mkdir($path, 0700, true);
    session_save_path($path);
    session_name('profskills_session');
    session_start(['use_strict_mode' => true, 'gc_maxlifetime'=>7200, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax',
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'cookie_path' => '/']);
}
function sendMail(string $email, string $subject, string $text): string {
    require_once __DIR__.'/mailer.php';
    require_once __DIR__.'/gmail.php';
    $transport=mailTransport();
    if ($transport==='log') {
        // Только для отдельно запущенных автоматических тестов, не для основного сайта.
        file_put_contents(storage().'/mail.log',json_encode(['to'=>$email,'subject'=>$subject,'text'=>$text,'created_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);
        return 'log';
    }
    try {
        if ($transport==='gmail_api') sendGmailMail($email,$subject,$text);
        else sendSmtpMail($email,$subject,$text);
    } catch (Throwable $error) {
        error_log('Ошибка доставки письма: '.$error->getMessage());
        if (function_exists('fail')) fail(503,'MAIL_UNAVAILABLE','Письмо не отправлено: сервис почты недоступен. Администратору нужно проверить подключение почты.');
        throw $error;
    }
    file_put_contents(storage().'/mail-delivery.log',json_encode(['to'=>$email,'subject'=>$subject,'status'=>'sent','transport'=>$transport,'created_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND|LOCK_EX);
    return $transport;
}
function notify(int $userId, string $type, int $eventId, string $text): void {
    query('INSERT INTO notifications (user_id, type, payload, message) VALUES (?, ?, ?, ?)', [
        $userId, $type, json_encode(['event_id' => $eventId]), $text,
    ]);
}

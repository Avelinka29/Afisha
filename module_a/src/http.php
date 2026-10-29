<?php
declare(strict_types=1);
final class ApiError extends RuntimeException {
    public function __construct(public int $status, public string $errorCode, string $message, public array $fields = []) { parent::__construct($message); }
}
function fail(int $status, string $code, string $message, array $fields = []): never { throw new ApiError($status, $code, $message, $fields); }
function json(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function body(): array {
    static $data;
    if ($data !== null) return $data;
    $raw = file_get_contents('php://input');
    if ($raw === '') return $data = [];
    try { $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { fail(422, 'VALIDATION_ERROR', 'Некорректный JSON', ['body' => ['Ожидается JSON-объект']]); }
    if (!is_array($value) || !str_starts_with(ltrim($raw), '{')) fail(422, 'VALIDATION_ERROR', 'Ожидается JSON-объект');
    return $data = $value;
}
function validate(array $data, array $required): void {
    $errors = [];
    foreach ($required as $field) if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) $errors[$field] = ['Обязательное поле'];
    if ($errors) fail(422, 'VALIDATION_ERROR', 'Заполните обязательные поля', $errors);
}
function text(array $data, string $field, int $max = 1000): string {
    $value = $data[$field] ?? null;
    if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) fail(422, 'VALIDATION_ERROR', 'Ошибка валидации', [$field => ["Введите текст длиной до $max символов"]]);
    return trim($value);
}
function integer(mixed $value, string $field, int $min = 1, int $max = PHP_INT_MAX): int {
    if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) fail(422, 'VALIDATION_ERROR', 'Ошибка валидации', [$field => ["Ожидается целое число от $min до $max"]]);
    return (int)$value;
}
function passwordValue(array $data): string {
    $password = text($data, 'password', 200);
    if (strlen($password) < 8 || strlen($password) > 72) fail(422, 'VALIDATION_ERROR', 'Ошибка валидации', ['password' => ['Пароль: от 8 до 72 байт']]);
    return $password;
}
function pagination(): array { return [integer($_GET['page'] ?? 1, 'page', 1, 1000000), integer($_GET['per_page'] ?? 12, 'per_page', 1, 100)]; }
function paged(string $sql, array $params, string $countSql, ?callable $map = null): array {
    [$page, $perPage] = pagination();
    $total = (int)query($countSql, $params)->fetchColumn();
    $rows = query($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params)->fetchAll();
    if ($map) $rows = array_map($map, $rows);
    return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
}
function currentUser(): ?array {
    // В сессии хранится id, а актуальные права и блокировку берём из базы.
    if (empty($_SESSION['user_id'])) return null;
    if (isset($_SESSION['last_activity']) && time()-(int)$_SESSION['last_activity']>7200) {
        $_SESSION=[];
        return null;
    }
    $user = one('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']]);
    if (!$user || (int)($user['auth_version'] ?? 0) !== (int)($_SESSION['auth_version'] ?? 0)) {
        // После сброса пароля версия меняется, поэтому старая сессия больше не подходит.
        unset($_SESSION['user_id']);
        return null;
    }
    // Активные запросы продлевают сессию; после двух часов бездействия нужен новый вход.
    $_SESSION['last_activity']=time();
    return $user;
}
function requireUser(): array {
    $user = currentUser();
    if (!$user) fail(401, 'UNAUTHORIZED', 'Войдите в аккаунт');
    if ($user['blocked']) fail(403, 'USER_BLOCKED', 'Аккаунт заблокирован');
    if (!$user['email_verified_at']) fail(403, 'NOT_VERIFIED', 'Подтвердите email');
    return $user;
}
function requireAdmin(): array {
    $user = requireUser();
    if ($user['role'] !== 'admin') fail(403, 'FORBIDDEN', 'Требуются права администратора');
    return $user;
}
function verified(int $userId, int $clubId): bool { return (bool)one('SELECT user_id FROM club_users WHERE user_id = ? AND club_id = ? AND verified = 1', [$userId, $clubId]); }
function profile(array $user): array {
    unset($user['password_hash'], $user['auth_version']);
    $clubs = query('SELECT c.*, cu.verified FROM clubs c JOIN club_users cu ON c.id = cu.club_id WHERE cu.user_id = ?', [$user['id']])->fetchAll();
    foreach ($clubs as &$club) $club['verified'] = (bool)$club['verified'];
    $user['clubs'] = $clubs;
    $user['google_connected']=(bool)one('SELECT user_id FROM google_accounts WHERE user_id=?',[$user['id']]);
    if ($user['role'] !== 'admin' && array_filter($clubs, fn($c) => $c['verified'])) $user['role'] = 'representative';
    $user['blocked'] = (bool)$user['blocked'];
    return $user;
}
function transaction(callable $action): mixed {
    // При ошибке откатываем все изменения этого действия, а не только последний запрос.
    db()->beginTransaction();
    try { $result = $action(); db()->commit(); return $result; }
    catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
}

<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/http.php';
require __DIR__.'/../src/auth.php';
require __DIR__.'/../src/google.php';
require __DIR__.'/../src/mailer.php';
require __DIR__.'/../src/gmail.php';
require __DIR__.'/../src/events.php';
require __DIR__.'/../src/account.php';
require __DIR__.'/../src/admin.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
set_exception_handler(function (Throwable $error) {
    if ($error instanceof ApiError) {
        $payload=['code'=>$error->errorCode,'message'=>$error->getMessage()];
        if ($error->fields) $payload['fields']=$error->fields;
        elseif ($error->status===422 && $error->errorCode==='VALIDATION_ERROR') $payload['fields']=['request'=>[$error->getMessage()]];
        json(['error'=>$payload],$error->status);
    }
    if ($error instanceof PDOException && ($error->errorInfo[1] ?? null)===1062) json(['error'=>['code'=>'VALIDATION_ERROR','message'=>'Запись уже существует','fields'=>['record'=>['Значение должно быть уникальным']]]],422);
    error_log((string)$error);
    json(['error'=>['code'=>'INTERNAL_ERROR','message'=>'Ошибка сервера. Подробности в журнале PHP']],500);
});
$method=$_SERVER['REQUEST_METHOD'];
$path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/', '/') ?: '/';
$origin=$_SERVER['HTTP_ORIGIN'] ?? '';
$allowed=[getenv('CLIENT_URL') ?: 'http://localhost:5173',getenv('APP_URL') ?: 'http://localhost:8000'];
if ($origin && in_array($origin,$allowed,true)) {
    header('Access-Control-Allow-Origin: '.$origin); header('Access-Control-Allow-Credentials: true'); header('Vary: Origin');
}
// Cookie-сессии защищаем от запросов с чужих сайтов, включая multipart-загрузки.
if (!in_array($method,['GET','HEAD','OPTIONS'],true)) {
    if ($origin && !in_array($origin,$allowed,true)) fail(403,'FORBIDDEN','Недопустимый источник запроса');
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')==='cross-site') fail(403,'FORBIDDEN','Запрос с другого сайта запрещён');
    if ($path!=='/api/uploads' && !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')) fail(422,'VALIDATION_ERROR','Используйте Content-Type: application/json');
}
if ($method==='OPTIONS') {
    header('Access-Control-Allow-Methods: GET,POST,PATCH,PUT,DELETE,OPTIONS'); header('Access-Control-Allow-Headers: Content-Type'); http_response_code(204); exit;
}
startSession();
$user=currentUser();
if ($user && $user['blocked'] && !($method==='GET' && $path==='/api/me')) fail(403,'USER_BLOCKED','Аккаунт заблокирован');
if (str_starts_with($path,'/api/me') && !$user) fail(401,'UNAUTHORIZED','Войдите в аккаунт');
if ($method==='GET' && $path==='/api/health') { require_once __DIR__.'/../src/mailer.php'; query('SELECT 1'); json(['status'=>'ok','database'=>'connected','mail_transport'=>mailTransport()]); }
if ($method==='GET' && in_array($path,['/api/clubs','/api/categories'],true)) {
    $table=$path==='/api/clubs'?'clubs':'categories'; json(['data'=>query("SELECT * FROM $table ORDER BY id")->fetchAll()]);
}
gmailAdminRoutes($method,$path);
googleRoutes($method,$path);
authRoutes($method,$path);
accountRoutes($method,$path);
eventRoutes($method,$path);
adminRoutes($method,$path);
fail(404,'NOT_FOUND','Маршрут не найден');

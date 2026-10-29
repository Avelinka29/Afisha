<?php
// Создаёт сессию только для проверки завершения уже подтверждённого Google-входа.
require __DIR__.'/../src/bootstrap.php';
session_id($argv[1]);startSession();
$_SESSION['google_pending']=['sub'=>$argv[2],'email'=>$argv[3],'name'=>'Проверка Google','picture'=>null,'expires_at'=>time()+600,'next'=>'/me'];
echo session_id();
session_write_close();

<?php
require __DIR__.'/../src/bootstrap.php';
session_id($argv[1]);startSession();
if(isset($argv[2]))$_SESSION['last_activity']=time()-(int)$argv[2];
echo (int)($_SESSION['last_activity'] ?? 0);
session_write_close();

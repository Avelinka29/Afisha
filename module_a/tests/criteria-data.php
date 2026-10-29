<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$checks=[];
function criterion(string $name,bool $passed,mixed $actual): void {global $checks;$checks[]=['name'=>$name,'passed'=>$passed,'actual'=>$actual];}
$representatives=(int)query('SELECT COUNT(DISTINCT user_id) FROM club_users cu JOIN users u ON u.id=cu.user_id WHERE cu.verified=1 AND u.email IN (?,?,?)',['rep1@example.com','rep2@example.com','rep3@example.com'])->fetchColumn();
criterion('2–3 тестовых представителя разных Комьюнити',$representatives>=2 && $representatives<=3,$representatives);
$student=one('SELECT u.id,(SELECT COUNT(*) FROM club_users cu WHERE cu.user_id=u.id AND verified=1) AS verifications FROM users u WHERE email=?',['student@example.com']);
criterion('Студент без верификации',isset($student['id']) && (int)$student['verifications']===0,$student['verifications'] ?? null);
$blocked=one('SELECT blocked FROM users WHERE email=?',['blocked@example.com']);criterion('Заблокированный пользователь',(int)($blocked['blocked'] ?? 0)===1,$blocked);
$students=(int)query("SELECT COUNT(*) FROM users WHERE email REGEXP '^student[1-8]@example[.]com$' AND blocked=0")->fetchColumn();criterion('5–10 обычных тестовых студентов',$students>=5 && $students<=10,$students);
$states=[];foreach(query('SELECT status,COUNT(*) AS n FROM events GROUP BY status')->fetchAll() as $row)$states[$row['status']]=(int)$row['n'];
criterion('6–8 published',($states['published'] ?? 0)>=6 && $states['published']<=8,$states['published'] ?? 0);
criterion('2–3 draft',($states['draft'] ?? 0)>=2 && $states['draft']<=3,$states['draft'] ?? 0);
criterion('2 cancelled',($states['cancelled'] ?? 0)===2,$states['cancelled'] ?? 0);
$future=(int)query("SELECT COUNT(*) FROM events WHERE status='published' AND starts_at>UTC_TIMESTAMP()")->fetchColumn();
$past=(int)query("SELECT COUNT(*) FROM events WHERE status='published' AND ends_at<=UTC_TIMESTAMP()")->fetchColumn();
criterion('2–3 будущих опубликованных',$future>=2 && $future<=3,$future);criterion('2–3 прошедших опубликованных',$past>=2 && $past<=3,$past);
$reasons=(int)query("SELECT COUNT(*) FROM events WHERE status='cancelled' AND cancellation_reason IS NOT NULL AND LENGTH(TRIM(cancellation_reason))>0")->fetchColumn();criterion('У отменённых указана причина',$reasons===2,$reasons);
$full=(int)query("SELECT COUNT(*) FROM events e WHERE status='published' AND capacity IS NOT NULL AND capacity=(SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='active')")->fetchColumn();criterion('Полностью заполненное событие',$full>=1,$full);
$online=(int)query("SELECT COUNT(*) FROM events WHERE format='online'")->fetchColumn();criterion('Онлайн-событие',$online>=1,$online);
$covers=query('SELECT DISTINCT cover_url FROM events')->fetchAll(PDO::FETCH_COLUMN);$coversValid=(bool)$covers;
foreach($covers as $url)$coversValid=$coversValid&&is_string($url)&&str_starts_with($url,'/uploads/')&&is_file(__DIR__.'/../public'.$url);
criterion('Тестовые изображения доступны',$coversValid,count($covers));
foreach(['users','clubs','categories','events','registrations','reactions','notifications','club_users'] as $table){$count=(int)query("SELECT COUNT(*) FROM $table")->fetchColumn();criterion('Таблица '.$table.' заполнена',$count>0,$count);}
$hashes=query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN);$hashed=true;foreach($hashes as $hash)$hashed=$hashed&&password_get_info($hash)['algoName']!=='unknown';criterion('Пароли пользователей хешированы',$hashed,count($hashes));
foreach(['module_a/schema.sql','module_a/bin/setup.php','module_a/profskills.postman_collection.json','module_b/index.html','module_b/dist/index.html','README.md'] as $file)criterion('Файл '.$file,is_file(__DIR__.'/../../'.$file),is_file(__DIR__.'/../../'.$file));
$result=['checked_at'=>gmdate('c'),'passed'=>count(array_filter($checks,fn($c)=>$c['passed'])),'total'=>count($checks),'checks'=>$checks];
file_put_contents(storage().'/criteria-data-report.json',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
foreach($checks as $c)echo ($c['passed']?'PASS ':'FAIL ').$c['name'].' — '.json_encode($c['actual'],JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($result['passed']===$result['total']?0:1);

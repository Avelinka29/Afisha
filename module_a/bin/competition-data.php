<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/extra-events.php';
$extra=[];
foreach (extraEventDefinitions() as $definition) {
    [$title,$club,,$creator]=$definition;
    $event=one('SELECT * FROM events WHERE title=? AND club_id=? AND created_by=?',[$title,$club,$creator]);
    if ($event) $extra[]=$event;
}
$backup=['created_at'=>gmdate('c'),'events'=>$extra,'registrations'=>[],'reactions'=>[],'reminder_deliveries'=>[],'notifications'=>[],
    'core_dates_before'=>query('SELECT id,title,starts_at,ends_at FROM events WHERE id IN (7,8)')->fetchAll()];
$ids=array_column($extra,'id');
$folder=storage().'/backups';if(!is_dir($folder))mkdir($folder,0700,true);
$archive=$folder.'/extra-events-'.gmdate('Ymd-His').'.json';
db()->beginTransaction();
try {
    if ($ids) {
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        foreach (['registrations','reactions','reminder_deliveries'] as $table) $backup[$table]=query("SELECT * FROM $table WHERE event_id IN ($placeholders)",$ids)->fetchAll();
        $backup['notifications']=query("SELECT * FROM notifications WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.event_id')) IN ($placeholders)",array_map('strval',$ids))->fetchAll();
        if(file_put_contents($archive,json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Не удалось сохранить архив, данные не изменены');
        chmod($archive,0600);
        query("DELETE FROM notifications WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.event_id')) IN ($placeholders)",array_map('strval',$ids));
        query("DELETE FROM events WHERE id IN ($placeholders)",$ids);
    }
    // Обновляем только две демонстрационные записи, чтобы на защите были текущие события.
    foreach ([7=>['Открытая тренировка','-30 minutes'],8=>['День добрых дел','-1 hour']] as $id=>[$title,$offset]) {
        query('UPDATE events SET starts_at=?,ends_at=? WHERE id=? AND title=? AND status=?',[
            gmdate('Y-m-d H:i:s',strtotime($offset)),gmdate('Y-m-d H:i:s',strtotime('+8 hours')),$id,$title,'published']);
    }
    db()->commit();
} catch (Throwable $error) {db()->rollBack();throw $error;}
echo 'Дополнительных мероприятий в архиве: '.count($extra).PHP_EOL;
if ($ids) echo 'Архив для восстановления: '.$archive.PHP_EOL;
echo json_encode(query('SELECT status,COUNT(*) AS total FROM events GROUP BY status')->fetchAll(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

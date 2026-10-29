<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
$count=0;
foreach ([24,1] as $hours) {
    // Ищем события с запасом в 5 минут на случай небольшой задержки планировщика.
    $rows=query("SELECT e.id,e.title,e.starts_at,r.user_id FROM events e JOIN registrations r ON r.event_id=e.id
        JOIN users u ON u.id=r.user_id WHERE e.status='published' AND r.status='active' AND u.blocked=0
        AND e.starts_at>UTC_TIMESTAMP() AND e.starts_at BETWEEN DATE_SUB(DATE_ADD(UTC_TIMESTAMP(),INTERVAL $hours HOUR),INTERVAL 5 MINUTE)
        AND DATE_ADD(UTC_TIMESTAMP(),INTERVAL $hours HOUR)")->fetchAll();
    foreach ($rows as $row) {
        db()->beginTransaction();
        try {
            $event=one('SELECT status,starts_at FROM events WHERE id=? FOR UPDATE',[$row['id']]);
            $registration=one("SELECT id FROM registrations WHERE event_id=? AND user_id=? AND status='active'",[$row['id'],$row['user_id']]);
            if (!$event || $event['status']!=='published' || $event['starts_at']!==$row['starts_at'] || !$registration) {
                db()->commit(); continue;
            }
            // Повторный запуск не создаёт дубликаты; перенос события даёт новое напоминание.
            $insert=query('INSERT IGNORE INTO reminder_deliveries (event_id,user_id,reminder_hours,starts_at) VALUES (?,?,?,?)',[$row['id'],$row['user_id'],$hours,$row['starts_at']]);
            if ($insert->rowCount()) {
                notify((int)$row['user_id'],'event_reminder',(int)$row['id'],'Через '.$hours.' ч: '.$row['title']); $count++;
            }
            db()->commit();
        } catch (Throwable $error) { db()->rollBack(); throw $error; }
    }
}
echo "Создано напоминаний: $count\n";

<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
function addStudentDemo(): void {
    $student=one('SELECT id FROM users WHERE email=?',['student@example.com']);
    if (!$student) return;
    $userId=(int)$student['id'];
    // Неверифицированная привязка позволяет показать, какие права выдаёт администратор.
    query('INSERT INTO club_users (user_id,club_id,verified) VALUES (?,2,0) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)',[$userId]);
    foreach ([2,4] as $eventId) {
        if (!one('SELECT id FROM events WHERE id=?',[$eventId])) continue;
        if (!one('SELECT id FROM registrations WHERE user_id=? AND event_id=?',[$userId,$eventId])) {
            query('INSERT INTO registrations (user_id,event_id) VALUES (?,?)',[$userId,$eventId]);
            if ($eventId===2) notify($userId,'registration_confirmed',$eventId,'Вы записались: Как устроен искусственный интеллект');
        }
    }
    foreach (['like','interested'] as $type) query('INSERT INTO reactions (user_id,event_id,type) VALUES (?,2,?) ON DUPLICATE KEY UPDATE type=VALUES(type)',[$userId,$type]);
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {addStudentDemo();echo "Тестовый студент: регистрации, реакции и статус сообщества готовы.\n";}

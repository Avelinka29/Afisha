<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$stateFile=storage().'/smoke-state.json';
if (!is_file($stateFile)) exit("Нет записей последней проверки.\n");
$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
db()->beginTransaction();
try {
    foreach ($state['events'] ?? [] as $fixture) {
        $event=one('SELECT id,title FROM events WHERE id=? FOR UPDATE',[$fixture['id']]);
        if (!$event) continue;
        if ($event['title']!==$fixture['title']) throw new RuntimeException('Тестовое событие изменено: очистка остановлена');
        query("DELETE FROM notifications WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.event_id'))=?",[(string)$event['id']]);
        query('DELETE FROM events WHERE id=?',[$event['id']]);
    }
    foreach ($state['emails'] ?? [] as $email) {
        if (!preg_match('/^test-[a-f0-9]+@example\.com$/D',$email)) throw new RuntimeException('Недопустимый тестовый email');
        $user=one('SELECT id FROM users WHERE email=?',[$email]); if (!$user) continue;
        foreach (['auth_tokens','notifications','reminder_deliveries','reactions','registrations','club_users'] as $table) query("DELETE FROM $table WHERE user_id=?",[$user['id']]);
        query('DELETE FROM users WHERE id=?',[$user['id']]);
    }
    db()->commit(); unlink($stateFile); echo "Данные последней проверки удалены.\n";
} catch (Throwable $error) { db()->rollBack(); throw $error; }

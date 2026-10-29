<?php
declare(strict_types=1);
echo "Планировщик напоминаний запущен. Для остановки нажмите Ctrl+C.\n";
while (true) {
    try {
        require __DIR__.'/reminders.php';
    } catch (Throwable $error) {
        error_log('Не удалось проверить напоминания: '.$error->getMessage());
    }
    sleep(60);
}

<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$file=$argv[1] ?? '';
if (!is_file($file)) exit("Укажите путь к JSON-архиву из storage/backups.\n");
$data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if (!is_array($data['events'] ?? null)) exit("Это не архив мероприятий.\n");
db()->beginTransaction();
try {
    foreach (['events','registrations','reactions','reminder_deliveries','notifications'] as $table) {
        $allowed=array_column(query("DESCRIBE $table")->fetchAll(),'Field');
        foreach ($data[$table] ?? [] as $row) {
            $row=array_intersect_key($row,array_flip($allowed));
            if($table!=='events')unset($row['id']);
            $columns=array_keys($row);
            query("INSERT INTO $table (".implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
        }
    }
    db()->commit();echo 'Восстановлено мероприятий: '.count($data['events']).PHP_EOL;
} catch (Throwable $error) {db()->rollBack();throw $error;}

<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$name=getenv('DB_NAME') ?: 'profskills';
if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) exit("DB_NAME: используйте латинские буквы, цифры и подчёркивание\n");
db(false)->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
foreach (explode(';',file_get_contents(__DIR__.'/../schema.sql')) as $sql) if (trim($sql)!=='') db()->exec($sql);
if ((int)query('SELECT COUNT(*) FROM users')->fetchColumn()>0) {
    echo 'Таблицы готовы. Существующие мероприятия не изменены.'.PHP_EOL;
    require_once __DIR__.'/student-demo.php';
    addStudentDemo();
    exit;
}
db()->beginTransaction();
try {
    $password=password_hash('ProfSkills2026!',PASSWORD_BCRYPT);
    $users=[
        ['Администратор','admin@example.com','admin',0],
        ['Анна Организатор','rep1@example.com','student',0],
        ['Марк Организатор','rep2@example.com','student',0],
        ['Алина Организатор','rep3@example.com','student',0],
        ['Студент для проверки','student@example.com','student',0],
        ['Заблокированный студент','blocked@example.com','student',1],
    ];
    foreach (['Егор','Полина','Илья','Мария','Даниил','София','Артём','Виктория'] as $i=>$student) $users[]=[$student,'student'.($i+1).'@example.com','student',0];
    foreach ($users as [$student,$email,$role,$blocked]) query('INSERT INTO users (name,email,password_hash,faculty,course,role,blocked,email_verified_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$student,$email,$password,'Информационные технологии',2,$role,$blocked]);
    foreach ([['Спортивный клуб','Тренировки, турниры и активный отдых'],['Научное общество','Исследования и открытые лекции'],['Творческая студия','Музыка, театр и студенческие выставки'],['Волонтёры кампуса','Добрые дела и помощь городу']] as $club) query('INSERT INTO clubs (name,description) VALUES (?,?)',$club);
    foreach (['Спорт','Наука','Культура','Образование','Развлечения','Волонтёрство'] as $category) query('INSERT INTO categories (name) VALUES (?)',[$category]);
    foreach ([[2,1],[3,2],[4,3],[2,4]] as $pair) query('INSERT INTO club_users (user_id,club_id) VALUES (?,?)',$pair);
    $events=[
        ['Кубок кампуса по волейболу',1,1,2,'+2 days','+2 days +3 hours','Спортивный зал, корпус А','offline',30,'published'],
        ['Как устроен искусственный интеллект',2,2,3,'+1 day','+1 day +2 hours','https://meet.example.com/science','online',100,'published'],
        ['Камерный концерт студии',3,3,4,'+3 days','+3 days +2 hours','Актовый зал','offline',2,'published'],
        ['Забег первокурсников',1,1,2,'-2 days','-2 days +2 hours','Стадион университета','offline',50,'published'],
        ['Ночь научных экспериментов',2,2,3,'-5 days','-5 days +4 hours','Лаборатория 204','offline',40,'published'],
        ['Выставка студенческой фотографии',3,3,4,'-1 day','-1 day +5 hours','Галерея кампуса','offline',80,'published'],
        ['Открытая тренировка',1,1,2,'-30 minutes','+8 hours','Спортивный зал','offline',25,'published'],
        ['День добрых дел',4,6,2,'-1 hour','+8 hours','Студенческий центр','offline',60,'published'],
        ['Черновик: шахматный турнир',1,1,2,'+7 days','+7 days +3 hours','Библиотека','offline',32,'draft'],
        ['Черновик: школа исследователя',2,4,3,'+8 days','+8 days +2 hours','Аудитория 301','offline',40,'draft'],
        ['Черновик: театральный вечер',3,3,4,'+9 days','+9 days +2 hours','Актовый зал','offline',100,'draft'],
        ['Кинопоказ во дворе',3,5,4,'+4 days','+4 days +2 hours','Внутренний двор','offline',50,'cancelled'],
        ['Поход выходного дня',1,1,2,'+5 days','+5 days +6 hours','Вход в главный корпус','offline',20,'cancelled'],
    ];
    $covers=__DIR__.'/../public/uploads'; if (!is_dir($covers)) mkdir($covers,0755,true);
    $colors=['#204C3B','#233D61','#794436','#64532A'];
    foreach ($colors as $i=>$color) {
        $label=['SPORT','SCIENCE','CULTURE','COMMUNITY'][$i];
        file_put_contents($covers.'/cover-'.($i+1).'.svg','<svg xmlns="http://www.w3.org/2000/svg" width="960" height="600" viewBox="0 0 960 600"><rect width="960" height="600" fill="'.$color.'"/><circle cx="760" cy="140" r="220" fill="white" opacity=".08"/><path d="M0 500L960 200V600H0Z" fill="white" opacity=".06"/><text x="65" y="390" fill="white" font-family="Arial,sans-serif" font-weight="bold" font-size="68">'.$label.'</text><text x="70" y="450" fill="white" font-family="Arial,sans-serif" font-size="24">STUDENT EVENTS</text></svg>');
    }
    foreach ($events as $i=>[$title,$club,$category,$creator,$start,$end,$location,$format,$capacity,$status]) {
        query('INSERT INTO events (club_id,category_id,created_by,title,description,starts_at,ends_at,location,format,capacity,cover_url,status,cancellation_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[
            $club,$category,$creator,$title,'Приглашаем студентов на мероприятие «'.$title.'». Познакомьтесь с участниками сообщества, попробуйте новое и проведите время вместе. Участие бесплатное; предварительная регистрация обязательна.',
            gmdate('Y-m-d H:i:s',strtotime($start)),gmdate('Y-m-d H:i:s',strtotime($end)),$location,$format,$capacity,'/uploads/cover-'.$club.'.svg',$status,$status==='cancelled'?'Изменились организационные условия':null,
        ]);
        $id=(int)db()->lastInsertId();
        if ($status!=='draft') {
            foreach ([7,8] as $userId) {
                query('INSERT INTO registrations (user_id,event_id,status) VALUES (?,?,?)',[$userId,$id,$status==='cancelled'?'cancelled_by_organizer':'active']);
                query('INSERT INTO reactions (user_id,event_id,type) VALUES (?,?,?)',[$userId,$id,$userId===7?'like':'interested']);
            }
        }
    }
    foreach (range(5,14) as $userId) {
        notify($userId,'event_cancelled',12,'Кинопоказ во дворе отменён: изменились организационные условия');
        notify($userId,'event_changed',1,'Обновлена информация о волейбольном турнире');
    }
    db()->commit();
    require_once __DIR__.'/student-demo.php';
    addStudentDemo();
    echo "Готово: 14 пользователей, 4 Комьюнити, 6 категорий, 13 мероприятий.\n";
} catch (Throwable $error) { db()->rollBack(); throw $error; }

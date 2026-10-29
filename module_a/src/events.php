<?php
declare(strict_types=1);
function eventSelect(): string {
    return "SELECT e.*, c.name AS club_name, cat.name AS category_name,
        (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='active') AS registered_count,
        (SELECT COUNT(*) FROM reactions r WHERE r.event_id=e.id AND r.type='like') AS likes_count,
        (SELECT COUNT(*) FROM reactions r WHERE r.event_id=e.id AND r.type='interested') AS interested_count
        FROM events e JOIN clubs c ON c.id=e.club_id JOIN categories cat ON cat.id=e.category_id";
}
function eventCard(array $event): array {
    // Добавляем к данным события счётчики, текущий статус и отметки вошедшего студента.
    foreach (['id','club_id','category_id','created_by','registered_count','likes_count','interested_count'] as $key) if (isset($event[$key])) $event[$key] = (int)$event[$key];
    $event['capacity'] = $event['capacity'] === null ? null : (int)$event['capacity'];
    $event['free_places'] = $event['capacity'] === null ? null : max(0,$event['capacity']-$event['registered_count']);
    $event['display_status'] = $event['status'] === 'published'
        ? (strtotime($event['starts_at']) > time() ? 'planned' : (strtotime($event['ends_at']) > time() ? 'ongoing' : 'completed')) : $event['status'];
    $event['club'] = ['id'=>$event['club_id'],'name'=>$event['club_name']];
    $event['category'] = ['id'=>$event['category_id'],'name'=>$event['category_name']];
    foreach (['starts_at','ends_at','created_at','updated_at'] as $key) $event[$key] = gmdate('Y-m-d\TH:i:s\Z',strtotime($event[$key]));
    $user = currentUser();
    $event['is_registered'] = $user ? (bool)one("SELECT id FROM registrations WHERE user_id=? AND event_id=? AND status='active'",[$user['id'],$event['id']]) : false;
    $event['my_reactions'] = $user ? query('SELECT type FROM reactions WHERE user_id=? AND event_id=?',[$user['id'],$event['id']])->fetchAll(PDO::FETCH_COLUMN) : [];
    $event['can_manage'] = $user ? verified((int)$user['id'],$event['club_id']) : false;
    return $event;
}
function findEvent(int $id, bool $lock = false): array {
    $event = one('SELECT * FROM events WHERE id=?' . ($lock ? ' FOR UPDATE' : ''),[$id]);
    if (!$event) fail(404,'NOT_FOUND','Мероприятие не найдено');
    return $event;
}
function publicEvent(array $event): void {
    $user = currentUser();
    if ($event['status'] === 'draft' && (!$user || !verified((int)$user['id'],(int)$event['club_id']))) fail(404,'NOT_FOUND','Мероприятие не найдено');
}
function manageEvent(array $user, array $event): void {
    // Представитель одного клуба не получает права на мероприятия остальных клубов.
    if (!verified((int)$user['id'],(int)$event['club_id'])) fail(403,'FORBIDDEN','Нет прав на мероприятия этого Комьюнити');
}
function dateValue(mixed $value, string $field): string {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/D',$value)) fail(422,'VALIDATION_ERROR','Некорректная дата',[$field=>['Используйте ISO 8601']]);
    try { $date = new DateTimeImmutable($value, new DateTimeZone('UTC')); }
    catch (Exception) { fail(422,'VALIDATION_ERROR','Некорректная дата',[$field=>['Некорректная дата']]); }
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) fail(422,'VALIDATION_ERROR','Некорректная дата',[$field=>['Несуществующая дата']]);
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function eventValues(array $data, ?array $old = null): array {
    $fields = ['club_id','category_id','title','description','starts_at','ends_at','location','format','capacity','cover_url'];
    if ($old) {
        foreach (['club_id','created_by','status'] as $field) if (array_key_exists($field,$data)) fail(422,'VALIDATION_ERROR','Поле нельзя менять',[$field=>['Используйте отдельное действие или оставьте поле без изменений']]);
    } else validate($data,['club_id','category_id','title','description','starts_at','ends_at','location','format']);
    $d = array_merge($old ?: [],array_intersect_key($data,array_flip($fields)));
    $result = ['club_id'=>integer($d['club_id'] ?? null,'club_id'),'category_id'=>integer($d['category_id'] ?? null,'category_id'),
        'title'=>text($d,'title',200),'description'=>text($d,'description',30000),'starts_at'=>dateValue($d['starts_at'] ?? null,'starts_at'),
        'ends_at'=>dateValue($d['ends_at'] ?? null,'ends_at'),'location'=>text($d,'location',1000),'format'=>$d['format'] ?? '',
        'capacity'=>isset($d['capacity']) && $d['capacity'] !== '' ? integer($d['capacity'],'capacity',1,1000000) : null,
        'cover_url'=>isset($d['cover_url']) && $d['cover_url'] !== '' ? text($d,'cover_url',1000) : null];
    if (!in_array($result['format'],['offline','online'],true)) fail(422,'VALIDATION_ERROR','Ошибка формата',['format'=>['Допустимы offline и online']]);
    if ($result['ends_at'] <= $result['starts_at']) fail(422,'VALIDATION_ERROR','Окончание должно быть после начала',['ends_at'=>['Позже начала']]);
    if ((!$old || $result['starts_at'] !== $old['starts_at']) && strtotime($result['starts_at']) <= time()) fail(422,'VALIDATION_ERROR','Начало должно быть в будущем',['starts_at'=>['Укажите будущее время']]);
    if ($result['format'] === 'online' && (!filter_var($result['location'],FILTER_VALIDATE_URL) || !preg_match('~^https?://~i',$result['location']))) fail(422,'VALIDATION_ERROR','Для онлайн-события нужна ссылка',['location'=>['Укажите http/https ссылку']]);
    if ($result['cover_url'] && !preg_match('~^(https?://|/uploads/)~i',$result['cover_url'])) fail(422,'VALIDATION_ERROR','Некорректная обложка',['cover_url'=>['Нужна ссылка http/https или /uploads/']]);
    if (!one('SELECT id FROM clubs WHERE id=?',[$result['club_id']])) fail(422,'VALIDATION_ERROR','Комьюнити не найдено',['club_id'=>['Не найдено']]);
    if (!one('SELECT id FROM categories WHERE id=?',[$result['category_id']])) fail(422,'VALIDATION_ERROR','Категория не найдена',['category_id'=>['Не найдена']]);
    if ($old && $result['capacity'] !== null) {
        $count = (int)query("SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='active'",[$old['id']])->fetchColumn();
        if ($result['capacity'] < $count) fail(422,'VALIDATION_ERROR','Вместимость меньше числа участников',['capacity'=>['Уже записано: '.$count]]);
    }
    return $result;
}
function eventParticipantsNotify(int $eventId, string $type, string $message): void {
    foreach (query("SELECT user_id FROM registrations WHERE event_id=? AND status='active'",[$eventId])->fetchAll() as $row) notify((int)$row['user_id'],$type,$eventId,$message);
}
function listEvents(bool $mine = false): array {
    $params = []; $conditions = [];
    if ($mine) {
        $user = requireUser();
        if (!one('SELECT user_id FROM club_users WHERE user_id=? AND verified=1',[$user['id']])) fail(403,'FORBIDDEN','Требуется верификация Комьюнити');
        $conditions[] = 'EXISTS (SELECT 1 FROM club_users cu WHERE cu.club_id=e.club_id AND cu.user_id=? AND cu.verified=1)'; $params[] = $user['id'];
        if (isset($_GET['status'])) {
            if (!in_array($_GET['status'],['draft','published','cancelled'],true)) fail(422,'VALIDATION_ERROR','Некорректный статус',['status'=>['draft, published, cancelled']]);
            $conditions[]='e.status=?'; $params[]=$_GET['status'];
        }
    } else $conditions[]="e.status='published'";
    foreach (['category_id','club_id'] as $field) if (isset($_GET[$field]) && $_GET[$field] !== '') { $conditions[]="e.$field=?"; $params[]=integer($_GET[$field],$field); }
    if (isset($_GET['format']) && $_GET['format'] !== '') {
        if (!in_array($_GET['format'],['offline','online'],true)) fail(422,'VALIDATION_ERROR','Некорректный формат',['format'=>['offline или online']]);
        $conditions[]='e.format=?'; $params[]=$_GET['format'];
    }
    foreach (['date_from'=>'>=','date_to'=>'<='] as $field=>$operator) if (!empty($_GET[$field])) {
        $date = dateValue($_GET[$field],$field);
        if ($field==='date_to' && strlen($_GET[$field])===10) $date=substr($date,0,10).' 23:59:59';
        $conditions[]="e.starts_at $operator ?"; $params[]=$date;
    }
    if (isset($_GET['upcoming'])) {
        if (!in_array($_GET['upcoming'],['true','false','1','0'],true)) fail(422,'VALIDATION_ERROR','Некорректный фильтр',['upcoming'=>['true или false']]);
        if (in_array($_GET['upcoming'],['true','1'],true)) $conditions[]='e.starts_at>UTC_TIMESTAMP()';
    }
    if (isset($_GET['q']) && $_GET['q'] !== '') {
        $search=text($_GET,'q',200);
        // % и _ в поисковой строке считаем обычными символами, а не шаблонами SQL.
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
        $conditions[]="(e.title LIKE ? ESCAPE '!' OR e.description LIKE ? ESCAPE '!')";
        $params[]=$pattern;$params[]=$pattern;
    }
    $sort = $_GET['sort'] ?? 'starts_at';
    if (!in_array($sort,['starts_at','popularity'],true)) fail(422,'VALIDATION_ERROR','Некорректная сортировка',['sort'=>['starts_at или popularity']]);
    $where=' WHERE '.implode(' AND ',$conditions);
    $order=$sort==='popularity' ? '(registered_count+likes_count+interested_count) DESC, e.starts_at ASC, e.id ASC' : 'e.starts_at ASC, e.id ASC';
    return paged(eventSelect().$where.' ORDER BY '.$order,$params,'SELECT COUNT(*) FROM events e'.$where,'eventCard');
}
function eventRoutes(string $method, string $path): void {
    if ($method==='GET' && $path==='/api/events') json(listEvents());
    if ($method==='GET' && $path==='/api/me/events') json(listEvents(true));
    if ($method==='POST' && $path==='/api/events') {
        $user=requireUser(); $data=body();
        $clubId=integer($data['club_id'] ?? null,'club_id');
        if (!verified((int)$user['id'],$clubId)) fail(403,'FORBIDDEN','Вы не верифицированы в этом Комьюнити');
        $values=eventValues($data); $values['created_by']=$user['id'];
        $columns=array_keys($values);
        query('INSERT INTO events ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($values));
        $id=(int)db()->lastInsertId(); json(['data'=>eventCard(one(eventSelect().' WHERE e.id=?',[$id]))],201);
    }
    if (!preg_match('~^/api/events/(\d+)(?:/(publish|cancel|register|reactions|participants))?$~D',$path,$match)) return;
    $id=(int)$match[1]; $action=$match[2] ?? '';
    if ($method==='GET' && $action==='') {
        $event=findEvent($id); publicEvent($event);
        json(['data'=>eventCard(one(eventSelect().' WHERE e.id=?',[$id]))]);
    }
    if ($method==='GET' && $action==='participants') {
        $user=requireUser(); $event=findEvent($id); manageEvent($user,$event);
        if (isset($_GET['export']) && $_GET['export']==='txt') {
            header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="participants-'.$id.'.txt"');
            echo "Имя\tСпециальность\tКурс\tРегистрация (UTC)\tСтатус\n";
            foreach (query('SELECT u.name,u.faculty,u.course,r.created_at,r.status FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.event_id=? ORDER BY r.id',[$id])->fetchAll() as $row) echo implode("\t",array_map(fn($v)=>str_replace(["\r","\n","\t"],' ',(string)$v),$row))."\n";
            exit;
        }
        json(paged('SELECT u.id AS user_id,u.name,u.faculty,u.course,r.created_at AS registered_at,r.status FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.event_id=? ORDER BY r.id',[$id],'SELECT COUNT(*) FROM registrations WHERE event_id=?'));
    }
    if (in_array($method,['POST','DELETE'],true) && $action==='register') {
        $user=requireUser();
        transaction(function () use ($id,$user,$method) {
            // Пока считаем места и записываем студента, другая запись на это событие ждёт.
            $event=findEvent($id,true); publicEvent($event);
            if ($event['status']==='cancelled') fail(409,'EVENT_CANCELLED','Мероприятие отменено');
            if ($event['status']!=='published') fail(404,'NOT_FOUND','Мероприятие не опубликовано');
            if (strtotime($event['starts_at'])<=time()) fail(409,'EVENT_STARTED','Мероприятие уже началось');
            if ($method==='DELETE') { query('DELETE FROM registrations WHERE user_id=? AND event_id=?',[$user['id'],$id]); return; }
            if (one('SELECT id FROM registrations WHERE user_id=? AND event_id=?',[$user['id'],$id])) fail(409,'ALREADY_REGISTERED','Вы уже зарегистрированы');
            $count=(int)query("SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='active'",[$id])->fetchColumn();
            if ($event['capacity']!==null && $count>=(int)$event['capacity']) fail(409,'EVENT_FULL','Свободных мест нет');
            query('INSERT INTO registrations (user_id,event_id) VALUES (?,?)',[$user['id'],$id]);
            notify((int)$user['id'],'registration_confirmed',$id,'Вы записались: '.$event['title']);
            if ($event['capacity']!==null && $count+1===(int)$event['capacity']) notify((int)$event['created_by'],'event_full',$id,'Все места заняты: '.$event['title']);
        });
        json(['data'=>eventCard(one(eventSelect().' WHERE e.id=?',[$id]))],$method==='POST'?201:200);
    }
    if (in_array($method,['POST','DELETE'],true) && $action==='reactions') {
        $user=requireUser(); $d=$method==='POST'?body():$_GET;
        $type=$d['type'] ?? '';
        if (!in_array($type,['like','interested'],true)) fail(422,'VALIDATION_ERROR','Некорректная реакция',['type'=>['like или interested']]);
        transaction(function () use ($id,$user,$type,$method) {
            $event=findEvent($id,true); publicEvent($event);
            if ($event['status']==='draft') fail(404,'NOT_FOUND','Мероприятие не опубликовано');
            if ($method==='POST') query('INSERT INTO reactions (user_id,event_id,type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE type=VALUES(type)',[$user['id'],$id,$type]);
            else query('DELETE FROM reactions WHERE user_id=? AND event_id=? AND type=?',[$user['id'],$id,$type]);
        });
        json(['data'=>eventCard(one(eventSelect().' WHERE e.id=?',[$id]))]);
    }
    if (($method==='PATCH' && $action==='') || ($method==='DELETE' && $action==='') || ($method==='POST' && in_array($action,['publish','cancel'],true))) {
        $user=requireUser();
        transaction(function () use ($id,$user,$method,$action) {
            $event=findEvent($id,true); manageEvent($user,$event);
            if ($method==='DELETE') {
                if ($event['status']!=='draft') fail(403,'FORBIDDEN','Удалять можно только черновики');
                query('DELETE FROM events WHERE id=?',[$id]); return;
            }
            if ($action==='publish') {
                if ($event['status']!=='draft') fail(409,'INVALID_STATE','Опубликовать можно только черновик');
                if (strtotime($event['starts_at'])<=time()) fail(409,'EVENT_STARTED','Укажите будущее время перед публикацией');
                query("UPDATE events SET status='published' WHERE id=?",[$id]); return;
            }
            if ($action==='cancel') {
                if ($event['status']==='cancelled') fail(409,'EVENT_CANCELLED','Мероприятие уже отменено');
                $reason=text(body(),'reason',3000);
                eventParticipantsNotify($id,'event_cancelled','Отменено: '.$event['title'].'. Причина: '.$reason);
                query("UPDATE registrations SET status='cancelled_by_organizer' WHERE event_id=? AND status='active'",[$id]);
                query("UPDATE events SET status='cancelled',cancellation_reason=? WHERE id=?",[$reason,$id]); return;
            }
            $values=eventValues(body(),$event);
            $changed=$values['starts_at']!==$event['starts_at'] || $values['location']!==$event['location'];
            $assign=[]; $params=[];
            foreach ($values as $key=>$value) if ($key!=='club_id') { $assign[]="$key=?"; $params[]=$value; }
            $params[]=$id; query('UPDATE events SET '.implode(',',$assign).' WHERE id=?',$params);
            if ($changed) eventParticipantsNotify($id,'event_changed','Изменились время или место: '.$event['title']);
        });
        if ($method==='DELETE') json(['message'=>'Черновик удалён']);
        json(['data'=>eventCard(one(eventSelect().' WHERE e.id=?',[$id]))]);
    }
}

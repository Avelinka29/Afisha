<?php
declare(strict_types=1);
function adminRoutes(string $method, string $path): void {
    if (!str_starts_with($path,'/api/admin/')) return;
    $admin=requireAdmin();
    if ($method==='GET' && $path==='/api/admin/users') json(paged('SELECT id,name,email,faculty,course,role,blocked,email_verified_at FROM users ORDER BY id',[],'SELECT COUNT(*) FROM users'));
    if ($method==='PATCH' && preg_match('~^/api/admin/users/(\d+)/block$~D',$path,$m)) {
        $d=body();
        if (!isset($d['blocked']) || !is_bool($d['blocked'])) fail(422,'VALIDATION_ERROR','Укажите blocked: true или false',['blocked'=>['Нужно логическое значение']]);
        if ((int)$m[1]===(int)$admin['id']) fail(403,'FORBIDDEN','Нельзя заблокировать себя');
        if (!one('SELECT id FROM users WHERE id=?',[$m[1]])) fail(404,'NOT_FOUND','Пользователь не найден');
        query('UPDATE users SET blocked=? WHERE id=?',[(int)$d['blocked'],$m[1]]);
        json(['message'=>'Статус пользователя обновлён']);
    }
    if ($method==='PUT' && preg_match('~^/api/admin/clubs/(\d+)/representatives/(\d+)$~D',$path,$m)) {
        $d=body();
        if (!isset($d['verified']) || !is_bool($d['verified'])) fail(422,'VALIDATION_ERROR','Укажите verified: true или false',['verified'=>['Нужно логическое значение']]);
        if (!one('SELECT id FROM clubs WHERE id=?',[$m[1]]) || !one('SELECT id FROM users WHERE id=?',[$m[2]])) fail(404,'NOT_FOUND','Пользователь или Комьюнити не найдены');
        query('INSERT INTO club_users (club_id,user_id,verified) VALUES (?,?,?) ON DUPLICATE KEY UPDATE verified=VALUES(verified)',[$m[1],$m[2],(int)$d['verified']]);
        json(['message'=>'Верификация обновлена']);
    }
    if (preg_match('~^/api/admin/(clubs|categories)(?:/(\d+))?$~D',$path,$m)) {
        $table=$m[1]; $id=isset($m[2])?(int)$m[2]:null;
        if ($id && !one("SELECT id FROM $table WHERE id=?",[$id])) fail(404,'NOT_FOUND','Запись не найдена');
        if ($method==='GET') json(['data'=>$id?one("SELECT * FROM $table WHERE id=?",[$id]):query("SELECT * FROM $table ORDER BY id")->fetchAll()]);
        if (($method==='POST' && !$id) || ($method==='PATCH' && $id)) {
            $d=body(); $name=text($d,'name',$table==='clubs'?150:120); $params=[$name]; $columns='name'; $assign='name=?';
            if ($table==='clubs') { $params[]=text($d,'description',10000); $columns.=',description'; $assign.=',description=?'; }
            if ($id) { $params[]=$id; query("UPDATE $table SET $assign WHERE id=?",$params); }
            else { query("INSERT INTO $table ($columns) VALUES (".implode(',',array_fill(0,count($params),'?')).')',$params); $id=(int)db()->lastInsertId(); }
            json(['data'=>one("SELECT * FROM $table WHERE id=?",[$id])],$method==='POST'?201:200);
        }
        if ($method==='DELETE' && $id) {
            if (one("SELECT id FROM events WHERE ".($table==='clubs'?'club_id':'category_id').'=? LIMIT 1',[$id])) fail(409,'IN_USE','Справочник используется в мероприятиях');
            transaction(function () use ($table,$id) { if ($table==='clubs') query('DELETE FROM club_users WHERE club_id=?',[$id]); query("DELETE FROM $table WHERE id=?",[$id]); });
            json(['message'=>'Запись удалена']);
        }
    }
    if ($method==='POST' && preg_match('~^/api/admin/events/(\d+)/cancel$~D',$path,$m)) {
        $reason=text(body(),'reason',3000); $id=(int)$m[1];
        transaction(function () use ($id,$reason) {
            $event=findEvent($id,true);
            if ($event['status']==='cancelled') fail(409,'EVENT_CANCELLED','Мероприятие уже отменено');
            eventParticipantsNotify($id,'event_cancelled','Отменено модератором: '.$event['title'].'. '.$reason);
            query("UPDATE registrations SET status='cancelled_by_organizer' WHERE event_id=? AND status='active'",[$id]);
            query("UPDATE events SET status='cancelled',cancellation_reason=? WHERE id=?",[$reason,$id]);
        });
        json(['message'=>'Мероприятие отменено']);
    }
}

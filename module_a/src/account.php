<?php
declare(strict_types=1);
function accountRoutes(string $method, string $path): void {
    if ($path==='/api/me' && $method==='GET') {
        $user=currentUser();
        if (!$user) fail(401,'UNAUTHORIZED','Войдите в аккаунт');
        json(['data'=>profile($user)]);
    }
    if ($path==='/api/me' && $method==='PATCH') {
        $user=requireUser(); $d=body(); $assign=[]; $params=[];
        foreach (array_keys($d) as $key) if (!in_array($key,['name','faculty','course','avatar_url'],true)) fail(422,'VALIDATION_ERROR','Поле нельзя менять',[$key=>['Недоступно для редактирования']]);
        foreach (['name'=>120,'faculty'=>190] as $field=>$max) if (array_key_exists($field,$d)) { $assign[]="$field=?"; $params[]=text($d,$field,$max); }
        if (array_key_exists('course',$d)) { $assign[]='course=?'; $params[]=integer($d['course'],'course',1,4); }
        if (array_key_exists('avatar_url',$d)) {
            $url=$d['avatar_url']===null || $d['avatar_url']==='' ? null : text($d,'avatar_url',1000);
            if ($url && !preg_match('~^(https?://|/uploads/)~i',$url)) fail(422,'VALIDATION_ERROR','Некорректный адрес',['avatar_url'=>['Нужен http/https или /uploads/']]);
            $assign[]='avatar_url=?'; $params[]=$url;
        }
        if ($assign) { $params[]=$user['id']; query('UPDATE users SET '.implode(',',$assign).' WHERE id=?',$params); }
        json(['data'=>profile(one('SELECT * FROM users WHERE id=?',[$user['id']]))]);
    }
    if ($method==='GET' && $path==='/api/me/registrations') {
        $user=requireUser(); $where='r.user_id=?'; $params=[$user['id']];
        $period=$_GET['period'] ?? null;
        if ($period!==null && !in_array($period,['past','upcoming'],true)) fail(422,'VALIDATION_ERROR','Некорректный период',['period'=>['past или upcoming']]);
        if ($period==='past') $where.=" AND e.ends_at<=UTC_TIMESTAMP() AND r.status='active' AND e.status='published'";
        if ($period==='upcoming') $where.=' AND e.starts_at>UTC_TIMESTAMP()';
        $map=function ($row) { $row['event']=eventCard(one(eventSelect().' WHERE e.id=?',[$row['event_id']])); return $row; };
        json(paged('SELECT r.* FROM registrations r JOIN events e ON e.id=r.event_id WHERE '.$where.' ORDER BY e.starts_at ASC,r.id ASC',$params,'SELECT COUNT(*) FROM registrations r JOIN events e ON e.id=r.event_id WHERE '.$where,$map));
    }
    if ($method==='GET' && $path==='/api/me/reactions') {
        $user=requireUser();
        json(paged('SELECT * FROM reactions WHERE user_id=? ORDER BY created_at DESC,id DESC',[$user['id']],'SELECT COUNT(*) FROM reactions WHERE user_id=?',function ($row) {
            $row['event']=eventCard(one(eventSelect().' WHERE e.id=?',[$row['event_id']])); return $row;
        }));
    }
    if ($method==='GET' && $path==='/api/me/notifications') {
        $user=requireUser();
        $result=paged('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC,id DESC',[$user['id']],'SELECT COUNT(*) FROM notifications WHERE user_id=?',function ($row) { $row['payload']=json_decode($row['payload'],true); return $row; });
        $result['meta']['unread_total']=(int)query('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL',[$user['id']])->fetchColumn();
        json($result);
    }
    if ($method==='PATCH' && preg_match('~^/api/me/notifications/(\d+)/read$~D',$path,$m)) {
        $user=requireUser();
        if (!one('SELECT id FROM notifications WHERE id=? AND user_id=?',[$m[1],$user['id']])) fail(404,'NOT_FOUND','Уведомление не найдено');
        query('UPDATE notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=? AND user_id=?',[$m[1],$user['id']]);
        json(['message'=>'Прочитано']);
    }
    if ($method==='POST' && $path==='/api/uploads') {
        requireUser(); $file=$_FILES['file'] ?? null;
        if (!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>4*1024*1024) fail(422,'VALIDATION_ERROR','Не удалось загрузить изображение',['file'=>['Максимум 4 МБ']]);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
        if (!$extension || !getimagesize($file['tmp_name'])) fail(422,'VALIDATION_ERROR','Нужно изображение JPEG, PNG или WebP',['file'=>['Недопустимый файл']]);
        $dir=__DIR__.'/../public/uploads'; if (!is_dir($dir)) mkdir($dir,0755,true);
        $name=bin2hex(random_bytes(16)).'.'.$extension;
        if (!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) fail(500,'INTERNAL_ERROR','Не удалось сохранить файл');
        json(['data'=>['url'=>'/uploads/'.$name]],201);
    }
}

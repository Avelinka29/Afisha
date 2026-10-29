<?php
$groups=[];
function requestItem(string $group,string $name,string $method,string $path,?array $body,string $description,?string $after=null): void {
    global $groups;
    $request=['method'=>$method,'header'=>[['key'=>'Content-Type','value'=>'application/json']],
        'url'=>'{{base_url}}'.$path,'description'=>$description];
    if ($body!==null) $request['body']=['mode'=>'raw','raw'=>json_encode($body,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'options'=>['raw'=>['language'=>'json']]];
    if ($path==='/uploads') {
        $request['header']=[];
        $request['body']=['mode'=>'formdata','formdata'=>[['key'=>'file','type'=>'file','src'=>[]]]];
    }
    $item=['name'=>$name,'request'=>$request];
    if ($after) $item['event']=[['listen'=>'test','script'=>['type'=>'text/javascript','exec'=>explode("\n",$after)]]];
    $groups[$group][]=$item;
}
$login=function(string $group,string $email) { requestItem($group,'Войти: '.$email,'POST','/auth/login',['email'=>$email,'password'=>'{{password}}'],'Запустить перед остальными запросами этой папки. Postman сохраняет сессионную cookie автоматически.'); };
requestItem('1. Гость','Проверка сервера и БД','GET','/health',null,'Ожидается status=ok и database=connected.');
requestItem('1. Гость','Афиша: фильтры и пагинация','GET','/events?page=1&per_page=12&sort=starts_at&upcoming=true',null,'Фильтры: category_id, club_id, format, date_from, date_to, q. Уберите upcoming, чтобы увидеть прошедшие события. Ответ: data и meta.');
requestItem('1. Гость','Поиск и популярность','GET','/events?q=студент&sort=popularity',null,'Популярность — сумма активных регистраций и обеих реакций.');
requestItem('1. Гость','Карточка мероприятия','GET','/events/{{event_id}}',null,'Гостю недоступны черновики (404). Отменённая карточка доступна по прямой ссылке.');
requestItem('1. Гость','Комьюнити','GET','/clubs',null,'Справочник для фильтров и формы мероприятия.');
requestItem('1. Гость','Категории','GET','/categories',null,'Справочник категорий.');
requestItem('2. Аккаунт','Регистрация','POST','/auth/register',['name'=>'Новый студент','email'=>'{{new_email}}','password'=>'{{password}}','faculty'=>'Информационные технологии','course'=>2],'Создаёт студента. При SMTP откройте письмо; в тестовом режиме скопируйте token из module_a/storage/mail.log в переменную verify_token.');
requestItem('2. Аккаунт','Подтверждение email и автовход','POST','/auth/verify-email',['token'=>'{{verify_token}}'],'Токен одноразовый, действует 24 часа. После подтверждения создаётся сессия. GET-вариант ссылки из письма перенаправляет на клиент.');
requestItem('2. Аккаунт','Повторить письмо подтверждения','POST','/auth/resend-verification',['email'=>'{{new_email}}'],'Повторная отправка для неподтверждённой почты. Ответ не раскрывает существование аккаунта.');
requestItem('2. Аккаунт','Google: состояние настройки','GET','/auth/google/config',null,'Показывает enabled без раскрытия Client Secret.');
requestItem('2. Аккаунт','Google: получить URL входа','GET','/auth/google/url',null,'Возвращает ссылку Google и сохраняет state/nonce/PKCE в сессии. Полный вход проходить кнопкой сайта: браузер и Postman имеют разные cookie.');
requestItem('2. Аккаунт','Google: защита callback','GET','/auth/google/callback?state=invalid&code=invalid',null,'Ожидается 302 на login?google_error=GOOGLE_INVALID_STATE. Для просмотра отключить автоматические редиректы Postman.');
requestItem('2. Аккаунт','Google: данные для завершения','GET','/auth/google/pending',null,'Нужна сессия после подтверждённого входа Google; без неё 401. Секреты и токены не возвращаются.');
requestItem('2. Аккаунт','Google: завершить профиль','POST','/auth/google/complete',['name'=>'Новый студент','faculty'=>'ИТ','course'=>2,'password'=>'{{password}}'],'После реального Google callback заполнить обязательные данные. Без Google-сессии возвращает 401.');
$login('2. Аккаунт','student@example.com');
requestItem('2. Аккаунт','Запрос восстановления','POST','/auth/password/forgot',['email'=>'{{new_email}}'],'Ответ одинаковый для существующего и неизвестного email. Токен из письма положите в reset_token.');
requestItem('2. Аккаунт','Новый пароль по токену','POST','/auth/password/reset',['token'=>'{{reset_token}}','password'=>'{{password}}','password_confirmation'=>'{{password}}'],'Токен действует один час и используется один раз. Старые сессии становятся недействительными.');
requestItem('2. Аккаунт','Смена пароля','POST','/me/password',['current_password'=>'{{password}}','password'=>'{{password}}','password_confirmation'=>'{{password}}'],'Требуется вход. В реальном сценарии укажите другой новый пароль; здесь тестовый пароль сохраняется.');
requestItem('2. Аккаунт','Выход','POST','/auth/logout',[],'Уничтожает сессию. Для последующих защищённых запросов снова выполните вход.');
$login('3. Студент','student@example.com');
requestItem('3. Студент','Привязать Google к профилю','GET','/auth/google/url?mode=link',null,'Требует действующую сессию студента. Само связывание происходит после согласия в браузере Google.');
requestItem('3. Студент','Мой профиль','GET','/me',null,'В ответе роль и список Комьюнити с verified. Это единственный доступный маршрут для заблокированной действующей сессии.');
requestItem('3. Студент','Изменить профиль','PATCH','/me',['name'=>'Студент для проверки','faculty'=>'Информационные технологии','course'=>2,'avatar_url'=>null],'Можно менять только name, faculty, course, avatar_url. Email и роль менять нельзя.');
requestItem('3. Студент','Записаться','POST','/events/{{event_id}}/register',[],'Выберите опубликованное будущее событие со свободными местами. Повторная запись: 409 ALREADY_REGISTERED.');
requestItem('3. Студент','Отменить запись','DELETE','/events/{{event_id}}/register',null,'Отмена разрешена только до начала события.');
requestItem('3. Студент','Нравится','POST','/events/{{event_id}}/reactions',['type'=>'like'],'Повторная отправка не создаёт дубль. Обе реакции могут существовать одновременно.');
requestItem('3. Студент','Интересно','POST','/events/{{event_id}}/reactions',['type'=>'interested'],'Это отдельная реакция, она не регистрирует на мероприятие.');
requestItem('3. Студент','Снять реакцию','DELETE','/events/{{event_id}}/reactions?type=like',null,'Укажите like или interested в query-параметре type.');
requestItem('3. Студент','Мои регистрации','GET','/me/registrations?period=upcoming&page=1&per_page=12',null,'Уберите period для всех регистраций. В каждой записи есть карточка event и статус регистрации.');
requestItem('3. Студент','Посещённые события','GET','/me/registrations?period=past',null,'Завершённые опубликованные мероприятия с активной регистрацией. Фактическая явка не учитывается.');
requestItem('3. Студент','Мои реакции','GET','/me/reactions?page=1&per_page=12',null,'Реакция, дата и карточка мероприятия.');
requestItem('3. Студент','Уведомления','GET','/me/notifications?page=1&per_page=12',null,'payload.event_id используется для перехода к мероприятию. Сохраните id уведомления в notification_id.');
requestItem('3. Студент','Прочитать уведомление','PATCH','/me/notifications/{{notification_id}}/read',[],'Разрешено только владельцу уведомления; чужое возвращает 404.');
requestItem('3. Студент','Загрузка изображения','POST','/uploads',null,'В Body выберите form-data, добавьте ключ file типа File, выберите PNG/JPEG/WebP до 4 МБ. Удалите вручную заголовок Content-Type: Postman добавит multipart boundary. Полученный data.url используйте как cover_url или avatar_url.');
$login('4. Представитель','rep1@example.com');
requestItem('4. Представитель','Мероприятия моих Комьюнити','GET','/me/events?page=1&per_page=12',null,'Включает черновики. Дополнительный фильтр: status=draft|published|cancelled.');
requestItem('4. Представитель','Создать черновик','POST','/events',[
    'club_id'=>1,'category_id'=>1,'title'=>'Турнир студенческих команд','description'=>'Открытый турнир для студентов университета.',
    'starts_at'=>'{{starts_at}}','ends_at'=>'{{ends_at}}','location'=>'Спортивный зал','format'=>'offline','capacity'=>30,'cover_url'=>'/uploads/cover-1.svg',
],'Верификация проверяется по club_id. id созданного события автоматически сохраняется в managed_event_id.',
"if (pm.response.code === 201) pm.collectionVariables.set('managed_event_id', pm.response.json().data.id);");
requestItem('4. Представитель','Редактировать','PATCH','/events/{{managed_event_id}}',['title'=>'Открытый турнир команд','location'=>'Спортивный зал, корпус А'],'club_id и created_by менять нельзя. Смена времени или места уведомляет зарегистрированных.');
requestItem('4. Представитель','Опубликовать','POST','/events/{{managed_event_id}}/publish',[],'Публикует черновик без одобрения администратора. Начало должно быть в будущем.');
requestItem('4. Представитель','Участники','GET','/events/{{managed_event_id}}/participants?page=1&per_page=12',null,'Имя, специальность, курс, время регистрации, статус. Доступ только верифицированным представителям этого Комьюнити.');
requestItem('4. Представитель','Экспорт участников','GET','/events/{{managed_event_id}}/participants?export=txt',null,'Ответ — текстовый файл UTF-8 с табуляцией, а не JSON.');
requestItem('4. Представитель','Отменить мероприятие','POST','/events/{{managed_event_id}}/cancel',['reason'=>'Перенос организационных планов'],'Причина обязательна. Все зарегистрированные получают уведомление; их статус становится cancelled_by_organizer.');
requestItem('4. Представитель','Удалить черновик','DELETE','/events/{{draft_id}}',null,'В draft_id укажите созданный ненужный черновик. Опубликованные и отменённые события удалять нельзя (403).');
$login('5. Администратор','admin@example.com');
requestItem('5. Администратор','Пользователи','GET','/admin/users?page=1&per_page=12',null,'Список для блокировки и выбора представителей.');
requestItem('5. Администратор','Верифицировать представителя','PUT','/admin/clubs/2/representatives/5',['verified'=>true],'Привязывает студента id=5 к Комьюнити id=2. Для отзыва отправьте verified=false.');
requestItem('5. Администратор','Отозвать верификацию','PUT','/admin/clubs/2/representatives/5',['verified'=>false],'Права проверяются при каждом запросе, повторный вход не нужен.');
requestItem('5. Администратор','Блокировка пользователя','PATCH','/admin/users/5/block',['blocked'=>true],'Блокирует действующие сессии на всех API, кроме GET /me. Себя блокировать нельзя.');
requestItem('5. Администратор','Снять блокировку','PATCH','/admin/users/5/block',['blocked'=>false],'Возвращает доступ студенту id=5.');
foreach (['clubs'=>'Комьюнити','categories'=>'Категории'] as $table=>$label) {
    requestItem('5. Администратор',$label.': список','GET','/admin/'.$table,null,'Административный список справочника.');
    $data=['name'=>'Новая запись']; if ($table==='clubs') $data['description']='Описание сообщества';
    requestItem('5. Администратор',$label.': создать','POST','/admin/'.$table,$data,'Создаёт запись. id сохранится в '.$table.'_id.',"if (pm.response.code === 201) pm.collectionVariables.set('{$table}_id', pm.response.json().data.id);");
    $data['name']='Обновлённая запись';
    requestItem('5. Администратор',$label.': изменить','PATCH','/admin/'.$table.'/{{'.$table.'_id}}',$data,'Изменяет запись из предыдущего запроса.');
    requestItem('5. Администратор',$label.': удалить','DELETE','/admin/'.$table.'/{{'.$table.'_id}}',null,'Удаление используемого справочника запрещено: 409 IN_USE.');
}
requestItem('5. Администратор','Модерация мероприятия','POST','/admin/events/{{event_id}}/cancel',['reason'=>'Модерация: нарушение правил'],'Отменяет мероприятие любого Комьюнити. Выполняйте только если хотите отменить тестовое событие.');
$login('6. Проверка ограничений','student@example.com');
requestItem('6. Проверка ограничений','Студент не создаёт события','POST','/events',['club_id'=>1],'Ожидается 403 FORBIDDEN.');
requestItem('6. Проверка ограничений','Скрытый черновик','GET','/events/9',null,'Ожидается 404 NOT_FOUND для обычного студента.');
requestItem('6. Проверка ограничений','Нет мест','POST','/events/3/register',[],'Ожидается 409 EVENT_FULL: у концерта заняты оба места.');
requestItem('6. Проверка ограничений','Событие началось','POST','/events/4/register',[],'Ожидается 409 EVENT_STARTED.');
requestItem('6. Проверка ограничений','Событие отменено','POST','/events/12/register',[],'Ожидается 409 EVENT_CANCELLED.');
requestItem('6. Проверка ограничений','Заблокированный аккаунт','POST','/auth/login',['email'=>'blocked@example.com','password'=>'{{password}}'],'Ожидается 403 USER_BLOCKED.');
$collection=[
    'info'=>['name'=>'ProfSkills — Афиша студентов','schema'=>'https://schema.getpostman.com/json/collection/v2.1.0/collection.json','description'=>'Сессионная аутентификация. Выполняйте вход в начале папки: Postman хранит cookie. API запускается на localhost:8000. Почта через SMTP при настройке .env; тестовый журнал: module_a/storage/mail.log.'],
    'variable'=>array_map(fn($key,$value)=>['key'=>$key,'value'=>$value],array_keys($vars=[
        'base_url'=>'http://localhost:8000/api','password'=>'ProfSkills2026!','event_id'=>'1','managed_event_id'=>'','draft_id'=>'9','notification_id'=>'1',
        'new_email'=>'','verify_token'=>'','reset_token'=>'','starts_at'=>'','ends_at'=>'','clubs_id'=>'','categories_id'=>'',
    ]),array_values($vars)),
    'event'=>[['listen'=>'prerequest','script'=>['type'=>'text/javascript','exec'=>[
        "if (!pm.collectionVariables.get('new_email')) pm.collectionVariables.set('new_email', 'student-' + Date.now() + '@example.com');",
        "if (!pm.collectionVariables.get('starts_at')) pm.collectionVariables.set('starts_at', new Date(Date.now()+7*86400000).toISOString());",
        "if (!pm.collectionVariables.get('ends_at')) pm.collectionVariables.set('ends_at', new Date(Date.now()+7*86400000+7200000).toISOString());",
    ]]]],
    'item'=>array_map(fn($name,$items)=>['name'=>$name,'item'=>$items],array_keys($groups),array_values($groups)),
];
file_put_contents(__DIR__.'/../profskills.postman_collection.json',json_encode($collection,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo "Коллекция создана\n";

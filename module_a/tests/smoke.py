"""Проверка API через HTTP. Создаёт и удаляет свои тестовые мероприятия."""
import json, urllib.request, urllib.error, urllib.parse, http.cookiejar, uuid, datetime, pathlib, subprocess, base64, os
BASE = os.environ.get('TEST_API_URL','http://localhost:8000/api')
PASSWORD = 'ProfSkills2026!'
checks = 0
state_file = pathlib.Path(__file__).resolve().parents[1]/'storage'/'smoke-state.json'
assert not state_file.exists(), 'Сначала очистите записи предыдущей проверки: php module_a/tests/cleanup.php'
state = {'events': [], 'emails': []}
def save_state(): state_file.write_text(json.dumps(state, ensure_ascii=False))
class Client:
    def __init__(self):
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def call(self, method, path, data=None, expected=200, code=None, raw=None, headers=None):
        global checks
        payload = raw if raw is not None else (json.dumps(data).encode() if data is not None else None)
        req = urllib.request.Request(BASE + urllib.parse.quote(path, safe='/?=&'), data=payload, method=method, headers=headers or {'Content-Type':'application/json'})
        try:
            with self.http.open(req) as response: status, body = response.status, response.read()
        except urllib.error.HTTPError as error: status, body = error.code, error.read()
        try: result = json.loads(body)
        except Exception: raise AssertionError((path, status, body[:800]))
        assert status == expected, (method, path, expected, status, result)
        if code: assert result.get('error',{}).get('code') == code, (path,result)
        checks += 1
        return result
    def login(self, email): return self.call('POST','/auth/login',{'email':email,'password':PASSWORD})
def stamp(hours): return (datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=hours)).strftime('%Y-%m-%dT%H:%M:%SZ')
def event(title='Проверка API', **changes):
    return dict({'club_id':1,'category_id':1,'title':title,'description':'Событие интеграционной проверки','starts_at':stamp(48),'ends_at':stamp(50),'location':'Корпус А','format':'offline','capacity':2}, **changes)
guest, student, rep, other, admin = [Client() for _ in range(5)]
health=guest.call('GET','/health')
assert health['database']=='connected'
assert health.get('mail_transport')=='log', 'Для тестов запустите отдельный API с MAIL_TRANSPORT=log и укажите TEST_API_URL; реальные письма тесты не отправляют'
listing=guest.call('GET','/events?per_page=2')
assert len(listing['data'])==2 and listing['meta']['total']>=8
assert all(e['status']=='published' for e in listing['data'])
guest.call('GET','/events?format=online&upcoming=true&sort=popularity')
guest.call('GET','/events?category_id=1&club_id=1&q=Кубок&date_from=2020-01-01&date_to=2099-12-31')
for path in ['/events?page=0','/events?sort=bad','/events?format=bad','/events?date_from=2026-02-30']:
    guest.call('GET',path,expected=422,code='VALIDATION_ERROR')
guest.call('GET','/clubs'); guest.call('GET','/categories')
guest.call('GET','/events/9',expected=404,code='NOT_FOUND')
guest.call('GET','/events/12')
guest.call('GET','/me',expected=401,code='UNAUTHORIZED')
guest.call('POST','/events/1/register',{},401,'UNAUTHORIZED')
guest.call('POST','/events',event(),401,'UNAUTHORIZED')
guest.call('POST','/auth/login',{'email':'student@example.com','password':'badpassword'},401,'UNAUTHORIZED')
guest.call('POST','/auth/login',{'email':'blocked@example.com','password':PASSWORD},403,'USER_BLOCKED')
student.login('student@example.com'); rep.login('rep1@example.com'); other.login('rep2@example.com'); admin.login('admin@example.com')
assert rep.call('GET','/me')['data']['role']=='representative'
student.call('POST','/events',event(),403,'FORBIDDEN')
student.call('GET','/me/events',expected=403,code='FORBIDDEN')
student.call('GET','/events/9',expected=404,code='NOT_FOUND')
other.call('PATCH','/events/9',{'title':'Чужое событие'},403,'FORBIDDEN')
rep.call('GET','/events/10',expected=404,code='NOT_FOUND')
rep.call('GET','/me/events?status=draft&per_page=1')
rep.call('POST','/events',event(ends_at=stamp(1)),422,'VALIDATION_ERROR')
rep.call('POST','/events',event(format='online'),422,'VALIDATION_ERROR')
created = rep.call('POST','/events',event(),201)['data']; eid=created['id']
state['events'].append({'id':eid,'title':created['title']}); save_state()
assert created['status']=='draft'
try:
    rep.call('PATCH',f'/events/{eid}',{'club_id':2},422,'VALIDATION_ERROR')
    rep.call('PATCH',f'/events/{eid}',{'title':'Обновлённая проверка'})
    state['events'][0]['title']='Обновлённая проверка'; save_state()
    guest.call('GET',f'/events/{eid}',expected=404)
    rep.call('POST',f'/events/{eid}/publish',{})
    rep.call('POST',f'/events/{eid}/publish',{},409,'INVALID_STATE')
    guest.call('GET',f'/events/{eid}')
    rep.call('DELETE',f'/events/{eid}',{},403,'FORBIDDEN')
    student.call('POST',f'/events/{eid}/register',{},201)
    student.call('POST',f'/events/{eid}/register',{},409,'ALREADY_REGISTERED')
    enrolled=student.call('GET',f'/events/{eid}')['data']; assert enrolled['is_registered'] and enrolled['registered_count']==1
    student.call('POST',f'/events/{eid}/reactions',{'type':'like'})
    student.call('POST',f'/events/{eid}/reactions',{'type':'like'})
    assert student.call('GET',f'/events/{eid}')['data']['likes_count']==1
    student.call('POST',f'/events/{eid}/reactions',{'type':'interested'})
    assert len(student.call('GET',f'/events/{eid}')['data']['my_reactions'])==2
    student.call('DELETE',f'/events/{eid}/reactions?type=like')
    student.call('POST',f'/events/{eid}/reactions',{'type':'bad'},422,'VALIDATION_ERROR')
    student.call('GET',f'/events/{eid}/participants',expected=403,code='FORBIDDEN')
    rep.call('GET',f'/events/{eid}/participants?per_page=1')
    rep.call('PATCH',f'/events/{eid}',{'location':'Новый корпус'})
    notices=student.call('GET','/me/notifications?per_page=100')['data']
    assert any(n['type']=='event_changed' and int(n['payload']['event_id'])==eid for n in notices)
    student.call('PATCH',f"/me/notifications/{notices[0]['id']}/read",{})
    rep.call('PATCH',f'/events/{eid}',{'capacity':1})
    second=Client(); second.login('student1@example.com')
    second.call('POST',f'/events/{eid}/register',{},409,'EVENT_FULL')
    student.call('DELETE',f'/events/{eid}/register')
    second.call('POST',f'/events/{eid}/register',{},201)
    repnotices=rep.call('GET','/me/notifications?per_page=100')['data']
    assert any(n['type']=='event_full' and int(n['payload']['event_id'])==eid for n in repnotices)
    second.call('DELETE',f'/events/{eid}/register')
    student.call('POST',f'/events/{eid}/register',{},201)
    rep.call('POST',f'/events/{eid}/cancel',{'reason':'Тест отмены'})
    student.call('POST',f'/events/{eid}/register',{},409,'EVENT_CANCELLED')
    registrations=student.call('GET','/me/registrations?per_page=100')['data']
    assert any(int(r['event_id'])==eid and r['status']=='cancelled_by_organizer' for r in registrations)
    assert all(e['id']!=eid for e in guest.call('GET','/events?per_page=100')['data'])
finally:
    # Опубликованное событие удаляем только из тестовой БД при отдельной очистке ниже.
    pass
student.call('POST','/events/4/register',{},409,'EVENT_STARTED')
student.call('POST','/events/3/register',{},409,'EVENT_FULL')
student.call('PATCH','/me',{'role':'admin'},422,'VALIDATION_ERROR')
student.call('PATCH','/me',{'course':5},422,'VALIDATION_ERROR')
student.call('PATCH','/me',{'course':0},422,'VALIDATION_ERROR')
student.call('PATCH','/me',{'name':'Студент для проверки','course':2})
student.call('GET','/me/reactions'); student.call('GET','/me/registrations?period=past')
admin.call('GET','/admin/users?per_page=2')
admin.call('PUT','/admin/clubs/2/representatives/5',{'verified':True})
assert student.call('GET','/me')['data']['role']=='representative'
admin.call('PUT','/admin/clubs/2/representatives/5',{'verified':False})
admin.call('PATCH','/admin/users/5/block',{'blocked':True})
try:
    student.call('GET','/events',expected=403,code='USER_BLOCKED')
    student.call('GET','/me')
finally: admin.call('PATCH','/admin/users/5/block',{'blocked':False})
student.call('POST','/auth/logout',{})
student.call('GET','/me',expected=401)
# Полный цикл нового аккаунта: подтверждение, сброс и смена пароля.
guest.call('POST','/auth/register',{'name':'Тест','email':'invalid-course@example.com','password':PASSWORD,'faculty':'ИТ','course':5},422,'VALIDATION_ERROR')
fresh=Client(); email='test-'+uuid.uuid4().hex[:10]+'@example.com'
fresh.call('POST','/auth/register',{'name':'Тест','email':email,'password':PASSWORD,'faculty':'ИТ','course':4},201)
state['emails'].append(email); save_state()
fresh.call('POST','/auth/resend-verification',{'email':email})
guest.call('POST','/auth/resend-verification',{'email':'unknown@example.com'})
fresh.call('POST','/auth/login',{'email':email,'password':PASSWORD},403,'NOT_VERIFIED')
mailpath=pathlib.Path(__file__).resolve().parents[1]/'storage'/'mail.log'
mail=[json.loads(line) for line in mailpath.read_text().splitlines() if line.strip()]
verify=next(m for m in reversed(mail) if m['to']==email)
token=verify['text'].split('token=')[1]
fresh.call('POST','/auth/verify-email',{'token':token})
fresh.call('GET','/me')
fresh.call('POST','/auth/verify-email',{'token':token},422,'VALIDATION_ERROR')
fresh.call('POST','/auth/password/forgot',{'email':email})
mail=[json.loads(line) for line in mailpath.read_text().splitlines() if line.strip()]
reset=next(m for m in reversed(mail) if m['to']==email)['text'].split('token=')[1]
fresh.call('POST','/auth/password/reset',{'token':reset,'password':'Changed2026!'})
fresh.call('GET','/me',expected=401)
fresh.call('POST','/auth/login',{'email':email,'password':'Changed2026!'})
fresh.call('POST','/me/password',{'current_password':'Changed2026!','password':PASSWORD,'password_confirmation':PASSWORD})
fresh.call('POST','/auth/password/reset',{'token':reset,'password':PASSWORD},422,'VALIDATION_ERROR')
guest.call('POST','/auth/password/forgot',{'email':'unknown@example.com'})
guest.call('POST','/auth/login',expected=422,raw=b'{')
guest.call('POST','/auth/login',{},403,'FORBIDDEN',headers={'Content-Type':'application/json','Origin':'https://foreign.example'})
# Черновик можно удалить через API.
draft=rep.call('POST','/events',event('Удаляемый черновик'),201)['data']['id']
rep.call('DELETE',f'/events/{draft}')
guest.call('GET',f'/events/{draft}',expected=404)
second.call('DELETE','/events/4/register',expected=409,code='EVENT_STARTED')
# Справочники: создание, изменение, удаление и запрет удаления используемого.
for table, data in [('clubs',{'name':'Тест клуба','description':'Тест'}),('categories',{'name':'Тест категории'})]:
    record=admin.call('POST','/admin/'+table,data,201)['data']['id']
    admin.call('GET',f'/admin/{table}/{record}')
    data['name']='Обновлённая запись'
    admin.call('PATCH',f'/admin/{table}/{record}',data)
    admin.call('DELETE',f'/admin/{table}/{record}')
admin.call('DELETE','/admin/categories/1',expected=409,code='IN_USE')
# Текстовый экспорт действительно отдаёт файл.
export=rep.http.open(BASE+f'/events/{eid}/participants?export=txt')
assert 'text/plain' in export.headers['Content-Type'] and 'Студент'.encode() in export.read()
checks+=1
# Загрузка небольшого PNG, получение файла и отклонение неподходящего MIME.
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
boundary='ProfskillsBoundary'
multipart=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n').encode()+png+f'\r\n--{boundary}--\r\n'.encode()
uploaded=rep.call('POST','/uploads',expected=201,raw=multipart,headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})['data']['url']
with rep.http.open('http://localhost:8000'+uploaded) as response: assert response.read()==png
checks+=1
upload_file=pathlib.Path(__file__).resolve().parents[1]/'public'/uploaded.lstrip('/')
upload_file.unlink()
rep.call('POST','/uploads',{},422,'VALIDATION_ERROR')
# Напоминания: оба интервала и отсутствие дублей при повторном запуске.
student.login('student@example.com')
reminder_ids=[]
for hours in [23+58/60,58/60]:
    title='Проверка напоминания '+str(hours)
    reminder_event=rep.call('POST','/events',event(title,starts_at=stamp(hours),ends_at=stamp(hours+2)),201)['data']
    rid=reminder_event['id']; reminder_ids.append(rid)
    state['events'].append({'id':rid,'title':title}); save_state()
    rep.call('POST',f'/events/{rid}/publish',{})
    student.call('POST',f'/events/{rid}/register',{},201)
root=pathlib.Path(__file__).resolve().parents[2]
subprocess.run(['php','module_a/bin/reminders.php'],cwd=root,check=True,capture_output=True)
subprocess.run(['php','module_a/bin/reminders.php'],cwd=root,check=True,capture_output=True)
notices=student.call('GET','/me/notifications?per_page=100')['data']
for rid in reminder_ids:
    assert len([n for n in notices if n['type']=='event_reminder' and int(n['payload']['event_id'])==rid])==1
checks+=2
print(f'Успешно: {checks} HTTP-проверок. Тестовое событие: {eid}. Тестовый аккаунт: {email}')

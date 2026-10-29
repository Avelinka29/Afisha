"""Дополнительные проверки строк 18, 25, 56, 58–60 таблицы критериев."""
import urllib.request,urllib.error,http.cookiejar,json,os,pathlib,subprocess,time,urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[2];BASE=os.environ.get('TEST_API_URL','http://localhost:8001/api');checks=[]
class Client:
 def __init__(self):self.jar=http.cookiejar.CookieJar();self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
 def call(self,method,path,data=None,expected=200,code=None):
  req=urllib.request.Request(BASE+path,method=method,headers={'Content-Type':'application/json'},data=None if data is None else json.dumps(data).encode())
  try:response=self.http.open(req)
  except urllib.error.HTTPError as error:response=error
  status=response.code;body=json.load(response);assert status==expected,(method,path,status,body)
  if code:assert body['error']['code']==code,(path,body)
  if status==422:assert isinstance(body['error'].get('fields'),dict),(path,body)
  assert 'password_hash' not in json.dumps(body) and 'password"' not in json.dumps(body),(path,'password leaked')
  checks.append({'method':method,'path':path,'status':status,'code':body.get('error',{}).get('code')})
  return body
 def login(self,email):return self.call('POST','/auth/login',{'email':email,'password':'ProfSkills2026!'})
 def sid(self):return next(c.value for c in self.jar if c.name=='profskills_session')
def age(client,seconds=None):
 cmd=['php','module_a/tests/session-fixture.php',client.sid()]
 if seconds is not None:cmd.append(str(seconds))
 return int(subprocess.check_output(cmd,cwd=ROOT,text=True))
guest=Client();assert guest.call('GET','/health')['mail_transport']=='log'
student,admin,blocked=[Client() for _ in range(3)];student.login('student@example.com');admin.login('admin@example.com');blocked.login('student2@example.com')
age(student,600);student.call('GET','/me');assert age(student)>=time.time()-3
old=student.sid();student.call('POST','/auth/refresh',{});assert student.sid()!=old
age(student,7201);student.call('GET','/me',expected=401,code='UNAUTHORIZED')
student.login('student@example.com');student.call('POST','/auth/logout',{});student.call('GET','/me',expected=401,code='UNAUTHORIZED')
for q in ["' OR 1=1 --",'%','_','открыт','ОТКРЫТ','о','zzz-not-existing']:
 result=guest.call('GET','/events?q='+urllib.parse.quote(q))
 for e in result['data']:
  assert q.lower().replace('ё','е') in (e['title']+' '+e['description']).lower().replace('ё','е'),(q,e['title'])
student.login('student@example.com')
for data in [{'role':'admin'},{'email':'changed@example.com'},{'course':9},{'name':''}]:student.call('PATCH','/me',data,422,'VALIDATION_ERROR')
student.call('POST','/auth/login',{'email':'student@example.com','password':'incorrect'},401,'UNAUTHORIZED')
admin.call('PATCH','/admin/users/8/block',{'blocked':True})
try:
 routes=[('GET','/events'),('GET','/events/1'),('GET','/clubs'),('GET','/categories'),('GET','/me/events'),('GET','/me/notifications'),('GET','/health'),('GET','/auth/google/config'),('POST','/events/1/register'),('DELETE','/events/1/register'),('POST','/events/1/reactions'),('PATCH','/me'),('POST','/auth/refresh'),('POST','/auth/logout'),('GET','/admin/users')]
 for method,path in routes:blocked.call(method,path,{} if method!='GET' else None,403,'USER_BLOCKED')
 assert blocked.call('GET','/me')['data']['blocked']
finally:admin.call('PATCH','/admin/users/8/block',{'blocked':False})
path=ROOT/'module_a/storage/criteria-http-report.json';path.write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('Дополнительные HTTP-проверки критериев:',len(checks),'— успешно')

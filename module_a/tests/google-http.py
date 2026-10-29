import urllib.request,urllib.error,urllib.parse,http.cookiejar,json,pathlib,subprocess,uuid,os
ROOT=pathlib.Path(__file__).resolve().parents[2]
BASE=os.environ.get('TEST_API_URL','http://localhost:8001/api')
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args):return None
session='google-test-'+uuid.uuid4().hex
email='test-'+uuid.uuid4().hex[:10]+'@example.com'
session=subprocess.run(['php','module_a/tests/google-session.php',session,'google-test-'+uuid.uuid4().hex,email],cwd=ROOT,check=True,capture_output=True,text=True).stdout.strip()
state=ROOT/'module_a/storage/smoke-state.json'
assert not state.exists(),'Очистите предыдущие тестовые записи'
client=urllib.request.build_opener(NoRedirect())
checks=0
def call(method,path,data=None,cookie=session):
 global checks
 headers={'Content-Type':'application/json'}
 if cookie:headers['Cookie']='profskills_session='+cookie
 request=urllib.request.Request(BASE+path,method=method,headers=headers,data=None if data is None else json.dumps(data).encode())
 try:response=client.open(request)
 except urllib.error.HTTPError as error:response=error
 raw=response.read();checks+=1
 return response.code,response.headers,json.loads(raw) if raw else None
status,_,body=call('GET','/auth/google/pending');assert status==200 and body['data']['email']==email
status,_,_=call('POST','/auth/google/complete',{'name':'Тест Google','faculty':'ИТ','course':5,'password':'ProfSkills2026!'});assert status==422
status,headers,body=call('POST','/auth/google/complete',{'name':'Тест Google','faculty':'ИТ','course':2,'password':'ProfSkills2026!'});assert status==201 and body['data']['google_connected'] and body['data']['role']=='student'
state.write_text(json.dumps({'events':[],'emails':[email]}))
# При входе идентификатор сессии обновляется.
newcookie=headers['Set-Cookie'].split('profskills_session=')[1].split(';')[0]
status,_,body=call('GET','/me',cookie=newcookie);assert status==200 and body['data']['email_verified_at']
status,_,_=call('GET','/auth/google/pending',cookie=newcookie);assert status==401
status,_,_=call('GET','/auth/google/url?mode=link',cookie=None);assert status==401
status,_,body=call('GET','/auth/google/url?next=https://foreign.example',cookie=newcookie);assert status==200
q=urllib.parse.parse_qs(urllib.parse.urlsplit(body['data']['url']).query);assert q['code_challenge_method']==['S256'] and q['nonce'] and q['state']
status,headers,_=call('GET','/auth/google/callback?state=wrong&code=wrong',cookie=newcookie);assert status==302 and 'GOOGLE_INVALID_STATE' in headers['Location']
status,_,body=call('GET','/auth/google/url',cookie=newcookie);q=urllib.parse.parse_qs(urllib.parse.urlsplit(body['data']['url']).query)
status,headers,_=call('GET','/auth/google/callback?state='+q['state'][0]+'&error=access_denied',cookie=newcookie);assert status==302 and 'GOOGLE_CANCELLED' in headers['Location']
print('Google HTTP:',checks,'проверок завершения профиля, сессии, PKCE, отказа и state пройдено')

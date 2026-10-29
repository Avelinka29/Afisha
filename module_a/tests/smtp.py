"""Локальная проверка SMTP: письма во внешнюю сеть не отправляются."""
import socketserver, threading, subprocess, os, email, email.policy, pathlib
ROOT=pathlib.Path(__file__).resolve().parents[2]
received=[]
class SMTP(socketserver.StreamRequestHandler):
    reject=False
    def reply(self,message):self.wfile.write((message+'\r\n').encode());self.wfile.flush()
    def handle(self):
        self.reply('220 localhost test smtp')
        while True:
            line=self.rfile.readline().decode().strip()
            if not line:return
            command=line.split(' ')[0]
            if command=='EHLO':self.reply('250-localhost\r\n250 AUTH LOGIN')
            elif line=='AUTH LOGIN':
                self.reply('334 VXNlcm5hbWU6');self.rfile.readline();self.reply('334 UGFzc3dvcmQ6');self.rfile.readline();self.reply('535 Authentication failed' if self.reject else '235 Authentication accepted')
                if self.reject:return
            elif command in ['MAIL','RCPT']:self.reply('250 OK')
            elif command=='DATA':
                self.reply('354 Send message');lines=[]
                while True:
                    line=self.rfile.readline()
                    if line==b'.\r\n':break
                    if not line:return
                    lines.append(line)
                received.append(email.message_from_bytes(b''.join(lines),policy=email.policy.default));self.reply('250 Accepted')
            elif command=='QUIT':self.reply('221 Bye');return
            else:self.reply('500 Unsupported')
for reject,fallback in [(False,False),(True,False)]:
    SMTP.reject=reject
    with socketserver.TCPServer(('127.0.0.1',0),SMTP) as server:
        worker=threading.Thread(target=server.serve_forever,daemon=True);worker.start()
        env=os.environ.copy();env.update(MAIL_TRANSPORT='smtp',MAIL_FALLBACK_LOG='true' if fallback else 'false',SMTP_HOST='127.0.0.1',SMTP_PORT=str(server.server_address[1]),SMTP_ENCRYPTION='none',SMTP_USERNAME='local-test',SMTP_PASSWORD='local-test')
        result=subprocess.run(['php','module_a/tests/smtp-send.php'],cwd=ROOT,env=env,capture_output=True,text=True)
        assert result.returncode==(1 if reject and not fallback else 0),(result.stdout,result.stderr)
        if fallback:assert result.stdout.strip()=='log'
        server.shutdown();worker.join()
assert len(received)==1
assert str(received[0]['Subject'])=='Проверка SMTP'
assert 'https://example.com/verify?token=test' in received[0].get_content()
assert 'recipient@example.com' in str(received[0]['To'])
print('SMTP: отправка, AUTH LOGIN, UTF-8, содержимое письма и отказ авторизации проверены')

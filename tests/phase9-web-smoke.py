"""Post-retirement web smoke: every role page must work without legacy table names."""
import os,re,urllib.request,urllib.parse,http.cookiejar
from pathlib import Path

BASE=os.environ.get('OJTRACK_TEST_URL','http://127.0.0.1:8087/ojtrack/')
if os.environ.get('OJTRACK_TEST_CONFIRM')!='synthetic':
    raise SystemExit('Set OJTRACK_TEST_CONFIRM=synthetic.')

checks=0
def check(ok,label):
    global checks
    assert ok,label
    checks+=1

class Client:
    def __init__(self):
        self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token=''
    def req(self,path,data=None):
        if data is not None:
            data=dict(data)
            data['csrf_token']=self.token
            data=urllib.parse.urlencode(data,doseq=True).encode()
        try:
            r=self.op.open(BASE+path,data,timeout=10)
        except urllib.error.HTTPError as e:
            r=e
        body=r.read().decode('utf-8',errors='replace')
        m=re.search(r'name="csrf-token" content="([^"]+)"',body)
        if m:self.token=m[1]
        check(not re.search(r'(Fatal error|Warning:|Parse error|Table .* doesn.t exist)',body,re.I),path+' runtime error: '+body[:500])
        return r.status,body,r.url
    def login(self,i):
        self.req('login.php')
        return self.req('login.php',{'action':'login','email':f'test{i}@example.invalid','password':'Synthetic-test-pass-42'})

root=Path(__file__).resolve().parents[1]
for idx,role in [(1,'admin'),(2,'coordinator'),(3,'company'),(4,'student')]:
    c=Client()
    status,body,url=c.login(idx)
    check(status==200 and 'login.php' not in url,'login '+role)
    for page in sorted((root/role).glob('*.php')):
        status,body,url=c.req(role+'/'+page.name)
        check(status==200,role+'/'+page.name+' status '+str(status))

print({'phase9_web_smoke_checks_passed':checks,'result':'PASS'})

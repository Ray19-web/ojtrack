"""Run only against the synthetic tests/seed.php database. Uses Python stdlib."""
import os, re, json, subprocess, urllib.request, urllib.parse, http.cookiejar
from pathlib import Path
BASE=os.environ.get('OJTRACK_TEST_URL','http://127.0.0.1:8087/ojtrack/')
SOCKET=os.environ.get('OJTRACK_TEST_SOCKET','/tmp/ojtrack-mariadb.sock')
if os.environ.get('OJTRACK_TEST_CONFIRM') != 'synthetic':
    raise SystemExit('Set OJTRACK_TEST_CONFIRM=synthetic; this suite mutates only seeded test data.')
checks=0
def check(condition,label):
    global checks
    assert condition,label
    checks+=1
def sql(q):
    return subprocess.check_output(['mariadb','--socket='+SOCKET,'-uroot','ojtrack_test','-N','-B','-e',q],text=True).strip()
class Client:
    def __init__(self):
        self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token=''
    def req(self,path,data=None,token=True):
        if data is not None:
            data=dict(data)
            if token:data['csrf_token']=self.token
            data=urllib.parse.urlencode(data,doseq=True).encode()
        try:r=self.op.open(BASE+path,data,timeout=10)
        except urllib.error.HTTPError as e:r=e
        body=r.read().decode('utf-8',errors='replace')
        m=re.search(r'name="csrf-token" content="([^"]+)"',body)
        if m:self.token=m[1]
        check(not re.search(r'(Fatal error|Warning:|Parse error)',body),path+' PHP error: '+body[:350])
        return r.status,body,r.url
    def login(self,i,password='Synthetic-test-pass-42'):
        self.req('login.php')
        return self.req('login.php',{'action':'login','email':f'test{i}@example.invalid','password':password})
c={}
for i in range(1,9):
    c[i]=Client();status,body,url=c[i].login(i)
    check(status==200 and 'login.php' not in url,'login '+str(i))
roles={1:'admin',2:'coordinator',3:'company',4:'student'}
for i,role in roles.items():
    for p in sorted((Path(__file__).resolve().parents[1]/role).glob('*.php')):
        status,body,url=c[i].req(role+'/'+p.name)
        check(status==200,role+'/'+p.name+' status '+str(status))
        for script in re.findall(r'<script\b[^>]*>(.*?)</script>',body,re.S|re.I):
            if script.strip():
                result=subprocess.run(['node','--check'],input=script,text=True,capture_output=True)
                check(result.returncode==0,'rendered JavaScript '+str(p)+': '+result.stderr)
        for form in re.findall(r'<form\b[^>]*method=["\']POST["\'][^>]*>(.*?)</form>',body,re.S|re.I):
            check('name="csrf_token"' in form,'rendered POST token '+str(p))
# Authentication, session & CSRF boundaries.
anon=Client()
check(anon.req('api/notifications.php')[0]==401,'anonymous API')
bad=Client()
check('login.php' in bad.login(1,'admin123')[2],'removed password bypass')
check(c[4].req('admin/users.php')[0]==403,'cross-role page blocked')
check(c[4].req('api/notifications.php',{'action':'mark_read','all':1},token=False)[0]==403,'CSRF blocked')
check(c[8].req('api/messages.php')[0]==403,'onboarding API gated')
# Notifications mark-one must not mark-all.
check(c[4].req('api/notifications.php',{'action':'mark_read','id':1})[0]==200,'notification mark one')
check(sql("SELECT GROUP_CONCAT(is_read ORDER BY id) FROM notifications WHERE id IN(1,2,3)")=='1,0,0','notification scope')
check(c[4].req('api/notifications.php',{'action':'mark_read'})[0]==422,'ambiguous mark-all denied')
check(c[4].req('api/notifications.php',{'action':'mark_read','all':1})[0]==200,'explicit mark all')
check(sql("SELECT is_read FROM notifications WHERE id=3")=='0','other user unchanged')
# Published and foreign evaluation ownership.
for data,expected in [
({'action':'add_section','form_id':1,'title':'Tamper'},409),
({'action':'add_section','form_id':3,'title':'Tamper'},403),
({'action':'delete_criterion','form_id':2,'criterion_id':4},403)]:
    check(c[2].req('coordinator/evaluation.php',data)[0]==expected,'evaluation guard '+str(data))
# Missing criteria leave no partial answers.
c[3].req('company/evaluation.php',{'action':'submit_submission','submission_id':2,'criterion_1':'100'})
check(sql('SELECT COUNT(*) FROM eval_answers WHERE submission_id=2')=='0','missing answer rolls back')
c[6].req('company/evaluation.php',{'action':'submit_submission','submission_id':2,'criterion_1':100,'criterion_2':0})
check(sql("SELECT status FROM eval_submissions WHERE id=2")=='pending','other company blocked')
valid={'action':'submit_submission','submission_id':2,'criterion_1':100,'criterion_2':0,'comments':'Synthetic'}
c[3].req('company/evaluation.php',valid);c[3].req('company/evaluation.php',valid)
check(sql('SELECT COUNT(*) FROM eval_answers WHERE submission_id=2')=='2','retry idempotent')
check(sql('SELECT overall_score FROM eval_submissions WHERE id=2')=='50.00','zero and 100 accepted')
check(sql('SELECT COUNT(*) FROM eval_answers WHERE submission_id=2 AND equivalent IS NULL')=='2','no equivalent overflow')
# Returned journals are revisable; date change recalculates week.
c[4].req('student/journal.php',{'action':'submit_journal','edit_id':1,'entry_date':'2026-09-08','activities':'Revised','learnings':'Revised','challenges':'Revised','hours_rendered':8})
check(sql("SELECT CONCAT(status,':',week_number) FROM journal_entries WHERE id=1")=='pending:36','returned journal resubmitted with new week')
# Scoped announcements and documents, including onboarding.
body=c[4].req('student/announcements.php')[1]
check('VISIBLE ASSIGNED' in body and 'VISIBLE ADMIN' in body and 'HIDDEN OTHER' not in body,'announcement audience')
check(c[4].req('download.php?file=requirements/test.txt')[1]=='Synthetic private document','own document')
check(c[7].req('download.php?file=requirements/test.txt')[0]==404,'other student document denied')
check(c[8].req('download.php?file=requirements/onboarding.txt')[1]=='Synthetic onboarding document','onboarding own document')
check(c[4].req('download.php?file=../config/db.php')[0]==404,'traversal denied')
check(c[4].req('uploads/requirements/test.txt')[0]==403,'test router blocks direct documents')
# Form creation binding, version copy, rule validation and scoped messaging.
c[2].req('coordinator/evaluation.php',{'action':'save_form','form_id':0,'title':'New test form','description':'Test','score_mode':'percentage'})
check(sql("SELECT COUNT(*) FROM evaluation_forms WHERE title='New test form'")=='1','form creation binding')
c[2].req('coordinator/evaluation.php',{'action':'edit_active','form_id':1})
check(sql("SELECT COUNT(*) FROM evaluation_forms WHERE parent_id=1 AND status='draft'")=='1','immutable version clone')
check(c[2].req('coordinator/evaluation.php',{'action':'add_rule','form_id':2,'score_min':101,'score_max':105,'equivalent':1})[0]==422,'invalid score band blocked')
check(c[4].req('api/messages.php',{'action':'create_thread','name':'Foreign','thread_type':'direct','members':'5'})[0]==403,'foreign recipient blocked')
status,body,url=c[4].req('api/messages.php',{'action':'create_thread','name':'Assigned','thread_type':'direct','members':'2'})
thread=json.loads(body)['thread_id']
check(json.loads(c[4].req('api/messages.php',{'action':'send','thread_id':thread,'message':'Synthetic hello'})[1])['ok'],'message saved')
check(not json.loads(c[7].req('api/messages.php?thread_id='+str(thread))[1])['ok'],'foreign thread read blocked')
check(not json.loads(c[7].req('api/messages.php',{'thread_id':thread,'message':'Tamper'})[1])['ok'],'foreign thread write blocked')
check('Synthetic hello' in c[2].req('api/messages.php?thread_id='+str(thread))[1],'recipient reads message')
# Password change retains current login and revokes another session.
second=Client();second.login(4)
c[4].req('student/profile.php',{'action':'change_password','current_password':'Synthetic-test-pass-42','new_password':'Synthetic-new-pass-43','confirm_password':'Synthetic-new-pass-43','redirect':'https://example.invalid/'})
check(c[4].req('api/notifications.php')[0]==200,'current password-change session retained')
check(second.req('api/notifications.php')[0]==401,'other password-change session revoked')
sql("UPDATE users SET status='inactive' WHERE id=7")
check(c[7].req('api/notifications.php')[0]==401,'inactive session revoked')
sql("UPDATE users SET status='active' WHERE id=7")
print(json.dumps({'checks_passed':checks,'result':'PASS'}))

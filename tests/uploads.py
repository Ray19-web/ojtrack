"""Run after integration.py against the same disposable seeded test instance."""
import os, re, io, zipfile, uuid, struct, zlib, subprocess, urllib.request, urllib.error, urllib.parse, http.cookiejar
from pathlib import Path
if os.environ.get('OJTRACK_TEST_CONFIRM') != 'synthetic':
    raise SystemExit('Set OJTRACK_TEST_CONFIRM=synthetic for the disposable seeded environment.')
BASE=os.environ.get('OJTRACK_TEST_URL','http://127.0.0.1:8087/ojtrack/')
SOCKET=os.environ.get('OJTRACK_TEST_SOCKET','/tmp/ojtrack-mariadb.sock')
ROOT=Path(__file__).resolve().parents[1]
PRIVATE=Path(os.environ['OJTRACK_PRIVATE_UPLOAD_DIR'])
count=0
def check(value,label):
    global count
    assert value,label
    count+=1
def sql(q):
    return subprocess.check_output(['mariadb','--socket='+SOCKET,'-uroot','ojtrack_test','-N','-B','-e',q],text=True).strip()
class Client:
    def __init__(self,i):
        self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token=''
        self.get('login.php')
        self.post('login.php',{'action':'login','email':f'test{i}@example.invalid','password':'Synthetic-new-pass-43' if i==4 else 'Synthetic-test-pass-42'})
    def send(self,req):
        try:r=self.op.open(req,timeout=20)
        except urllib.error.HTTPError as e:r=e
        body=r.read().decode(errors='replace')
        m=re.search(r'name="csrf-token" content="([^"]+)"',body)
        if m:self.token=m[1]
        check(not re.search(r'(Fatal error|Warning:|Parse error)',body),'PHP failure '+body[:200])
        return r.status,body
    def get(self,path):return self.send(BASE+path)
    def post(self,path,data,files=()):
        boundary='----OJTrackTest'+uuid.uuid4().hex
        chunks=[]
        for key,value in dict(data,csrf_token=self.token).items():
            chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        for field,name,content in files:
            chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+b'\r\n')
        chunks.append(f'--{boundary}--\r\n'.encode())
        return self.send(urllib.request.Request(BASE+path,data=b''.join(chunks),headers={'Content-Type':'multipart/form-data; boundary='+boundary}))
def png():
    def chunk(t,d):return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',1,1,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(b'\x00\xff\x00\x00'))+chunk(b'IEND',b'')
def office(kind='docx',macro=False):
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w',zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml','<Types><Override ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
        z.writestr('word/document.xml','<document/>')
        if macro:z.writestr('word/vbaProject.bin',b'not-a-real-macro')
    return b.getvalue()
s=Client(4);onboard=Client(8);company=Client(3);admin=Client(1);coord=Client(2)
report_id=int(sql("SELECT report_assignment_id FROM legacy_report_migration_map WHERE legacy_report_id=3"))
onboard_req_id=int(sql("SELECT requirement_assignment_id FROM legacy_requirement_migration_map WHERE legacy_requirement_id=2"))
huge_req_id=int(sql("SELECT requirement_assignment_id FROM legacy_requirement_migration_map WHERE legacy_requirement_id=7"))
pdf=b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF'
# MIME spoof, empty upload and explicit no-file submission use normalized assignments.
req_id=int(sql("SELECT requirement_assignment_id FROM legacy_requirement_migration_map WHERE legacy_requirement_id=3"))
foreign_req_id=int(sql("SELECT requirement_assignment_id FROM legacy_requirement_migration_map WHERE legacy_requirement_id=2"))
for name,content in [('fake.pdf',b'<?php echo "bad"; ?>'),('fake.jpg',b'not an image'),('empty.pdf',b'')]:
    check(s.post('student/requirements.php',{'action':'upload','req_id':req_id},[('document',name,content)])[0]==422,'reject '+name)
check(sql(f"SELECT COUNT(*) FROM requirement_submissions WHERE requirement_assignment_id={req_id}")=='0','invalid files do not submit')
check(s.post('student/requirements.php',{'action':'upload','req_id':req_id})[0]==422,'no phantom submission')
check(s.post('student/requirements.php',{'action':'upload','req_id':foreign_req_id},[('document','valid.pdf',pdf)])[0]==404,'foreign assignment denied')
check(s.post('student/requirements.php',{'action':'upload','req_id':req_id},[('document','valid.pdf',pdf)])[0]==200,'valid PDF upload')
path=sql(f"""SELECT a.storage_key
FROM requirement_submissions rs
JOIN requirement_submission_attachments rsa ON rsa.requirement_submission_id=rs.id
JOIN attachments a ON a.id=rsa.attachment_id
WHERE rs.requirement_assignment_id={req_id}
ORDER BY rs.version_no DESC,a.id LIMIT 1""")
check(bool(re.search(r'_[0-9a-f]{32}\.pdf$',path)),'random file name')
check((PRIVATE/path).read_bytes()==pdf,'PDF stored')
submission_id=int(sql(f"SELECT id FROM requirement_submissions WHERE requirement_assignment_id={req_id} ORDER BY version_no DESC,id DESC LIMIT 1"))
sql(f"UPDATE requirement_submissions SET status='approved' WHERE id={submission_id}")
sql(f"UPDATE requirement_assignments SET status='closed' WHERE id={req_id}")
check(s.post('student/requirements.php',{'action':'upload','req_id':req_id},[('document','valid.pdf',pdf)])[0]==409,'approved upload preserved')
path_after=sql(f"""SELECT a.storage_key
FROM requirement_submissions rs
JOIN requirement_submission_attachments rsa ON rsa.requirement_submission_id=rs.id
JOIN attachments a ON a.id=rsa.attachment_id
WHERE rs.requirement_assignment_id={req_id}
ORDER BY rs.version_no DESC,a.id LIMIT 1""")
check(path_after==path,'approved file unchanged')
# Images normalized before storage; payload removed.
image=png()+b'<?php synthetic_tail ?>'
for who,role in [(s,'student'),(company,'company'),(admin,'admin'),(coord,'coordinator')]:
    check(who.post(role+'/profile.php',{'action':'upload_avatar'},[('avatar','picture.png',image)])[0]==200,role+' avatar accepted')
for uid in [1,2,3,4]:
    path=sql(f'SELECT avatar FROM users WHERE id={uid}')
    check(b'synthetic_tail' not in (ROOT/'uploads'/path).read_bytes(),'image payload removed '+str(uid))
check(company.post('company/certificate.php',{'action':'save_template'},[('logo','logo.svg',b'<svg xmlns="http://www.w3.org/2000/svg"/>')])[0]==422,'SVG rejected')
check(company.post('company/certificate.php',{'action':'save_template'},[('logo','logo.png',png())])[0]==200,'raster logo accepted')
# Office ZIP type matching and macro rejection.
for name,content in [('fake.docx',b'PKinvalid'),('macro.docx',office(macro=True)),('wrong.xlsx',office())]:
    who=admin if name.endswith('xlsx') else s
    route='admin/announcements.php' if name.endswith('xlsx') else 'student/reports.php'
    field='attachment' if name.endswith('xlsx') else 'report_file'
    data={'action':'post','title':'Bad','body':'Bad','target_role':'all'} if name.endswith('xlsx') else {'action':'submit_report','rep_id':report_id}
    check(who.post(route,data,[(field,name,content)])[0]==422,'reject Office '+name)
check(s.post('student/reports.php',{'action':'submit_report','rep_id':report_id},[('report_file','valid.docx',office())])[0]==200,'DOCX accepted')
check(sql(f"SELECT status FROM report_submissions WHERE report_assignment_id={report_id} ORDER BY version_no DESC,id DESC LIMIT 1")=='submitted','report submitted')
# Entire onboarding batch is validated before any page mutation.
before_onboard=sql(f"SELECT COUNT(*) FROM requirement_submissions WHERE requirement_assignment_id={onboard_req_id}")
check(onboard.post('student/onboarding.php',{'action':'upload_all'},[(f'documents[{onboard_req_id}]','valid.pdf',pdf),('documents[999]','bad.pdf',b'bad')])[0]==422,'bad batch blocked')
check(sql(f"SELECT COUNT(*) FROM requirement_submissions WHERE requirement_assignment_id={onboard_req_id}")==before_onboard,'batch no partial mutation')
check(onboard.post('student/onboarding.php',{'action':'upload_all'},[(f'documents[{onboard_req_id}]','valid.pdf',pdf)])[0]==200,'valid onboarding batch')
# Application file limits, journal proof and PHP body limit.
check(s.post('student/profile.php',{'action':'upload_avatar'},[('avatar','large.png',png()+b'x'*(5*1024*1024))])[0]==422,'5MB image limit')
journal={'action':'submit_journal','edit_id':1,'entry_date':'2026-09-08','activities':'Upload test','learnings':'Test','challenges':'Test','hours_rendered':8}
check(s.post('student/journal.php',journal,[('proof_image','proof.png',image)])[0]==200,'journal proof accepted')
path=sql("""SELECT a.storage_key
FROM journal_revisions jr
JOIN journal_revision_attachments jra ON jra.journal_revision_id=jr.id
JOIN attachments a ON a.id=jra.attachment_id
WHERE jr.journal_day_id=1
ORDER BY jr.revision_no DESC,a.id DESC LIMIT 1""")
check(b'synthetic_tail' not in (PRIVATE/path).read_bytes(),'journal proof normalized')
check(not (ROOT/'uploads'/path).exists(),'new journal proof stays outside public uploads')
check(s.post('student/requirements.php',{'action':'upload','req_id':huge_req_id},[('document','huge.pdf',pdf+b'x'*(11*1024*1024))])[0]==422,'PHP upload limit handled')
try:
    status,_=s.post('student/requirements.php',{'action':'upload','req_id':huge_req_id},[('document','huge.pdf',pdf+b'x'*(33*1024*1024))])
    check(status==413,'PHP post limit handled')
except urllib.error.URLError as error:
    check(isinstance(error.reason, ConnectionResetError),'oversized request rejected by server')
    check(s.get('login.php')[0]==200,'server remains healthy after oversized request')
print({'upload_checks_passed':count,'result':'PASS'})

"""After the regression/upload suites: verify copy-only migration on synthetic files."""
import os, json, subprocess
from pathlib import Path
if os.environ.get('OJTRACK_TEST_CONFIRM') != 'synthetic':
    raise SystemExit('Set OJTRACK_TEST_CONFIRM=synthetic.')
ROOT=Path(__file__).resolve().parents[1]
PRIVATE=Path(os.environ['OJTRACK_PRIVATE_UPLOAD_DIR'])
SOCKET=os.environ['OJTRACK_TEST_SOCKET']
checks=0
def check(value,label):
    global checks
    assert value,label
    checks+=1
def sql(q):
    return subprocess.check_output(['mariadb','--no-defaults','--socket='+SOCKET,'-uroot','ojtrack_test','-N','-B','-e',q],text=True).strip()
def run(mode):
    p=subprocess.run(['php','-d','mysqli.default_socket='+SOCKET,str(ROOT/'bin/copy-private-documents.php'),mode],text=True,capture_output=True)
    check(not p.stderr.strip(),'unexpected stderr: '+p.stderr)
    return p.returncode,json.loads(p.stdout)
source=ROOT/'uploads/requirements/test.txt'
destination=PRIVATE/'requirements/test.txt'
before=source.read_bytes()
db_before=sql("SELECT GROUP_CONCAT(CONCAT(id,':',COALESCE(file_path,'')) ORDER BY id) FROM ojt_requirements")
code,r=run('--dry-run')
check(code==0 and r['would_copy']==1,'dry run finds one remaining referenced legacy file')
check(not destination.exists(),'dry run writes nothing')
code,r=run('--copy')
check(code==0 and r['copied']==1,'copy writes referenced legacy file')
check(destination.read_bytes()==before and source.read_bytes()==before,'both copies match; source retained')
code,r=run('--copy')
check(code==0 and r['copied']==0 and r['verified_existing']==1,'repeat copy verifies existing destination')
try:
    destination.write_bytes(b'Synthetic conflict')
    code,r=run('--copy')
    check(code==1 and len(r['errors'])==1,'conflict is reported')
    check(destination.read_bytes()==b'Synthetic conflict','conflict is never overwritten')
    check(source.read_bytes()==before,'source unchanged after conflict')
finally:
    destination.write_bytes(before)
check(sql("SELECT GROUP_CONCAT(CONCAT(id,':',COALESCE(file_path,'')) ORDER BY id) FROM ojt_requirements")==db_before,'DB references unchanged')
print(json.dumps({'migration_checks_passed':checks,'result':'PASS'}))

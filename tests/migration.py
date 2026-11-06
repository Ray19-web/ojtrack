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
refs=[p for p in sql("""SELECT path FROM (
  SELECT file_path AS path FROM ojt_requirements WHERE file_path IS NOT NULL AND file_path!=''
  UNION SELECT file_path FROM reports WHERE file_path IS NOT NULL AND file_path!=''
  UNION SELECT proof_image FROM journal_entries WHERE proof_image IS NOT NULL AND proof_image!=''
) refs ORDER BY path""").splitlines() if p]
expected_copy=[]
for relative in refs:
    legacy=ROOT/'uploads'/relative
    private=PRIVATE/relative
    if legacy.is_file() and not private.exists():
        expected_copy.append(relative)
check('requirements/test.txt' in expected_copy,'test migration fixture included')
code,r=run('--dry-run')
check(code==0 and r['would_copy']==len(expected_copy),'dry run finds all remaining referenced legacy files')
check(all(not (PRIVATE/p).exists() for p in expected_copy),'dry run writes nothing')
code,r=run('--copy')
check(code==0 and r['copied']==len(expected_copy),'copy writes all referenced legacy files')
check(destination.read_bytes()==before and source.read_bytes()==before,'both copies match; source retained')
code,r=run('--copy')
check(code==0 and r['copied']==0 and r['verified_existing']>=len(expected_copy),'repeat copy verifies existing destinations')
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

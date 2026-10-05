<?php
define('OJTRACK', true);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login(['student','company','coordinator','admin']);

$user=current_user();
$requestedStudentId=isset($_GET['student']) ? (int)$_GET['student'] : 0;
$student=null;

if ($user['role']==='student') {
    $student=normalized_student_context_by_user((int)$user['id']);
} elseif ($requestedStudentId>0) {
    $candidate=normalized_student_context($requestedStudentId);
    if ($candidate) {
        if ($user['role']==='admin') {
            $student=$candidate;
        } elseif ($user['role']==='company') {
            $company=query_one("SELECT id FROM companies WHERE user_id=? LIMIT 1",[(int)$user['id']],'i');
            $certificate=normalized_certificate_for_student($requestedStudentId);
            if ($company && $certificate) {
                $allowed=query_one(
                    "SELECT c.id
                     FROM certificates c
                     JOIN placements p ON p.id=c.placement_id
                     WHERE c.id=? AND p.company_id=? LIMIT 1",
                    [(int)$certificate['id'],(int)$company['id']],
                    'ii'
                );
                if ($allowed) $student=$candidate;
            }
        } elseif ($user['role']==='coordinator') {
            $coord=query_one("SELECT id FROM coordinators WHERE user_id=? LIMIT 1",[(int)$user['id']],'i');
            $enrollment=normalized_enrollment_for_student($requestedStudentId);
            if ($coord && $enrollment && query_one(
                "SELECT id FROM enrollment_coordinators
                 WHERE ojt_enrollment_id=? AND coordinator_id=? AND ended_at IS NULL LIMIT 1",
                [(int)$enrollment['id'],(int)$coord['id']],
                'ii'
            )) $student=$candidate;
        }
    }
}

$certificate=$student ? normalized_certificate_for_student((int)$student['id']) : null;

if (!$certificate) {
    $available=[];
    if ($user['role']==='student') {
        $available=[];
    } elseif ($user['role']==='company') {
        $company=query_one("SELECT id FROM companies WHERE user_id=? LIMIT 1",[(int)$user['id']],'i');
        if ($company) {
            $available=query(
                "SELECT DISTINCT s.id,u.name,c.certificate_no,c.issued_at
                 FROM certificates c
                 JOIN placements pl ON pl.id=c.placement_id
                 JOIN ojt_enrollments oe ON oe.id=pl.ojt_enrollment_id
                 JOIN students s ON s.id=oe.student_id
                 JOIN users u ON u.id=s.user_id
                 WHERE pl.company_id=?
                 ORDER BY c.issued_at DESC",
                [(int)$company['id']],
                'i'
            ) ?: [];
        }
    } elseif ($user['role']==='coordinator') {
        $coord=query_one("SELECT id FROM coordinators WHERE user_id=? LIMIT 1",[(int)$user['id']],'i');
        if ($coord) {
            $available=query(
                "SELECT DISTINCT s.id,u.name,c.certificate_no,c.issued_at
                 FROM certificates c
                 JOIN placements pl ON pl.id=c.placement_id
                 JOIN ojt_enrollments oe ON oe.id=pl.ojt_enrollment_id
                 JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
                 JOIN students s ON s.id=oe.student_id
                 JOIN users u ON u.id=s.user_id
                 WHERE ec.coordinator_id=?
                 ORDER BY c.issued_at DESC",
                [(int)$coord['id']],
                'i'
            ) ?: [];
        }
    } else {
        $available=query(
            "SELECT DISTINCT s.id,u.name,c.certificate_no,c.issued_at
             FROM certificates c
             JOIN placements pl ON pl.id=c.placement_id
             JOIN ojt_enrollments oe ON oe.id=pl.ojt_enrollment_id
             JOIN students s ON s.id=oe.student_id
             JOIN users u ON u.id=s.user_id
             ORDER BY c.issued_at DESC
             LIMIT 100"
        ) ?: [];
    }

    $page_title='Certificates';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="page-heading">
      <div class="page-title">Issued Certificates</div>
      <div class="page-sub">Official certificate records are permanent after issuance.</div>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Student</th><th>Certificate No.</th><th>Issued</th><th>Action</th></tr></thead>
          <tbody>
          <?php if (!$available): ?>
            <tr><td colspan="4" class="text-center text-muted py-6">
              <?= $user['role']==='student'
                  ? 'Your official certificate has not been issued yet. Completion alone does not create an issuance record.'
                  : 'No issued certificates are available in your scope yet.' ?>
            </td></tr>
          <?php else: ?>
            <?php foreach ($available as $row): ?>
              <tr>
                <td><strong><?= e($row['name']) ?></strong></td>
                <td class="td-mono"><?= e($row['certificate_no']) ?></td>
                <td><?= format_date($row['issued_at']) ?></td>
                <td><a class="btn btn-primary btn-sm" href="/ojtrack/certificate.php?student=<?= (int)$row['id'] ?>">View / Reprint</a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$snapshot=normalized_certificate_snapshot($certificate);
if (!$snapshot) request_error(500,'The issued certificate snapshot is unavailable.');

$template=$snapshot['template'] ?? [];
$studentName=$snapshot['student_name'] ?? ($student['name'] ?? 'Student');
$renderedBody=$snapshot['rendered_body'] ?? '';
$certificateNo=$certificate['certificate_no'];
$issuedAt=$certificate['issued_at'];

$page_title='Certificate of Recognition';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Certificate of Recognition</div>
    <div class="page-sub">Permanent issued record · <?= e($certificateNo) ?></div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="/ojtrack/certificate.php" class="btn btn-secondary btn-sm">← Issued Certificates</a>
    <button class="btn btn-secondary" onclick="window.print()">🖨 Reprint</button>
    <button class="btn btn-primary" onclick="downloadCert()">⬇ Download</button>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadCert() {
  var el=document.getElementById('certificate');
  if (!el || typeof html2pdf==='undefined') {
    alert('PDF library not loaded. Please check your internet connection.');
    return;
  }
  html2pdf().set({
    margin:8,
    filename:'Certificate_<?= preg_replace('/[^A-Za-z0-9]+/','_',$certificateNo) ?>.pdf',
    image:{type:'jpeg',quality:0.98},
    html2canvas:{scale:2,useCORS:true,windowWidth:1200},
    jsPDF:{unit:'mm',format:'a4',orientation:'landscape'},
    pagebreak:{mode:['css','legacy']}
  }).from(el).save();
}
</script>

<div id="certificate" style="background:#fff;border:8px double #103b78;padding:56px 48px;text-align:center;font-family:Georgia,serif;max-width:1100px;min-height:560px;margin:0 auto">
  <?php if (!empty($template['logo'])): ?>
    <img src="/ojtrack/uploads/<?= e($template['logo']) ?>" alt="Logo" style="height:72px;margin-bottom:12px"><br>
  <?php endif; ?>
  <div style="font-size:22px;font-weight:700;color:#103b78"><?= e($template['org_name'] ?? ($snapshot['company_name'] ?? '')) ?></div>
  <?php if (!empty($template['org_address'])): ?>
    <div style="font-size:13px;color:#666;margin-bottom:24px"><?= e($template['org_address']) ?></div>
  <?php endif; ?>
  <div style="font-size:30px;letter-spacing:2px;margin:28px 0 8px;color:#103b78;text-transform:uppercase"><?= e($template['cert_title'] ?? 'Certificate of Recognition') ?></div>
  <div style="font-size:11px;color:#777;margin-bottom:10px">Certificate No. <?= e($certificateNo) ?></div>
  <div style="font-size:13px;color:#888;letter-spacing:3px;text-transform:uppercase;margin-bottom:28px">This certifies that</div>
  <div style="font-size:26px;font-weight:700;border-bottom:1px solid #999;display:inline-block;padding:0 24px 4px;margin-bottom:20px"><?= e($studentName) ?></div>
  <div style="font-size:14px;line-height:1.7;max-width:640px;margin:0 auto 36px"><?= nl2br(e($renderedBody)) ?></div>
  <?php if (!empty($template['footer_text'])): ?>
    <div style="font-size:12px;color:#777;font-style:italic;margin-bottom:40px"><?= e($template['footer_text']) ?></div>
  <?php endif; ?>
  <div style="display:flex;justify-content:space-between;max-width:640px;margin:0 auto">
    <div style="text-align:center">
      <div style="border-top:1px solid #333;width:200px;margin-bottom:6px"></div>
      <div style="font-size:14px;font-weight:700"><?= e($template['signatory_name'] ?? '') ?></div>
      <div style="font-size:12px;color:#666"><?= e($template['signatory_title'] ?? '') ?></div>
    </div>
    <div style="text-align:center">
      <div style="border-top:1px solid #333;width:200px;margin-bottom:6px"></div>
      <div style="font-size:14px;font-weight:700"><?= date('F d, Y',strtotime($issuedAt)) ?></div>
      <div style="font-size:12px;color:#666">Date Issued</div>
    </div>
  </div>
</div>

<style>
@page { size:A4 landscape; margin:8mm; }
@media print {
  body * { visibility:hidden; }
  #certificate,#certificate * { visibility:visible; }
  #certificate {
    position:absolute; left:0; top:0;
    width:281mm; max-width:281mm; min-height:190mm;
    box-sizing:border-box; padding:24mm 20mm;
    border:8px double #103b78; font-size:13px; overflow:hidden;
  }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

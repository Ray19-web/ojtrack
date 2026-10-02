<?php
define('OJTRACK', true);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login(['student', 'company', 'coordinator', 'admin']);

$user = current_user();
$uq = query_one("SELECT * FROM users WHERE id=?", [$user['id']], 'i');

$student = null;
if ($user['role'] === 'student') {
    $student = query_one("SELECT * FROM students WHERE user_id=?", [$user['id']], 'i');
} else {
    $sid = isset($_GET['student']) ? (int)$_GET['student'] : 0;
    if ($sid > 0) {
        if ($user['role'] === 'company') {
            $co = query_one("SELECT id FROM companies WHERE user_id=?", [$user['id']], 'i');
            $student = query_one("SELECT * FROM students WHERE id=? AND company_id=?", [$sid, $co['id'] ?? 0], 'ii');
        } elseif ($user['role'] === 'coordinator') {
            $co = query_one("SELECT id FROM coordinators WHERE user_id=?", [$user['id']], 'i');
            $student = query_one("SELECT * FROM students WHERE id=? AND coordinator_id=?", [$sid, $co['id'] ?? 0], 'ii');
        } else {
            $student = query_one("SELECT * FROM students WHERE id=?", [$sid], 'i');
        }
    }
}

if (!$student || $student['ojt_status'] !== 'completed') {
    // Build a friendly list of available certificates instead of a dead end
    $available = [];
    if ($user['role'] === 'company') {
        $co = query_one("SELECT id FROM companies WHERE user_id=?", [$user['id']], 'i');
        $available = query("SELECT s.*, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.company_id=? AND s.ojt_status='completed'", [$co['id'] ?? 0], 'i');
    } elseif ($user['role'] === 'coordinator') {
        $co = query_one("SELECT id FROM coordinators WHERE user_id=?", [$user['id']], 'i');
        $available = query("SELECT s.*, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.coordinator_id=? AND s.ojt_status='completed'", [$co['id'] ?? 0], 'i');
    } elseif ($user['role'] === 'admin') {
        $available = query("SELECT s.*, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.ojt_status='completed' LIMIT 100");
    }

    $page_title = 'Certificates';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="page-heading">
      <div class="page-title">Certificates of Recognition</div>
      <div class="page-sub">Completion certificates ready for download</div>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Student</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (empty($available)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">No completed certificates yet.</td></tr>
            <?php else: ?>
              <?php foreach ($available as $a): ?>
              <tr>
                <td><strong><?= e($a['name']) ?></strong></td>
                <td><?= status_badge($a['ojt_status']) ?></td>
                <td><a href="/ojtrack/certificate.php?student=<?= $a['id'] ?>" class="btn btn-primary btn-sm">View &amp; Download</a></td>
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

$student = query_one(
    "SELECT s.*, co.company_name, co.cert_template, co.location, co.supervisor_name, u.name AS student_name, co_u.name AS supervisor_user_name
     FROM students s
     LEFT JOIN companies co ON co.id=s.company_id
     LEFT JOIN users co_u ON co_u.id=co.user_id
     JOIN users u ON u.id=s.user_id
     WHERE s.id=?",
    [$student['id']], 'i'
);

$defaults = [
    'logo'            => '',
    'org_name'        => $student['company_name'] ?? '',
    'org_address'     => $student['location'] ?? '',
    'cert_title'      => 'Certificate of Recognition',
    'body_text'       => "This is to certify that {student_name} of {program} has successfully completed the required OJT training hours at {company_name}, with a total of {rendered_hours} rendered hours.",
    'signatory_name'  => $student['supervisor_name'] ?: ($student['supervisor_user_name'] ?? ''),
    'signatory_title' => 'Training Supervisor',
    'footer_text'     => 'In recognition of dedication, commitment, and performance during the On-the-Job Training program.',
];
$template = $defaults;
if (!empty($student['cert_template'])) {
    $saved = json_decode($student['cert_template'], true);
    if (is_array($saved)) {
        $template = array_merge($defaults, $saved);
    }
}

$coord_name = null;
if (!empty($student['coordinator_id'])) {
    $coord_name = query_one("SELECT u.name FROM coordinators c JOIN users u ON u.id=c.user_id WHERE c.id=?", [$student['coordinator_id']], 'i');
}

$replace = [
    '{student_name}' => $student['student_name'],
    '{program}'      => $student['program'] ?? '',
    '{company_name}' => $student['company_name'] ?? '',
    '{rendered_hours}' => number_format($student['rendered_hours'] ?? 0, 0) . ' hours',
    '{required_hours}' => number_format($student['required_hours'] ?? 0, 0) . ' hours',
    '{coordinator_name}' => $coord_name['name'] ?? '',
    '{date}'         => date('F d, Y'),
];
$rendered_body = strtr($template['body_text'], $replace);

$page_title = 'Certificate of Recognition';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Certificate of Recognition</div>
    <div class="page-sub">Print or save as PDF</div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="/ojtrack/certificate.php" class="btn btn-secondary btn-sm">← All Certificates</a>
    <button class="btn btn-secondary" onclick="window.print()">🖨 Print</button>
    <button class="btn btn-primary" onclick="downloadCert()">⬇ Download</button>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadCert() {
  var el = document.getElementById('certificate');
  if (!el || typeof html2pdf === 'undefined') { alert('PDF library not loaded. Please check your internet connection.'); return; }
  var opt = {
    margin: 8,
    filename: 'Certificate_of_Recognition_<?= preg_replace('/[^A-Za-z0-9]+/', '_', $student['student_name'] ?? 'student') ?>.pdf',
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2, useCORS: true, windowWidth: 1200 },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
    pagebreak: { mode: ['css', 'legacy'] }
  };
  html2pdf().set(opt).from(el).save();
}
</script>

<div id="certificate" style="background:#fff;border:8px double #103b78;padding:56px 48px;text-align:center;font-family:Georgia,serif;max-width:1100px;min-height:560px;margin:0 auto">
  <?php if (!empty($template['logo'])): ?>
    <img src="/ojtrack/uploads/<?= e($template['logo']) ?>" alt="Logo" style="height:72px;margin-bottom:12px"><br>
  <?php endif; ?>
  <div style="font-size:22px;font-weight:700;color:#103b78"><?= e($template['org_name']) ?></div>
  <?php if ($template['org_address']): ?><div style="font-size:13px;color:#666;margin-bottom:24px"><?= e($template['org_address']) ?></div><?php endif; ?>
  <div style="font-size:30px;letter-spacing:2px;margin:28px 0 8px;color:#103b78;text-transform:uppercase"><?= e($template['cert_title']) ?></div>
  <div style="font-size:13px;color:#888;letter-spacing:3px;text-transform:uppercase;margin-bottom:28px">This certifies that</div>
  <div style="font-size:26px;font-weight:700;border-bottom:1px solid #999;display:inline-block;padding:0 24px 4x;margin-bottom:20px"><?= e($student['student_name']) ?></div>
  <div style="font-size:14px;line-height:1.7;max-width:640px;margin:0 auto 36px"><?= nl2br(e($rendered_body)) ?></div>
  <?php if ($template['footer_text']): ?><div style="font-size:12px;color:#777;font-style:italic;margin-bottom:40px"><?= e($template['footer_text']) ?></div><?php endif; ?>
  <div style="display:flex;justify-content:space-between;max-width:640px;margin:0 auto">
    <div style="text-align:center">
      <div style="border-top:1px solid #333;width:200px;margin-bottom:6px"></div>
      <div style="font-size:14px;font-weight:700"><?= e($template['signatory_name']) ?></div>
      <div style="font-size:12px;color:#666"><?= e($template['signatory_title']) ?></div>
    </div>
    <div style="text-align:center">
      <div style="border-top:1px solid #333;width:200px;margin-bottom:6px"></div>
      <div style="font-size:14px;font-weight:700"><?= date('F d, Y') ?></div>
      <div style="font-size:12px;color:#666">Date Issued</div>
    </div>
  </div>
</div>

<style>
@page { size: A4 landscape; margin: 8mm; }
@media print {
  body * { visibility: hidden; }
  #certificate, #certificate * { visibility: visible; }
  #certificate {
    position: absolute; left: 0; top: 0;
    width: 281mm; max-width: 281mm; min-height: 190mm;
    box-sizing: border-box;
    padding: 24mm 20mm;
    border: 8px double #103b78;
    font-size: 13px;
    overflow: hidden;
  }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

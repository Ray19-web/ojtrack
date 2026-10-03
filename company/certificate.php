<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

$user    = current_user();
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$user['id']], 'i');

$success = ''; $error = '';

$defaults = [
    'logo'            => '',
    'org_name'        => $company['company_name'] ?? '',
    'org_address'     => $company['location'] ?? '',
    'cert_title'      => 'Certificate of Recognition',
    'body_text'       => "This is to certify that {student_name} of {program} has successfully completed the required OJT training hours at {company_name}, with a total of {rendered_hours} rendered hours.",
    'signatory_name'  => $company['supervisor_name'] ?? '',
    'signatory_title' => 'Training Supervisor',
    'footer_text'     => 'In recognition of dedication, commitment, and performance during the On-the-Job Training program.',
];
$template = $defaults;
if (!empty($company['cert_template'])) {
    $saved = json_decode($company['cert_template'], true);
    if (is_array($saved)) {
        $template = array_merge($defaults, $saved);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_template') {
    $t = [
        'logo'            => $template['logo'],
        'org_name'        => trim($_POST['org_name'] ?? ''),
        'org_address'     => trim($_POST['org_address'] ?? ''),
        'cert_title'      => trim($_POST['cert_title'] ?? 'Certificate of Recognition'),
        'body_text'       => trim($_POST['body_text'] ?? ''),
        'signatory_name'  => trim($_POST['signatory_name'] ?? ''),
        'signatory_title' => trim($_POST['signatory_title'] ?? ''),
        'footer_text'     => trim($_POST['footer_text'] ?? ''),
    ];

    if (!empty($_FILES['logo']['name']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $error = 'Logo must be JPG, PNG, GIF, or WEBP.';
        } else {
            $dest_dir = __DIR__ . '/../uploads/cert_logos/';
            if (!is_dir($dest_dir)) { mkdir($dest_dir, 0755, true); }
            $filename = 'certlogo_' . $company['id'] . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest_dir . $filename)) {
                $t['logo'] = 'cert_logos/' . $filename;
            } else {
                $error = 'Logo upload failed.';
            }
        }
    }

    if (!$error) {
        query("UPDATE companies SET cert_template=? WHERE id=?", [json_encode($t), $company['id']], 'si');
        $template = $t;
        log_activity($user['id'], 'Certificate Template Updated', '');
        $success = 'Certificate template saved. It will be used for all generated certificates.';
    }
}

$students = query(
    "SELECT s.*, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.company_id=? AND s.ojt_status IN ('ongoing','completed') ORDER BY u.name",
    [$company['id']], 'i'
) ?: [];

$sel_id = isset($_GET['student']) ? (int)$_GET['student'] : ($students[0]['id'] ?? 0);
$selected = null;
foreach ($students as $s) { if ($s['id'] == $sel_id) { $selected = $s; break; } }

$rendered_body = '';
if ($selected) {
    $coord_name = query_one(
        "SELECT u.name FROM coordinators c JOIN users u ON u.id=c.user_id WHERE c.id=?",
        [$selected['coordinator_id'] ?? 0], 'i'
    );
    $replace = [
        '{student_name}' => $selected['name'],
        '{program}'      => $selected['program'] ?? '',
        '{company_name}' => $company['company_name'] ?? '',
        '{rendered_hours}' => number_format($selected['rendered_hours'] ?? 0, 0) . ' hours',
        '{required_hours}' => number_format($selected['required_hours'] ?? 0, 0) . ' hours',
        '{coordinator_name}' => $coord_name['name'] ?? '',
        '{date}'         => date('F d, Y'),
    ];
    $rendered_body = strtr($template['body_text'], $replace);
}

$page_title = 'Certificates';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
  <div class="page-title">Certificate of Recognition</div>
  <div class="page-sub">Generate printable certificates for your trainees using your editable company template</div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="grid" style="grid-template-columns:1fr">
  <div>
    <div class="card card-body mb-4 py-3">
      <form method="GET" class="flex-items-center gap-3" style="flex-wrap:wrap">
        <label class="form-label mb-0">Generate for:</label>
        <select name="student" class="form-control" style="width:280px" onchange="this.form.submit()">
          <?php foreach ($students as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $s['id']==$sel_id?'selected':'' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">Print / Save as PDF</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="downloadCert()">⬇ Download</button>
        <span style="flex:1"></span>
        <button type="button" onclick="openModal('templateModal')" style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border:none;border-radius:10px;background:linear-gradient(135deg,#103b78,#1d4ed8);color:#fff;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 4px 12px rgba(16,59,120,.25);transition:transform .15s ease, box-shadow .15s ease" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 18px rgba(16,59,120,.3)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 12px rgba(16,59,120,.25)'">✏️ Editable Template</button>
      </form>
    </div>
<div class="modal-overlay" id="templateModal">
  <div class="modal modal-lg" style="max-height:90vh;overflow-y:auto">
    <div class="modal-title">Editable Template</div>
    <p class="modal-sub">Saved to your company account. Placeholders: {student_name}, {program}, {company_name}, {rendered_hours}, {coordinator_name}, {date}</p>
    <div style="max-height:60vh;overflow-y:auto;padding-right:4px">
      <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
        <input type="hidden" name="action" value="save_template">
        <div class="form-group">
          <label class="form-label">Company Logo</label>
          <?php if (!empty($template['logo'])): ?>
            <img src="/ojtrack/uploads/<?= e($template['logo']) ?>" alt="Logo" style="height:48px;margin-bottom:6px;display:block">
          <?php endif; ?>
          <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
        </div>
        <div class="form-group">
          <label class="form-label">Organization Name</label>
          <input type="text" name="org_name" class="form-control" value="<?= e($template['org_name']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Address / Subtitle</label>
          <input type="text" name="org_address" class="form-control" value="<?= e($template['org_address']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Certificate Title</label>
          <input type="text" name="cert_title" class="form-control" value="<?= e($template['cert_title']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Body Text</label>
          <textarea name="body_text" class="form-control" rows="4"><?= e($template['body_text']) ?></textarea>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Signatory Name</label>
            <input type="text" name="signatory_name" class="form-control" value="<?= e($template['signatory_name']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Signatory Title</label>
            <input type="text" name="signatory_title" class="form-control" value="<?= e($template['signatory_title']) ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Footer Text</label>
          <textarea name="footer_text" class="form-control" rows="2"><?= e($template['footer_text']) ?></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeModal('templateModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Template</button>
        </div>
      </form>
    </div>
  </div>
</div>

    <?php if (!$selected): ?>
      <div class="card card-body"><div class="empty-state"><p>No trainees available for certificates.</p></div></div>
    <?php else: ?>
    <div id="certificate" style="background:#fff;border:8px double #103b78;padding:56px 48px;text-align:center;font-family:Georgia,serif;max-width:1100px;min-height:560px;margin:0 auto">
      <?php if (!empty($template['logo'])): ?>
        <img src="/ojtrack/uploads/<?= e($template['logo']) ?>" alt="Logo" style="height:72px;margin-bottom:12px"><br>
      <?php endif; ?>
      <div style="font-size:22px;font-weight:700;color:#103b78"><?= e($template['org_name']) ?></div>
      <?php if ($template['org_address']): ?><div style="font-size:13px;color:#666;margin-bottom:24px"><?= e($template['org_address']) ?></div><?php endif; ?>
      <div style="font-size:30px;letter-spacing:2px;margin:28px 0 8px;color:#103b78;text-transform:uppercase"><?= e($template['cert_title']) ?></div>
      <div style="font-size:13px;color:#888;letter-spacing:3px;text-transform:uppercase;margin-bottom:28px">This certifies that</div>
      <div style="font-size:26px;font-weight:700;border-bottom:1px solid #999;display:inline-block;padding:0 24px 4px;margin-bottom:20px"><?= e($selected['name']) ?></div>
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
    <?php endif; ?>
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadCert() {
  var el = document.getElementById('certificate');
  if (!el || typeof html2pdf === 'undefined') { alert('PDF library not loaded. Please check your internet connection.'); return; }
  var opt = {
    margin: 8,
    filename: 'Certificate_of_Recognition.pdf',
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2, useCORS: true, windowWidth: 1200 },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
    pagebreak: { mode: ['css', 'legacy'] }
  };
  html2pdf().set(opt).from(el).save();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

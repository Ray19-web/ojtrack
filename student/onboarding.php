<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user = current_user();
$uid  = (int)$user['id'];

$student = query_one(
    "SELECT s.*, u.name, u.email, p.code AS program_code, p.name AS program_name,
            cu.name AS coordinator_name
     FROM students s
     JOIN users u ON u.id=s.user_id
     LEFT JOIN programs p ON p.id=s.program_id
     LEFT JOIN coordinators c ON c.id=s.coordinator_id
     LEFT JOIN users cu ON cu.id=c.user_id
     WHERE s.user_id=?",
    [$uid],
    'i'
);

if (!$student) {
    redirect('login.php?error=student_profile_missing');
}

$student_id = (int)$student['id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {

    $req_id = (int)($_POST['req_id'] ?? 0);

    $req = query_one(
        "SELECT r.*, s.user_id
         FROM ojt_requirements r
         JOIN students s ON s.id=r.student_id
         WHERE r.id=? AND r.student_id=?",
        [$req_id, $student_id],
        'ii'
    );

    if (!$req) {

        $error = 'The selected requirement could not be found.';

    } elseif ($req['status'] === 'approved') {

        $error = 'This document has already been approved.';

    } elseif (
        !isset($_FILES['document']) ||
        $_FILES['document']['error'] !== UPLOAD_ERR_OK
    ) {

        $error = 'Please choose a document to upload.';

    } elseif ((int)$_FILES['document']['size'] > 10 * 1024 * 1024) {

        $error = 'The file is too large. Maximum size is 10MB.';

    } else {

        $orig_name = $_FILES['document']['name'];
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        $allowed = [
            'pdf',
            'jpg',
            'jpeg',
            'png',
            'doc',
            'docx'
        ];

        if (!in_array($ext, $allowed, true)) {

            $error = 'Invalid file format. Please upload PDF, JPG, PNG, DOC, or DOCX.';

        } else {

            $token = bin2hex(random_bytes(16));

            $new_filename =
                'req_' .
                $student_id .
                '_' .
                $req_id .
                '_' .
                $token .
                '.' .
                $ext;

            if (
                !store_private_upload(
                    $_FILES['document']['tmp_name'],
                    'requirements', $new_filename
                )
            ) {

                $error = 'Failed to save the uploaded file. Please try again.';

            } else {

                $file_path = 'requirements/' . $new_filename;

                query(
                    "UPDATE ojt_requirements
                     SET status='pending',
                         submitted_at=NOW(),
                         file_path=?,
                         remarks='Submitted — awaiting coordinator review',
                         reviewed_by=NULL,
                         reviewed_at=NULL
                     WHERE id=? AND student_id=?",
                    [
                        $file_path,
                        $req_id,
                        $student_id
                    ],
                    'sii'
                );

                if (!empty($student['coordinator_id'])) {

                    $coord_user = query_one(
                        "SELECT user_id
                         FROM coordinators
                         WHERE id=?",
                        [$student['coordinator_id']],
                        'i'
                    );

                    if ($coord_user) {

                        create_notification(
                            $coord_user['user_id'],
                            "{$user['name']} submitted an OJT requirement for review.",
                            'info',
                            '/ojtrack/coordinator/requirements.php'
                        );
                    }
                }

                log_activity(
                    $uid,
                    'Requirement Submitted',
                    "Requirement ID $req_id from onboarding"
                );

                $success =
                    "“{$req['document_name']}” was submitted and is now awaiting coordinator review.";
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'upload_all'
) {

    $files = $_FILES['documents'] ?? [];

    $submitted_count = 0;

    $submitted_names = [];

    $file_errors = [];

    if (!empty($files['name']) && is_array($files['name'])) {

        foreach ($files['name'] as $req_id => $orig_name) {

            $req_id = (int)$req_id;

            $err_code =
                $files['error'][$req_id] ?? UPLOAD_ERR_NO_FILE;

            if ($err_code === UPLOAD_ERR_NO_FILE || $orig_name === '') {

                // Skip empty file inputs — only submit requirements
                // that actually have a document attached.
                continue;

            }

            $req = query_one(
                "SELECT r.*, s.user_id
                 FROM ojt_requirements r
                 JOIN students s ON s.id=r.student_id
                 WHERE r.id=? AND r.student_id=?",
                [$req_id, $student_id],
                'ii'
            );

            if (!$req) {

                $file_errors[] =
                    "Requirement ID $req_id could not be found.";

                continue;

            }

            if ($req['status'] === 'approved') {

                continue;

            }

            if ($err_code !== UPLOAD_ERR_OK) {

                $file_errors[] =
                    "“{$req['document_name']}” could not be uploaded.";

                continue;

            }

            if ((int)$files['size'][$req_id] > 10 * 1024 * 1024) {

                $file_errors[] =
                    "“{$req['document_name']}” is too large. Maximum size is 10MB.";

                continue;

            }

            $ext = strtolower(
                pathinfo($orig_name, PATHINFO_EXTENSION)
            );

            $allowed = [
                'pdf',
                'jpg',
                'jpeg',
                'png',
                'doc',
                'docx'
            ];

            if (!in_array($ext, $allowed, true)) {

                $file_errors[] =
                    "“{$req['document_name']}” has an invalid format.";

                continue;

            }

            $token = bin2hex(random_bytes(16));

            $new_filename =
                'req_' .
                $student_id .
                '_' .
                $req_id .
                '_' .
                $token .
                '.' .
                $ext;

            if (
                !store_private_upload(
                    $files['tmp_name'][$req_id],
                    'requirements', $new_filename
                )
            ) {

                $file_errors[] =
                    "Failed to save “{$req['document_name']}”.";

                continue;

            }

            $file_path = 'requirements/' . $new_filename;

            query(
                "UPDATE ojt_requirements
                 SET status='pending',
                     submitted_at=NOW(),
                     file_path=?,
                     remarks='Submitted — awaiting coordinator review',
                     reviewed_by=NULL,
                     reviewed_at=NULL
                 WHERE id=? AND student_id=?",
                [
                    $file_path,
                    $req_id,
                    $student_id
                ],
                'sii'
            );

            log_activity(
                $uid,
                'Requirement Submitted',
                "Requirement ID $req_id from onboarding"
            );

            $submitted_count++;

            $submitted_names[] = $req['document_name'];

        }

    }

    if ($submitted_count > 0) {

        if (!empty($student['coordinator_id'])) {

            $coord_user = query_one(
                "SELECT user_id
                 FROM coordinators
                 WHERE id=?",
                [$student['coordinator_id']],
                'i'
            );

            if ($coord_user) {

                create_notification(
                    $coord_user['user_id'],
                    "{$user['name']} submitted $submitted_count OJT requirement" .
                        ($submitted_count === 1 ? '' : 's') .
                        " for review.",
                    'info',
                    '/ojtrack/coordinator/requirements.php'
                );

            }

        }

        $success =
            "Submitted: " .
            implode(', ', $submitted_names) .
            ". Awaiting coordinator review.";

    } elseif (empty($file_errors)) {

        $error =
            'Please attach at least one document before submitting.';

    }

    if (!empty($file_errors)) {

        $error = trim($error . ' ' . implode(' ', $file_errors));

    }

}

$requirements = query(
    "SELECT *
     FROM ojt_requirements
     WHERE student_id=?
     ORDER BY
       CASE status
         WHEN 'rejected' THEN 0
         WHEN 'pending' THEN 1
         WHEN 'approved' THEN 2
         ELSE 3
       END,
       deadline ASC,
       id ASC",
    [$student_id],
    'i'
) ?: [];

$total = count($requirements);

$submitted = count(
    array_filter(
        $requirements,
        fn($r) => !empty($r['submitted_at']) && $r['status'] !== 'rejected'
    )
);

$approved = count(
    array_filter(
        $requirements,
        fn($r) => $r['status'] === 'approved'
    )
);

$rejected = count(
    array_filter(
        $requirements,
        fn($r) => $r['status'] === 'rejected'
    )
);

$pending_review = count(
    array_filter(
        $requirements,
        fn($r) =>
            $r['status'] === 'pending' &&
            !empty($r['submitted_at'])
    )
);

$ready = $total > 0 && $approved === $total;

if ($ready) {

    student_onboarding_complete($student_id);

    $student['onboarding_completed_at'] =
        date('Y-m-d H:i:s');
}

$details_complete =
    !empty($student['student_id_no']) &&
    (
        !empty($student['program_id']) ||
        !empty($student['program'])
    );

$documents_complete =
    $total > 0 &&
    $submitted === $total;

$current_step =
    $ready
        ? 4
        : (
            $pending_review > 0 ||
            $documents_complete
                ? 3
                : 2
        );
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Student Activation — OJTRACK</title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="/ojtrack/assets/css/style.css?v=20261002a"
    >

    <style>

        body.ojt-onboarding {
            min-height: 100vh;
            margin: 0;

            background:
                radial-gradient(
                    circle at 10% 10%,
                    rgba(37,99,235,.08),
                    transparent 32%
                ),
                radial-gradient(
                    circle at 90% 90%,
                    rgba(16,185,129,.07),
                    transparent 30%
                ),
                #f4f7fb;

            color: #172033;
            font-family: Inter, sans-serif;
        }

        .onboarding-shell {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
            padding: 34px 0 50px;
        }

        /* =========================================
           BRAND / HEADER
        ========================================= */

        .onboarding-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            width: 100%;
        }

        .onboarding-brand img {
            width: 46px;
            height: 46px;
            object-fit: contain;
            border-radius: 12px;
            flex-shrink: 0;
        }

        .onboarding-brand > div {
            min-width: 0;
        }

        .onboarding-brand strong {
            font: 700 20px Outfit, sans-serif;
            letter-spacing: .2px;
        }

        .onboarding-brand span {
            display: block;
            font-size: 11px;
            color: #7b8798;
            margin-top: 2px;
        }

        /* THIS PUSHES SIGN OUT TO THE RIGHT */
        .onboarding-brand .signout {
            margin-left: auto;

            font-size: 16px;
            font-weight: 700;

            color: #cf2e2eff;
            text-decoration: none;

            white-space: nowrap;

            padding: 8px 4px;
        }

        .onboarding-brand .signout:hover {
            color: #1764bb;
            text-decoration: underline;
        }

        /* =========================================
           MAIN CARD
        ========================================= */
                  

        .onboarding-head {
            padding: 30px 34px 26px;
            border-bottom: 1px solid #edf1f5;
        }

        .eyebrow {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #2563eb;
            margin-bottom: 7px;
        }

        .onboarding-title {
            font: 700 28px Outfit, sans-serif;
            margin: 0 0 7px;
        }

        .onboarding-sub {
            margin: 0;
            color: #697589;
            font-size: 14px;
            line-height: 1.6;
            max-width: 760px;
        }

        /* =========================================
           STEPPER
        ========================================= */

        .stepper {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            padding: 26px 34px 30px;
        }

        .step {
            position: relative;
            min-width: 0;
        }

        .step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 18px;
            left: calc(50% + 20px);
            right: calc(-50% + 20px);
            height: 2px;
            background: #dfe6ef;
        }

        .step.complete:not(:last-child)::after {
            background: #19b889;
        }

        .step.current:not(:last-child)::after {
            background:
                linear-gradient(
                    90deg,
                    #19b889 0 28%,
                    #dfe6ef 28%
                );
        }

        .step-dot {
            position: relative;
            z-index: 2;

            width: 36px;
            height: 36px;

            margin: 0 auto 9px;

            border-radius: 50%;

            display: flex;
            align-items: center;
            justify-content: center;

            background: #fff;
            border: 2px solid #dfe6ef;

            color: #9aa5b4;
            font-size: 13px;
            font-weight: 800;
        }

        .step.complete .step-dot {
            background: #20bc8e;
            border-color: #20bc8e;
            color: #fff;
        }

        .step.current .step-dot {
            background: #fff;
            border-color: #2b82f6;
            color: #1764bb;
            box-shadow: 0 0 0 5px #e7f1ff;
        }

        .step-label {
            text-align: center;
            font-size: 12px;
            font-weight: 800;
            color: #374151;
        }

        .step-state {
            text-align: center;
            margin-top: 5px;
            font-size: 10px;
            color: #9aa5b4;
        }

        .step.complete .step-state {
            color: #16a879;
        }

        .step.current .step-state {
            color: #1764bb;
        }

        /* =========================================
           BODY
        ========================================= */

        .onboarding-body {
            padding: 30px 34px;
        }

        .account-summary {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
        }

        .summary-item {
            border: 1px solid #e7edf4;
            border-radius: 14px;
            padding: 14px 16px;
            background: #fbfcfe;
        }

        .summary-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #8a95a5;
            font-weight: 800;
        }

        .summary-value {
            font-size: 14px;
            font-weight: 700;
            margin-top: 5px;
        }

        .section-title {
            font: 700 18px Outfit, sans-serif;
            margin: 0;
        }

        .section-sub {
            font-size: 13px;
            color: #748095;
            margin: 4px 0 18px;
        }

        /* =========================================
           NOTICES
        ========================================= */

        .notice {
            border-radius: 13px;
            padding: 13px 15px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.5;
        }

        .notice.info {
            background: #eef6ff;
            border: 1px solid #cfe4ff;
            color: #24547f;
        }

        .notice.success {
            background: #ecfbf5;
            border: 1px solid #bdeedc;
            color: #166c51;
        }

        .notice.warn {
            background: #fff8e8;
            border: 1px solid #f6dfaa;
            color: #8a5b00;
        }

        .notice.error {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #9f1239;
        }

        /* =========================================
           REQUIREMENTS
        ========================================= */

        .requirements {
            display: grid;
            gap: 12px;
        }

        .requirement {
            border: 1px solid #e5ebf2;
            border-radius: 16px;
            padding: 17px 18px;

            display: grid;
            grid-template-columns: minmax(0,1fr) auto;

            gap: 18px;
            align-items: center;
        }

        .requirement:hover {
            border-color: #d4deea;
        }

        .req-name {
            font-weight: 800;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .req-meta {
            font-size: 11px;
            color: #7c8798;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .req-remarks {
            font-size: 12px;
            color: #6b7280;
            margin-top: 8px;
            max-width: 700px;
        }

        .req-remarks.rejected {
            color: #b42318;
            font-weight: 600;
        }

        .req-actions {
            display: flex;
            align-items: center;
            gap: 9px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        /* =========================================
           STATUS
        ========================================= */

        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;

            padding: 6px 9px;

            border-radius: 999px;

            font-size: 10px;
            font-weight: 800;

            white-space: nowrap;
        }

        .status.approved {
            background: #e9fbf3;
            color: #14825e;
        }

        .status.pending {
            background: #edf6ff;
            color: #2169b2;
        }

        .status.rejected {
            background: #fff0f1;
            color: #b42318;
        }

        .status.unsubmitted {
            background: #fff7e8;
            color: #9a6500;
        }

        /* =========================================
           UPLOAD
        ========================================= */

        .upload-form {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .upload-form input[type=file] {
            display: none;
        }

        .file-label {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 180px;
            border: 1.5px dashed #c3d2e4;
            border-radius: 12px;
            padding: 9px 14px;
            font-size: 11px;
            font-weight: 700;
            background: linear-gradient(180deg, #fbfdff, #f3f8ff);
            color: #40516a;
            transition:
                border-color 0.2s ease,
                background 0.2s ease,
                color 0.2s ease,
                transform 0.15s ease,
                box-shadow 0.2s ease;
        }

        .file-label:hover {
            border-color: #1764bb;
            border-style: solid;
            background: #eef5ff;
            color: #1764bb;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(23, 100, 187, 0.12);
        }

        .file-label .file-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 7px;
            background: #e4efff;
            color: #1764bb;
            font-size: 12px;
            flex-shrink: 0;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .file-label:hover .file-icon {
            background: #1764bb;
            color: #fff;
        }

        .file-label.has-file {
            border-style: solid;
            border-color: #bcebd9;
            background: #f2fbf7;
            color: #14825e;
        }

        .file-label.has-file .file-icon {
            background: #d6f3e6;
            color: #14825e;
        }

        .file-label.has-file:hover {
            border-color: #14825e;
            color: #14825e;
            box-shadow: 0 4px 10px rgba(20, 130, 94, 0.12);
        }

        .file-label.has-file:hover .file-icon {
            background: #14825e;
            color: #fff;
        }

        .file-label .file-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .req-rejected-note {
            margin-top: 8px;
            padding: 8px 12px;
            border: 1px solid #f3c2c0;
            border-radius: 9px;
            background: #fff0f1;
            color: #b42318;
            font-size: 11px;
            font-weight: 700;
        }

        .req-file-name {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            color: #9aa8bb;
        }

        .req-file-name.has-file {
            color: #14825e;
        }

        .requirement[onclick]:hover {
            border-color: #9db7d5;
            box-shadow: 0 4px 12px rgba(23, 100, 187, 0.08);
        }

        .upload-btn {
            border: 0;
            border-radius: 9px;
            padding: 9px 12px;
            background: #1764bb;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }

        .upload-btn:hover {
            background: #12559f;
        }

        .upload-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        /* =========================================
           READY CARD
        ========================================= */

        .ready-card {
            border: 1px solid #bcebd9;

            background:
                linear-gradient(
                    135deg,
                    #effcf7,
                    #f8fffc
                );

            border-radius: 17px;
            padding: 22px;
            text-align: center;
        }

        .ready-icon {
            width: 46px;
            height: 46px;

            border-radius: 50%;

            background: #1bb889;
            color: #fff;

            display: flex;
            align-items: center;
            justify-content: center;

            margin: 0 auto 10px;

            font-weight: 900;
        }

        .ready-card h2 {
            font: 700 21px Outfit, sans-serif;
            margin: 0 0 5px;
        }

        .ready-card p {
            margin: 0 0 16px;
            color: #607184;
            font-size: 13px;
        }

        .btn-dashboard {
            display: inline-flex;
            text-decoration: none;
            background: #1764bb;
            color: #fff;
            padding: 11px 17px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 800;
        }

        .btn-dashboard:hover {
            background: #12559f;
        }

        /* =========================================
           FOOTER
        ========================================= */

        .onboarding-foot {
            padding: 18px 34px;

            border-top: 1px solid #edf1f5;

            background: #fbfcfe;

            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
        }

        .foot-note {
            font-size: 11px;
            color: #8792a2;
            line-height: 1.5;
        }

        /* =========================================
           MOBILE
        ========================================= */

        @media (max-width: 760px) {

            .onboarding-shell {
                width: min(100% - 20px, 1120px);
                padding-top: 18px;
            }

            .onboarding-head,
            .onboarding-body {
                padding: 24px 18px;
            }

            .stepper {
                padding: 22px 12px;
            }

            .step-label {
                font-size: 10px;
            }

            .step-state {
                font-size: 9px;
            }

            .step-dot {
                width: 32px;
                height: 32px;
            }

            .step:not(:last-child)::after {
                top: 16px;
                left: calc(50% + 17px);
                right: calc(-50% + 17px);
            }

            .account-summary {
                grid-template-columns: 1fr;
            }

            .requirement {
                grid-template-columns: 1fr;
            }

            .req-actions {
                justify-content: flex-start;
            }

            .onboarding-title {
                font-size: 24px;
            }

            .onboarding-foot {
                padding: 16px 18px;
                align-items: flex-start;
                flex-direction: column;
            }

            /*
             * Keep the sign-out button at the
             * right side on smaller screens too.
             */
            .onboarding-brand .signout {
                margin-left: auto;
            }
        }

    </style>

</head>

<body class="ojt-onboarding">

<div class="onboarding-shell">

    <!-- =========================================
         HEADER
    ========================================== -->

    <div class="onboarding-brand">

        <img
            src="/ojtrack/assets/images/logo.png"
            alt="OJTRACK"
        >

        <div>
            <strong style="color: #103b78ff">OJT</strong><strong style="color: #F5A623;">RACK</strong>
            <span>USTP Jasaan OJT Management System</span>
        </div>

        <!-- SIGN OUT -->
        <a
            class="signout"
            href="/ojtrack/logout.php"
        >
            Sign out
        </a>

    </div>


    <!-- =========================================
         MAIN CARD
    ========================================== -->

    <section class="onboarding-card">

        <div class="onboarding-head">

            <h1 class="onboarding-title">
                Complete your OJT setup
            </h1>

        </div>


        <!-- =========================================
             STEPPER
        ========================================== -->

        <div
            class="stepper"
            aria-label="Student activation progress"
        >

            <!-- STEP 1 -->

            <div
                class="step <?= $details_complete ? 'complete' : 'current' ?>"
            >

                <div class="step-dot">
                    <?= $details_complete ? '✓' : '1' ?>
                </div>

                <div class="step-label">
                    Student Details
                </div>

                <div class="step-state">
                    <?= $details_complete ? 'Completed' : 'In Progress' ?>
                </div>

            </div>


            <!-- STEP 2 -->

            <div
                class="step <?= $documents_complete
                    ? 'complete'
                    : (!$details_complete ? '' : 'current') ?>"
            >

                <div class="step-dot">
                    <?= $documents_complete ? '✓' : '2' ?>
                </div>

                <div class="step-label">
                    Required Documents
                </div>

                <div class="step-state">
                    <?= $documents_complete
                        ? 'Completed'
                        : 'In Progress' ?>
                </div>

            </div>


            <!-- STEP 3 -->

            <div
                class="step <?= $ready
                    ? 'complete'
                    : (($current_step === 3) ? 'current' : '') ?>"
            >

                <div class="step-dot">
                    <?= $ready ? '✓' : '3' ?>
                </div>

                <div class="step-label">
                    Coordinator Review
                </div>

                <div class="step-state">

                    <?= $ready
                        ? 'Completed'
                        : (
                            ($pending_review > 0 || $documents_complete)
                                ? 'In Progress'
                                : 'Pending'
                        )
                    ?>

                </div>

            </div>


            <!-- STEP 4 -->

            <div
                class="step <?= $ready ? 'complete current' : '' ?>"
            >

                <div class="step-dot">
                    <?= $ready ? '✓' : '4' ?>
                </div>

                <div class="step-label">
                    Dashboard Access
                </div>

                <div class="step-state">
                    <?= $ready ? 'Ready' : 'Pending' ?>
                </div>

            </div>

        </div>


        <!-- =========================================
             BODY
        ========================================== -->

        <div class="onboarding-body">

            <!-- ACCOUNT SUMMARY -->

            <div class="account-summary">

                <div class="summary-item">

                    <div class="summary-label">
                        Student
                    </div>

                    <div class="summary-value">
                        <?= e($student['name']) ?>
                    </div>

                </div>


                <div class="summary-item">

                    <div class="summary-label">
                        Student ID
                    </div>

                    <div class="summary-value">
                        <?= e($student['student_id_no']) ?>
                    </div>

                </div>


                <div class="summary-item">

                    <div class="summary-label">
                        Program
                    </div>

                    <div class="summary-value">
                        <?= e(
                            $student['program_code']
                                ?: $student['program']
                        ) ?>
                    </div>

                </div>

            </div>


            <!-- SUCCESS MESSAGE -->

            <?php if ($success): ?>

                <div class="notice success">
                    <?= e($success) ?>
                </div>

            <?php endif; ?>


            <!-- ERROR MESSAGE -->

            <?php if ($error): ?>

                <div class="notice error">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>


            <!-- =====================================
                 READY
            ====================================== -->

            <?php if ($ready): ?>

                <div class="ready-card">

                    <div class="ready-icon">
                        ✓
                    </div>

                    <h2>
                        Your OJT account is ready
                    </h2>

                    <p>
                        All <?= $total ?>
                        required document<?= $total === 1 ? '' : 's' ?>
                        have been approved by your coordinator.
                        You can now proceed to the main dashboard.
                    </p>

                    <a
                        class="btn-dashboard"
                        href="/ojtrack/student/dashboard.php"
                    >
                        Continue to Main Dashboard →
                    </a>

                </div>


            <!-- =====================================
                 NO REQUIREMENTS
            ====================================== -->

            <?php elseif ($total === 0): ?>

                <div class="notice info" style="text-align:center">

                    <strong>
                        Waiting for your coordinator.
                    </strong>

                    <br>

                   Your account is ready, but no OJT requirements have been assigned yet. Please wait for your coordinator to assign them.

                </div>


            <!-- =====================================
                 REQUIREMENTS
            ====================================== -->

            <?php else: ?>

                <div
                    style="
                        display:flex;
                        justify-content:space-between;
                        gap:16px;
                        align-items:end;
                        margin-bottom:14px
                    "
                >

                    <div>

                        <h2 class="section-title">
                            Required OJT Documents
                        </h2>

                    </div>

                    <div
                        style="
                            font-size:11px;
                            color:#748095;
                            white-space:nowrap
                        "
                    >
                        <?= $approved ?>/<?= $total ?> approved
                    </div>

                </div>


                <form
                    method="POST"
                    enctype="multipart/form-data"
                ><?= csrf_field() ?>

                    <input
                        type="hidden"
                        name="action"
                        value="upload_all"
                    >

                <div class="requirements">

                    <?php foreach ($requirements as $r): ?>

                        <?php

                        $status = $r['status'];

                        $submitted_at =
                            $r['submitted_at']
                                ? date(
                                    'M d, Y',
                                    strtotime($r['submitted_at'])
                                )
                                : '';

                        $status_class =
                            $status === 'approved'
                                ? 'approved'
                                : (
                                    $status === 'rejected'
                                        ? 'rejected'
                                        : (
                                            $r['submitted_at']
                                                ? 'pending'
                                                : 'unsubmitted'
                                        )
                                );

                        $status_label =
                            $status === 'approved'
                                ? 'Approved'
                                : (
                                    $status === 'rejected'
                                        ? 'Rejected — Resubmit'
                                        : (
                                            $r['submitted_at']
                                                ? 'Under Review'
                                                : 'Not Submitted'
                                        )
                                );

                        ?>

                        <article
                            class="requirement"
                            <?php if ($status !== 'approved'): ?>
                                style="cursor:pointer"
                                onclick="
                                    var t = event.target;
                                    if (t.closest('a') || t.closest('button')) return;
                                    var i = this.querySelector('input[type=file]');
                                    if (i) i.click();
                                "
                            <?php endif; ?>
                        >

                            <div>

                                <div class="req-name">
                                    <?= e($r['document_name']) ?>
                                </div>


                                <div class="req-meta">

                                    <span
                                        class="status <?= $status_class ?>"
                                    >
                                        <?= e($status_label) ?>
                                    </span>


                                    <?php if ($r['deadline']): ?>

                                        <span>
                                            Deadline:
                                            <?= e(
                                                format_date(
                                                    $r['deadline']
                                                )
                                            ) ?>
                                        </span>

                                    <?php endif; ?>


                                    <?php if ($submitted_at): ?>

                                        <span>
                                            Submitted:
                                            <?= e($submitted_at) ?>
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <?php if ($status === 'rejected'): ?>

                                    <div class="req-rejected-note">
                                        ✗ Your coordinator returned this document.
                                        Please attach a new file and resubmit.
                                    </div>

                                <?php endif; ?>

                                <?php if (!empty($r['remarks'])): ?>

                                    <div
                                        class="req-remarks <?= $status === 'rejected'
                                            ? 'rejected'
                                            : '' ?>"
                                    >
                                        <?= e($r['remarks']) ?>
                                    </div>

                                <?php endif; ?>


                                <?php if (!empty($r['file_path'])): ?>

                                    <div style="margin-top:8px">

                                        <a
                                            href="/ojtrack/download.php?file=<?= rawurlencode($r['file_path']) ?>"
                                            target="_blank"
                                            style="
                                                font-size:11px;
                                                font-weight:700;
                                                color:#1764bb;
                                                text-decoration:none
                                            "
                                        >
                                            View submitted file ↗
                                        </a>

                                    </div>

                                <?php endif; ?>

                            </div>


                            <div class="req-actions">

                                <?php if ($status !== 'approved'): ?>

                                    <input
                                        type="file"
                                        name="documents[<?= (int)$r['id'] ?>]"
                                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                        style="display:none"
                                        onchange="
                                            var el = this.closest('article')
                                                .querySelector('.req-file-name');
                                            el.textContent = this.files[0]
                                                ? '📎 ' + this.files[0].name
                                                : '<?= $status === 'rejected'
                                                    ? 'Choose replacement'
                                                    : 'Click this card to attach a document' ?>';
                                            el.classList.toggle('has-file', !!this.files[0]);
                                        "
                                    >

                                    <span class="req-file-name<?= (!empty($r['submitted_at']) || !empty($r['file_path'])) ? ' has-file' : '' ?>">
                                        <?php if ($status === 'rejected'): ?>
                                            Click this card to choose a replacement
                                        <?php elseif (!empty($r['submitted_at']) || !empty($r['file_path'])): ?>
                                            📎 <?= e(basename($r['file_path'])) ?> — click this card to change file
                                        <?php else: ?>
                                            Click this card to attach a document
                                        <?php endif; ?>
                                    </span>

                                <?php else: ?>

                                    <span
                                        style="
                                            font-size:11px;
                                            font-weight:800;
                                            color:#14825e
                                        "
                                    >
                                        Verified ✓
                                    </span>

                                <?php endif; ?>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

                    <div style="margin-top:14px;text-align:right">

                        <button
                            class="upload-btn"
                            type="submit"
                        >
                            Submit All
                        </button>

                    </div>

                </form>

            <?php endif; ?>

        </div>

    </section>

</div>

</body>
</html>

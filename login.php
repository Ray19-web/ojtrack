<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_csrf();

if (is_logged_in()) {
    $map = [
        'student' => 'student/dashboard.php',
        'coordinator' => 'coordinator/dashboard.php',
        'company' => 'company/dashboard.php',
        'admin' => 'admin/dashboard.php'
    ];

    header('Location: /ojtrack/' . ($map[$_SESSION['role']] ?? 'login.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields.';
        } else {
            $user = query_one(
                "SELECT u.*, s.student_id_no, s.program, s.year_level,
                        s.rendered_hours, s.required_hours,
                        c.department,
                        co.company_name
                 FROM users u
                 LEFT JOIN students s ON s.user_id = u.id
                 LEFT JOIN coordinators c ON c.user_id = u.id
                 LEFT JOIN companies co ON co.user_id = u.id
                 WHERE (u.email = ? OR s.student_id_no = ?)
                 AND u.status = 'active'",
                [$email, $email],
                'ss'
            );

            $valid = $user && password_verify($password, $user['password']);

            if ($valid) {
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['auth_fingerprint'] = hash('sha256', $user['password']);
                $_SESSION['last_activity'] = time();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role']    = $user['role'];
                $_SESSION['name']    = $user['name'];
                $_SESSION['avatar']  = $user['avatar'] ?? '';

                $sub = match ($user['role']) {
                    'student' =>
                        ($user['program'] ?? 'BSIT') . ' · ' .
                        ($user['student_id_no'] ?? ''),

                    'coordinator' =>
                        $user['department'] ?? 'OJT Coordinator',

                    'company' =>
                        $user['company_name'] ?? 'Company',

                    'admin' =>
                        'All Access',

                    default =>
                        '',
                };

                $_SESSION['sub'] = $sub;

                log_activity(
                    $user['id'],
                    'Login',
                    'Successful login'
                );

                $map = [
                    'student' => 'student/dashboard.php',
                    'coordinator' => 'coordinator/dashboard.php',
                    'company' => 'company/dashboard.php',
                    'admin' => 'admin/dashboard.php'
                ];

                header('Location: /ojtrack/' . $map[$user['role']]);
                exit;
            } else {
                $error = 'Invalid credentials. Please try again.';
            }
        }
    }
}
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

    <title>JTrack — Login</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- Main CSS -->
    <link
        rel="stylesheet"
        href="/ojtrack/assets/css/style.css"
    >

    <style>

        /* =========================================================
           RESET
        ========================================================= */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }


        /* =========================================================
           VARIABLES
        ========================================================= */

        :root {
            --navy: #123d78;
            --navy-dark: #0b3269;
            --blue: #1764bb;

            --text: #16243a;
            --muted: #7e8998;

            --border: #e1e6ec;

            --white: #ffffff;
        }


        /* =========================================================
           HTML / BODY
        ========================================================= */

        html,
        body {
            width: 100%;
            min-height: 100%;
        }


        body {

            font-family: 'Inter', sans-serif;

            min-height: 100vh;

            display: flex;

            align-items: center;
            justify-content: center;

            padding: 30px;

            position: relative;

            overflow-x: hidden;

            /*
             * Main background image
             */
            background:
                linear-gradient(
                    rgba(22, 27, 34, 0.70),
                    rgba(22, 27, 34, 0.70)
                ),
                url('/ojtrack/assets/images/background.png');

            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }


        /*
         * Additional subtle dark overlay.
         */
        body::before {

            content: '';

            position: fixed;

            inset: 0;

            pointer-events: none;

            background:
                radial-gradient(
                    circle at center,
                    transparent 20%,
                    rgba(0, 0, 0, 0.15) 100%
                );

            z-index: 0;
        }


        /* =========================================================
           MAIN PORTAL
        ========================================================= */

        .portal {

            position: relative;

            z-index: 1;

            width: min(100%, 940px);

            min-height: 610px;

            display: grid;

            grid-template-columns: 430px 1fr;

            background: #ffffff;

            border-radius: 22px;

            overflow: hidden;

            border: 1px solid #F5A623;

            box-shadow:
                0 30px 70px #f5a52314,
                0 10px 25px #f5a52313;

            animation: portalIn 0.65s ease-out;
        }


        @keyframes portalIn {

            from {
                opacity: 0;
                transform: translateY(20px) scale(0.985);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }

        }


        /* =========================================================
           LEFT LOGIN PANEL
        ========================================================= */

        .login-panel {

            background: #e3e3e3ff;

            padding: 48px 46px 34px;

            display: flex;

            flex-direction: column;

            position: relative;

            z-index: 2;
        }


        /* =========================================================
           BRAND
        ========================================================= */

        .brand-row {

            display: flex;

            align-items: center;

            justify-content: center;

            margin-bottom: 48px;
        }


        .brand-logo {

            height: 100px;

            width: auto;

            object-fit: contain;

            object-position: left center;
        }


        .portal-badge {

            display: inline-flex;

            align-items: center;

            gap: 7px;

            padding: 8px 13px;

            border-radius: 20px;

            background: #f7f9fb;

            color: #718096;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: 0.2px;

            white-space: nowrap;
        }


        .portal-badge::before {

            content: '';

            width: 7px;

            height: 7px;

            border-radius: 50%;

            background: #f5b51b;

            box-shadow:
                0 0 0 3px rgba(245, 181, 27, 0.12);
        }


        /* =========================================================
           LOGIN HEADING
        ========================================================= */

        .login-heading {

            margin-bottom: 28px;
        }


        .login-heading h1 {

            font-family: 'Outfit', sans-serif;

            color: #000000ff;

            font-size: 34px;

            line-height: 1;

            font-weight: 700;

            margin-bottom: 9px;
        }


        .login-heading p {

            color: #8b95a5;

            font-size: 13px;

            line-height: 1.5;

            font-weight: 400;
        }


        /* =========================================================
           ERROR
        ========================================================= */

        .login-error {

            display: flex;

            align-items: center;

            gap: 10px;

            padding: 12px 14px;

            margin-bottom: 20px;

            border-radius: 9px;

            background: #fff2f2;

            border: 1px solid #ffd4d4;

            color: #b42318;

            font-size: 12px;

            font-weight: 500;

            animation: shake 0.4s ease-in-out;
        }


        .login-error svg {

            width: 17px;

            height: 17px;

            flex-shrink: 0;
        }


        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-4px);
            }

            75% {
                transform: translateX(4px);
            }

        }


        /* =========================================================
           FORM GROUP
        ========================================================= */

        .login-form-group {

            margin-bottom: 20px;
        }


        .login-form-group label {

            display: block;

            margin-bottom: 8px;

            color: #536174;

            font-size: 11px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: 0.4px;
        }


        /* =========================================================
           INPUT WRAPPER
        ========================================================= */

        .input-wrap {

            position: relative;
        }


        .input-wrap > .field-icon {

            position: absolute;

            left: 14px;

            top: 50%;

            width: 18px;

            height: 18px;

            transform: translateY(-50%);

            color: #a6afbb;

            pointer-events: none;

            transition: color 0.2s ease;
        }


        .input-wrap input {

            width: 100%;

            height: 46px;

            padding: 0 42px 0 42px;

            border: 1px solid #dfe5eb;

            border-radius: 9px;

            outline: none;

            background: #ffffff;

            color: #1d2939;

            font-family: 'Inter', sans-serif;

            font-size: 12px;

            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease,
                background 0.2s ease;
        }


        .input-wrap input::placeholder {

            color: #aab2bd;

            font-size: 11px;
        }


        .input-wrap input:focus {

            border-color: #2767b9;

            box-shadow:
                0 0 0 4px rgba(39, 103, 185, 0.09);

            background: #ffffff;
        }


        .input-wrap input:focus ~ .field-icon {

            color: #2767b9;
        }


        /* =========================================================
           PASSWORD TOGGLE
        ========================================================= */

        .password-toggle {

            position: absolute;

            right: 11px;

            top: 50%;

            transform: translateY(-50%);

            border: 0;

            background: transparent;

            color: #a6afbb;

            padding: 6px;

            cursor: pointer;

            display: flex;

            align-items: center;

            justify-content: center;

            transition: color 0.2s ease;
        }


        .password-toggle:hover {

            color: #245c9f;
        }


        .password-toggle svg {

            width: 17px;

            height: 17px;
        }


        /* =========================================================
           OPTIONS
        ========================================================= */

        .login-options {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-top: 3px;

            margin-bottom: 22px;
        }


        .remember {

            display: flex;

            align-items: center;

            gap: 7px;

            color: #7d8795;

            font-size: 11px;

            cursor: pointer;
        }


        .remember input {

            width: 14px;

            height: 14px;

            margin: 0;

            accent-color: #174b91;

            cursor: pointer;
        }


        .forgot {

            color: #244f86;

            font-size: 11px;

            font-weight: 600;

            text-decoration: none;

            transition: color 0.2s ease;
        }


        .forgot:hover {

            color: #123d78;

            text-decoration: underline;
        }


        /* =========================================================
           LOGIN BUTTON
        ========================================================= */

        .login-submit {

            width: 100%;

            height: 47px;

            border: 0;

            border-radius: 9px;

            background: #103b78ff;

            color: #F5A623;
            letter-spacing: 10px;

            font-family: 'Inter', sans-serif;

            font-size: 16px;

            font-weight: 1000;

            cursor: pointer;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 9px;

            box-shadow:
                0 4px 9px rgba(16, 59, 120, 0.22);

            transition:
                transform 0.2s ease,
                background 0.2s ease,
                box-shadow 0.2s ease;
        }


        .login-submit span {

            font-size: 18px;

            line-height: 1;

            font-weight: 400;
        }


        .login-submit:hover {

            background: #0b3269;

            transform: translateY(-1px);

            box-shadow:
                0 7px 17px rgba(16, 59, 120, 0.28);
        }


        .login-submit:active {

            transform: translateY(0);
        }


        /* =========================================================
           DIVIDER
        ========================================================= */

        .login-divider {

            display: flex;

            align-items: center;

            gap: 10px;

            margin: 22px 0 16px;

            color: #a4acb7;

            font-size: 9px;

            font-weight: 600;
        }


        .login-divider::before,
        .login-divider::after {

            content: '';

            flex: 1;

            height: 1px;

            background: #edf0f3;
        }


        /* =========================================================
           GOOGLE BUTTON
        ========================================================= */

        .google-btn {

            width: 100%;

            height: 43px;

            border: 1px solid #e2e7ed;

            border-radius: 9px;

            background: #ffffff;

            color: #4b5563;

            font-family: 'Inter', sans-serif;

            font-size: 11px;

            font-weight: 600;

            cursor: pointer;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 9px;

            transition:
                background 0.2s ease,
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }


        .google-btn:hover {

            background: #fafbfc;

            border-color: #d5dbe3;

            box-shadow:
                0 3px 10px rgba(15, 23, 42, 0.06);
        }


        .google-icon {

            width: 16px;

            height: 16px;
        }


        /* =========================================================
           BOTTOM
        ========================================================= */

        .login-bottom {

            margin-top: auto;

            padding-top: 22px;

            border-top: 1px solid #f0f2f5;

            display: flex;

            align-items: center;

            justify-content: space-between;
        }


        .login-bottom p {

            color: #9aa3af;

            font-size: 10px;
        }


        .login-bottom a {

            min-width: 70px;

            height: 31px;

            padding: 0 12px;

            border: 1px solid #e1e6ec;

            border-radius: 7px;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            color: #4b5563;

            font-size: 10px;

            font-weight: 600;

            text-decoration: none;

            background: #ffffff;

            transition:
                border-color 0.2s ease,
                background 0.2s ease;
        }


        .login-bottom a:hover {

            border-color: #cbd5e1;

            background: #f8fafc;
        }


        /* =========================================================
           RIGHT HERO PANEL
        ========================================================= */

        .hero-panel {

            position: relative;

            overflow: hidden;

            background:
                linear-gradient(
                    135deg,
                    #123f7b 0%,
                    #0b3470 55%,
                    #153f78 100%
                );

            color: white;

            padding: 48px 46px 36px;

            display: flex;

            flex-direction: column;

            justify-content: center;
        }


        /*
         * Background image layer.
         *
         * The image is intentionally subtle.
         */
        .hero-panel::before {

            content: '';

            position: absolute;

            inset: 0;

            background-image:
                url('/ojtrack/assets/images/background.png');

            background-size: cover;

            background-position: center;

            background-repeat: no-repeat;

            opacity: 0.14;

            z-index: 0;
        }


        /*
         * Blue overlay over the background image.
         */
        .hero-panel::after {

            content: '';

            position: absolute;

            inset: 0;

            background:
                radial-gradient(
                    circle at 80% 15%,
                    rgba(52, 125, 218, 0.35),
                    transparent 40%
                ),
                linear-gradient(
                    135deg,
                    rgba(18, 63, 123, 0.35),
                    rgba(11, 52, 112, 0.20)
                );

            z-index: 1;
        }


        /* =========================================================
           HERO CONTENT
        ========================================================= */

        .hero-content {

            position: relative;

            z-index: 3;

            max-width: 460px;
        }


        /* =========================================================
           HERO BADGE
        ========================================================= */

        .hero-badge {

            display: inline-flex;

            align-items: center;

            gap: 8px;

            height: 28px;

            padding: 0 14px;

            border-radius: 20px;

            background: rgba(70, 137, 211, 0.27);

            border: 1px solid rgba(127, 181, 237, 0.15);

            color: #e0edfc;

            font-size: 10px;

            font-weight: 600;

            letter-spacing: 0.1px;
        }


        .hero-badge::before {

            content: '';

            width: 7px;

            height: 7px;

            border-radius: 50%;

            background: #f2c51e;

            box-shadow:
                0 0 0 3px rgba(242, 197, 30, 0.08);
        }


        /* =========================================================
           HERO TITLE
        ========================================================= */

        .hero-title {

            margin-top: 20px;
        }


        .hero-title h2 {

            font-family: 'Outfit', sans-serif;

            font-size: 48px;

            line-height: 0.96;

            letter-spacing: -1.5px;

            font-weight: 800;

            color: #F5A623;
        }


        .hero-title h2 span {

            display: block;
        }


        .hero-title p {

            margin-top: 19px;

            max-width: 350px;

            color: #c8d8ed;

            font-size: 14px;

            line-height: 1.55;

            font-weight: 500;
        }


        /* =========================================================
           HERO INFORMATION
        ========================================================= */

        .hero-info {

            position: relative;

            z-index: 3;

            margin-top: 35px;

            display: flex;

            flex-direction: column;

            gap: 13px;

            max-width: 350px;
        }


        .info-item {

            display: flex;

            align-items: center;

            gap: 11px;

            color: #d7e5f7;

            font-size: 11px;

            font-weight: 500;
        }


        .info-dot {

            width: 8px;

            height: 8px;

            flex-shrink: 0;

            border-radius: 50%;

            background: #F5A623;
        }


        /* =========================================================
           HERO FOOTER
        ========================================================= */

        .hero-footer {

            position: absolute;

            z-index: 5;

            left: 46px;

            right: 46px;

            bottom: 30px;

            display: flex;

            align-items: center;

            justify-content: space-between;
        }


        .university-name {

            color: #9bb9df;

            font-family: 'Outfit', sans-serif;

            font-size: 9px;

            font-weight: 600;
        }


        .version {

            color: #f2c31d;

            font-size: 9px;

            font-weight: 700;
        }


        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 850px) {

            body {

                padding: 20px;
            }


            .portal {

                width: 100%;

                max-width: 720px;

                grid-template-columns: 1fr 1fr;

                min-height: 580px;
            }


            .login-panel {

                padding: 40px 34px 30px;
            }


            .hero-panel {

                padding: 40px 34px 30px;
            }


            .hero-title h2 {

                font-size: 40px;
            }


            .hero-title p {

                font-size: 13px;
            }


            .hero-footer {

                left: 34px;

                right: 34px;
            }

        }


        @media (max-width: 680px) {

            body {

                padding: 15px;
            }


            .portal {

                max-width: 460px;

                grid-template-columns: 1fr;

                min-height: auto;
            }


            .hero-panel {

                min-height: 330px;

                order: -1;

                justify-content: flex-start;

                padding: 35px 30px 65px;
            }


            .login-panel {

                min-height: 520px;

                padding: 38px 30px 30px;
            }


            .hero-title h2 {

                font-size: 39px;
            }


            .hero-title p {

                max-width: 390px;

                font-size: 13px;
            }


            .hero-info {

                margin-top: 25px;
            }


            .hero-footer {

                left: 30px;

                right: 30px;

                bottom: 22px;
            }

        }


        @media (max-width: 480px) {

            body {

                padding: 10px;
            }


            .portal {

                border-radius: 17px;
            }


            .hero-panel {

                min-height: 300px;

                padding: 30px 24px 62px;
            }


            .login-panel {

                padding: 32px 24px 26px;
            }


            .brand-row {

                margin-bottom: 36px;
            }


            .brand-logo {

                height: 39px;
            }


            .portal-badge {

                padding: 7px 10px;

                font-size: 8px;
            }


            .login-heading h1 {

                font-size: 31px;
            }


            .login-heading p {

                font-size: 12px;
            }


            .hero-title h2 {

                font-size: 34px;

                letter-spacing: -1px;
            }


            .hero-title p {

                font-size: 12px;

                max-width: 300px;
            }


            .hero-info {

                gap: 10px;
            }


            .info-item {

                font-size: 10px;
            }


            .hero-footer {

                left: 24px;

                right: 24px;
            }


            .university-name,
            .version {

                font-size: 8px;
            }


            .login-bottom p {

                font-size: 9px;
            }

        }


        @media (max-width: 370px) {

            .portal-badge {

                display: none;
            }


            .login-heading h1 {

                font-size: 28px;
            }


            .hero-title h2 {

                font-size: 30px;
            }


            .hero-title p {

                font-size: 11px;
            }


            .login-panel {

                padding-left: 20px;

                padding-right: 20px;
            }


            .hero-panel {

                padding-left: 20px;

                padding-right: 20px;
            }


            .hero-footer {

                left: 20px;

                right: 20px;
            }

        }

    </style>

</head>


<body>


<div class="portal">


    <!-- =========================================================
         LEFT: LOGIN PANEL
    ========================================================== -->

    <section class="login-panel">


        <!-- BRAND -->

        <div class="brand-row">

            <img
                src="/ojtrack/assets/images/branging.png"
                alt="JTrack"
                class="brand-logo"
            >

        </div>


        <!-- HEADING -->

        <div class="login-heading">

            <h1>
                Login
            </h1>

            <p>
                Enter your account details to access your portal
            </p>

        </div>


        <!-- ERROR -->

        <?php if ($error): ?>

            <div class="login-error">

                <svg
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                >

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                    />

                </svg>

                <span>
                    <?= e($error) ?>
                </span>

            </div>

        <?php endif; ?>


        <!-- LOGIN FORM -->

        <form
            method="POST"
            action=""
        ><?= csrf_field() ?>

            <input
                type="hidden"
                name="action"
                value="login"
            >


            <!-- USERNAME -->

            <div class="login-form-group">

                <label for="email">
                    Username or Student ID
                </label>


                <div class="input-wrap">

                    <input
                        type="text"
                        id="email"
                        name="email"
                        placeholder="e.g. 2021301234 or email"
                        autocomplete="username"
                        required
                        autofocus
                    >


                    <svg
                        class="field-icon"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0z"
                        />

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M5 21a7 7 0 0114 0"
                        />

                    </svg>

                </div>

            </div>


            <!-- PASSWORD -->

            <div class="login-form-group">

                <label for="passwordInput">
                    Password
                </label>


                <div class="input-wrap">

                    <input
                        type="password"
                        id="passwordInput"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >


                    <svg
                        class="field-icon"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M12 15v2"
                        />

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M7 10V7a5 5 0 0110 0v3"
                        />

                        <rect
                            x="5"
                            y="10"
                            width="14"
                            height="10"
                            rx="2"
                        />

                    </svg>


                    <button
                        type="button"
                        class="password-toggle"
                        onclick="togglePassword()"
                        aria-label="Toggle password visibility"
                    >

                        <svg
                            id="eyeIcon"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                            />

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                            />

                        </svg>

                    </button>

                </div>

            </div>


            <!-- OPTIONS -->

            <div class="login-options">


                <label class="remember">

                    <input
                        type="checkbox"
                        name="remember"
                    >

                    <span>
                        Remember me
                    </span>

                </label>


                <a
                    href="#"
                    class="forgot"
                >
                    Forgot Password?
                </a>


            </div>


            <!-- LOGIN -->

            <button
                type="submit"
                class="login-submit"
            >

                Login

            </button>


        </form>


        


    </section>


    <!-- =========================================================
         RIGHT: HERO PANEL
    ========================================================== -->

    <section class="hero-panel">


        <div class="hero-content">


            <div class="hero-title">

                <h2>

                    <span>
                        Welcome to
                    </span>

                    <span>
                        OJTrack portal
                    </span>

                </h2>


                <p>
                    Login to access your account &amp; track internship
                    milestones
                </p>

            </div>


            <!-- HERO INFORMATION -->

            <div class="hero-info">

                <div class="info-item">

                    <span class="info-dot"></span>

                    <span>
                        Track your OJT progress and internship hours
                    </span>

                </div>


                <div class="info-item">

                    <span class="info-dot"></span>

                    <span>
                        Monitor your internship milestones
                    </span>

                </div>


                <div class="info-item">

                    <span class="info-dot"></span>

                    <span>
                        Access your USTP OJT portal securely
                    </span>

                </div>

            </div>


        </div>



    </section>


</div>


<script>

    function togglePassword() {

        const input =
            document.getElementById('passwordInput');

        const icon =
            document.getElementById('eyeIcon');


        if (input.type === 'password') {

            input.type = 'text';


            icon.innerHTML = `

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029"
                />

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M9.878 9.878a3 3 0 104.243 4.243"
                />

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M3 3l18 18"
                />

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M10.477 5.477A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411"
                />

            `;

        } else {

            input.type = 'password';


            icon.innerHTML = `

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                />

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                />

            `;

        }

    }

</script>


</body>

</html>

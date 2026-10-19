<?php
session_start();

/* ==========================================================================
   1. CONFIGURATION & MANAGER NOTIFICATION SETTINGS
   ========================================================================== 
   👉 Put the manager's actual email address below to receive instant phone alerts */
$manager_email_address = 'www.lwambagideon@gmail.com'; 


/* ==========================================================================
   2. DATABASE CONNECTION & AUTOMATIC SCHEMA MIGRATION
   ========================================================================== */
$host = 'localhost';$user = 'root';
$pass = '';$db   = 'aqua_learn';

try {
    $pdo = new PDO(
        "mysql:host=$host;charset=utf8mb4", 
        $user,$pass, 
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db`");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Core tables
$pdo->exec("CREATE TABLE IF NOT EXISTS `client` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(100) NULL,
    `password` VARCHAR(255) NOT NULL,
    `age_category` VARCHAR(20) DEFAULT 'Kid',
    `package_name` VARCHAR(50) DEFAULT '15 Sessions Package',
    `total_sessions` INT DEFAULT 15
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS `staff` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(100) NULL,
    `password` VARCHAR(255) NOT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS `owner` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(100) NULL,
    `password` VARCHAR(255) NOT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `staff_id` INT NULL,
    `date` DATE NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `notes` TEXT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS `progress_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `staff_id` INT NULL,
    `record_date` DATE DEFAULT CURRENT_DATE,
    `skill_level` VARCHAR(100) NOT NULL,
    `coach_remarks` TEXT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS `coach_activity` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `staff_id` INT NOT NULL,
    `activity_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `shift_notes` TEXT NULL,
    `status` VARCHAR(50) DEFAULT 'Completed Shift'
)");

// Schema repair
foreach (['staff', 'owner', 'client'] as $tbl) {
    try { $pdo->exec("ALTER TABLE `$tbl` MODIFY COLUMN `password` VARCHAR(255) NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `phone` VARCHAR(50) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `$tbl` MODIFY COLUMN `email` VARCHAR(100) NULL"); } catch (Exception $e) {}
}
try { $pdo->exec("ALTER TABLE `client` ADD COLUMN `age_category` VARCHAR(20) DEFAULT 'Kid'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE `client` ADD COLUMN `package_name` VARCHAR(50) DEFAULT '15 Sessions Package'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE `client` ADD COLUMN `total_sessions` INT DEFAULT 15"); } catch (Exception $e) {}


/* ==========================================================================
   3. ROUTING & CLIENT-ONLY MODE DETECTION
   ========================================================================== */
$mode =$_GET['mode'] ?? ''; // Mode: 'swimmer' for client direct link
$action =$_GET['action'] ?? '';

if ($action === 'logout') {
    session_unset();
    session_destroy();
    $redirect = ($mode === 'swimmer') ? 'index.php?mode=swimmer' : 'index.php';
    header("Location: " . $redirect);
    exit();
}

// Default page handling for Swimmer-Only Mode vs Main Mode
if ($mode === 'swimmer' && !isset($_GET['page']) && !isset($_SESSION['user_id'])) {$page = 'register';
} else {
    $page =$_GET['page'] ?? 'home';
}

$msg = '';$err = '';


/* ==========================================================================
   4. FORM SUBMISSIONS & EMAIL ALERTS
   ========================================================================== */

// LOGIN SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) &&$_POST['form_type'] === 'login') {
    $phone    = trim($_POST['phone'] ?? '');
    $password =$_POST['password'] ?? '';

    if ($phone === '' || $password === '') {$err = "Please enter both phone number and password.";
    } else {
        $dummy_email =$phone . '@aqualearn.com';

        function checkAccount($pdo, $table,$val, $dummy_email,$password) {
            try {
                $stmt =$pdo->prepare("SELECT * FROM `$table` WHERE phone = ? OR email = ? OR email = ?");
                $stmt->execute([$val, $val, $dummy_email]);
                $user = $stmt->fetch();
                if ($user && !empty($user['password'])) {
                    if (password_verify($password, $user['password']) || $password === $user['password']) {
                        return $user;
                    }
                }
            } catch (Exception $e) {}
            return false;
        }

        $user = checkAccount($pdo, 'staff', $phone, $dummy_email, $password);
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['role']    = 'coach';
            header("Location: index.php?page=coach");
            exit();
        }

        $user = checkAccount($pdo, 'owner', $phone, $dummy_email, $password);
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['role']    = 'manager';
            header("Location: index.php?page=manager");
            exit();
        }

        $user = checkAccount($pdo, 'client', $phone, $dummy_email, $password);
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['role']    = 'client';
            $mode_param = ($mode === 'swimmer') ? '&mode=swimmer' : '';
            header("Location: index.php?page=client" . $mode_param);
            exit();
        }

        $err = "Invalid phone number or password.";
    }
}

// REGISTER SUBMISSION + EMAIL NOTIFICATION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) && $_POST['form_type'] === 'register') {
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = trim($_POST['role'] ?? 'client');

    if ($name === '' || $phone === '' || $password === '') {
        $err = "Please fill in all required fields.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $dummy_email = $phone . '@aqualearn.com';

        try {
            if ($role === 'coach') {
                $stmt = $pdo->prepare("INSERT INTO staff (name, phone, email, password) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $phone, $dummy_email, $hashed]);
                $msg = "Coach account registered! You can now log in.";
            } elseif ($role === 'owner') {
                $stmt = $pdo->prepare("INSERT INTO owner (name, phone, email, password) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $phone, $dummy_email, $hashed]);
                $msg = "Manager account created! You can now log in.";
            } else {
                $age_cat    = $_POST['age_category'] ?? 'Kid';
                $total_sess = (int)($_POST['total_sessions'] ?? 15);
                $pkg_name   = $total_sess . " Sessions Package";

                $stmt = $pdo->prepare("INSERT INTO client (name, phone, email, password, age_category, package_name, total_sessions) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $phone, $dummy_email, $hashed, $age_cat, $pkg_name, $total_sess]);
                
                $msg = "Swimmer profile created with $total_sess sessions! You can now log in.";

                /* ----------------------------------------------------------
                   EMAIL NOTIFICATION TO MANAGER
                   ---------------------------------------------------------- */
                if (!empty($manager_email_address)) {
                    $email_subject = "🏊 New Swimmer Registration: " . $name;
                    $email_message = "Hello Manager,\n\n"
                        . "A new swimmer has registered at Aqua Learn Swim Center Zambia!\n\n"
                        . "Swimmer Details:\n"
                        . "----------------------------------------\n"
                        . "Full Name: " . $name . "\n"
                        . "Phone Number: " . $phone . "\n"
                        . "Category: " . $age_cat . "\n"
                        . "Package Chosen: " . $pkg_name . " (" . $total_sess . " sessions)\n"
                        . "Registration Date: " . date('Y-m-d H:i:s') . "\n"
                        . "----------------------------------------\n\n"
                        . "Log in to the Manager Portal to view and update session allocations.\n\n"
                        . "Aqua Learn Swim Center Zambia System";

                    $headers = "From: Aqua Learn Swim Center <no-reply@aqualearn.com>\r\n" .
                               "Reply-To: no-reply@aqualearn.com\r\n" .
                               "X-Mailer: PHP/" . phpversion();

                    @mail($manager_email_address, $email_subject, $email_message, $headers);
                }
            }
            $page = 'login';
        } catch (Exception $e) {
            $err = "Registration failed: Phone number may already be registered.";
        }
    }
}

// MANAGER ADD SESSION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) && $_POST['form_type'] === 'manager_add_session') {
    if (($_SESSION['role'] ?? '') === 'manager') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $count = (int)($_POST['count'] ?? 1);
        if ($client_id > 0 && $count > 0) {
            $stmt = $pdo->prepare("UPDATE client SET total_sessions = total_sessions + ? WHERE id = ?");
            $stmt->execute([$count, $client_id]);
            $msg = "Added $count session(s) to swimmer package successfully.";
        }
    }
}

// MANAGER DEDUCT SESSION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) && $_POST['form_type'] === 'manager_deduct_session') {
    if (($_SESSION['role'] ?? '') === 'manager') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        if ($client_id > 0) {
            $stmt = $pdo->prepare("INSERT INTO attendance (client_id, staff_id, date, status, notes) VALUES (?, ?, CURRENT_DATE, 'Present', 'Deducted by Manager')");
            $stmt->execute([$client_id, $_SESSION['user_id'] ?? null]);
            $msg = "Deducted 1 session from swimmer package successfully.";
        }
    }
}

// MANAGER DELETE SWIMMER / CLIENT
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) && $_POST['form_type'] === 'delete_client') {
    if (($_SESSION['role'] ?? '') === 'manager') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        if ($client_id > 0) {
            $pdo->prepare("DELETE FROM attendance WHERE client_id = ?")->execute([$client_id]);
            $pdo->prepare("DELETE FROM progress_records WHERE client_id = ?")->execute([$client_id]);
            $pdo->prepare("DELETE FROM client WHERE id = ?")->execute([$client_id]);
            $msg = "Swimmer profile deleted successfully.";
        }
    }
}

// MANAGER DELETE COACH / STAFF
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) && $_POST['form_type'] === 'delete_coach') {
    if (($_SESSION['role'] ?? '') === 'manager') {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        if ($staff_id > 0) {
            $pdo->prepare("DELETE FROM coach_activity WHERE staff_id = ?")->execute([$staff_id]);
            $pdo->prepare("DELETE FROM staff WHERE id = ?")->execute([$staff_id]);
            $msg = "Coach account deleted successfully.";
        }
    }
}

// COACH SHIFT LOG
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) && $_POST['form_type'] === 'coach_log_shift') {
    $staff_id = $_SESSION['user_id'] ?? null;
    $notes    = trim($_POST['shift_notes'] ?? '');

    if ($staff_id && ($_SESSION['role'] ?? '') === 'coach') {
        $stmt = $pdo->prepare("INSERT INTO coach_activity (staff_id, shift_notes) VALUES (?, ?)");
        $stmt->execute([$staff_id, $notes]);
        $msg = "Coaching session completed & logged successfully! Manager notified.";
    }
}

// COACH ATTENDANCE SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) && $_POST['form_type'] === 'coach_attendance') {
    $client_id = $_POST['client_id'] ?? null;
    $status    = $_POST['status'] ?? 'Present';
    $date      = $_POST['date'] ?? date('Y-m-d');
    $notes     = trim($_POST['notes'] ?? '');

    if ($client_id) {
        $stmt = $pdo->prepare("INSERT INTO attendance (client_id, staff_id, date, status, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$client_id, $_SESSION['user_id'] ?? 1, $date, $status, $notes]);
        $msg = "Attendance recorded successfully.";
    }
}

// COACH PROGRESS SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type']) && $_POST['form_type'] === 'coach_progress') {
    $client_id     = $_POST['client_id'] ?? null;
    $skill_level   = trim($_POST['skill_level'] ?? '');
    $coach_remarks = trim($_POST['coach_remarks'] ?? '');

    if ($client_id && $skill_level !== '') {
        $stmt = $pdo->prepare("INSERT INTO progress_records (client_id, staff_id, skill_level, coach_remarks) VALUES (?, ?, ?, ?)");
        $stmt->execute([$client_id, $_SESSION['user_id'] ?? 1, $skill_level, $coach_remarks]);
        $msg = "Swimmer progress saved successfully.";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aqua Learn Swim Center Zambia</title>
    <style>
        :root {
            --bg-dark: #070e14;
            --card-bg: rgba(15, 26, 36, 0.88);
            --accent-teal: #00f2fe;
            --accent-blue: #4facfe;
            --text-light: #f1f5f9;
            --text-muted: #94a3b8;
            --border-color: rgba(0, 242, 254, 0.28);
            --input-bg: rgba(7, 14, 20, 0.92);
            --success: #10b981;
            --danger: #ef4444;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: var(--bg-dark);
            background-image: 
                linear-gradient(180deg, rgba(7, 14, 20, 0.82) 0%, rgba(7, 14, 20, 0.94) 100%),
                url('aqua_water_confidence.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: var(--text-light);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }

        .navbar {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem 1.5rem;
            background: rgba(15, 26, 36, 0.92);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.6);
            text-align: center;
        }

        .brand-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin-bottom: 8px;
        }

        .brand-logo-img {
            height: 65px;
            width: auto;
            object-fit: contain;
            mix-blend-mode: screen;
            filter: drop-shadow(0 0 10px rgba(0, 242, 254, 0.5));
            display: block;
            margin: 0 auto;
        }

        .brand-header-title {
            font-size: 2.2rem;
            font-weight: 900;
            letter-spacing: 4px;
            text-transform: uppercase;
            background: linear-gradient(135deg, #ffffff 20%, var(--accent-teal) 100%);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            -webkit-text-fill-color: transparent;
            margin: 0;
            line-height: 1;
        }

        .brand-header-sub {
            font-size: 0.82rem;
            color: var(--accent-teal);
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 700;
            display: block;
            margin-top: 2px;
        }

        .brand-motto {
            font-size: 0.78rem;
            color: #e2e8f0;
            font-style: italic;
            margin-top: 2px;
            display: block;
            opacity: 0.9;
        }

        .nav-links {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
        }

        .container {
            max-width: 1050px;
            margin: 0 auto;
            padding: 20px 15px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 18px;
            box-shadow: 0 0 25px rgba(0, 242, 254, 0.1);
        }

        .clickable-card {
            cursor: pointer;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        .clickable-card:hover {
            transform: translateY(-4px);
            border-color: var(--accent-teal);
            box-shadow: 0 0 30px rgba(0, 242, 254, 0.3);
        }

        .card-title {
            color: var(--accent-teal);
            margin-top: 0;
            font-size: 1.15rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 6px;
        }

        .banner-card-img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            margin-bottom: 12px;
        }

        .photo-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease;
        }

        .photo-card:hover {
            transform: translateY(-3px);
            border-color: var(--accent-teal);
        }

        .photo-card-img {
            width: 100%;
            height: 140px;
            object-fit: cover;
            border-bottom: 1px solid var(--border-color);
        }

        .photo-card-body {
            padding: 10px;
            flex: 1;
        }

        .photo-card-title {
            color: var(--accent-teal);
            margin: 0 0 4px 0;
            font-size: 0.92rem;
        }

        .photo-card-text {
            color: var(--text-muted);
            font-size: 0.78rem;
            line-height: 1.35;
            margin: 0;
        }

        .auth-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 220px);
            padding: 20px;
        }

        .auth-box {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 25px;
            width: 100%;
            max-width: 380px;
            margin: 0 auto;
            box-shadow: 0 0 30px rgba(0, 242, 254, 0.15);
            box-sizing: border-box;
        }

        .form-group {
            margin-bottom: 14px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-muted);
            font-size: 0.82rem;
            font-weight: 600;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 9px 12px;
            background-color: var(--input-bg);
            border: 1px solid rgba(0, 242, 254, 0.25);
            border-radius: 6px;
            color: #fff;
            box-sizing: border-box;
            font-size: 0.9rem;
            outline: none;
        }

        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .password-wrapper input {
            padding-right: 38px !important;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            background: transparent;
            border: none;
            color: var(--accent-teal);
            cursor: pointer;
            font-size: 1.05rem;
            user-select: none;
        }

        .btn-primary, .btn-nav, .btn-danger {
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-teal));
            color: #070e14;
            font-weight: 700;
            padding: 8px 16px;
            font-size: 0.82rem;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            cursor: pointer;
            display: inline-block;
            transition: all 0.2s ease;
        }

        .btn-sm {
            padding: 4px 8px !important;
            font-size: 0.75rem !important;
        }

        .btn-nav {
            background: transparent;
            color: var(--text-light);
            border: 1px solid var(--border-color);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f87171, #ef4444);
            color: #ffffff;
            padding: 4px 8px;
            font-size: 0.75rem;
        }

        .btn-primary:hover, .btn-nav:hover, .btn-danger:hover {
            transform: translateY(-1px);
        }

        .session-badge {
            background: rgba(0, 242, 254, 0.15);
            border: 1px solid var(--accent-teal);
            color: var(--accent-teal);
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.82rem;
            display: inline-block;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.85rem;
        }

        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        th {
            color: var(--accent-teal);
        }

        .d-inline {
            display: inline-block;
            margin: 0;
            padding: 0;
        }

        .actions-flex {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: wrap;
        }

        .fw-600 { font-weight: 600; }
        .text-white-bold { font-weight: 600; color: #ffffff; }
        .text-teal { color: var(--accent-teal); }
        .text-muted-sub { color: var(--text-muted); }
    </style>
</head>
<body>

    <!-- NAVBAR HEADER -->
    <header class="navbar">
        <div class="brand-container">
            <img src="aqua_logo.png" alt="Aqua Learn Swim Center Logo" class="brand-logo-img">
            <h1 class="brand-header-title">AQUALEARN</h1>
            <span class="brand-header-sub">SWIM CENTER ZAMBIA</span>
            <span class="brand-motto">"Swim strong, swim safe, swim to a better you"</span>
        </div>
        <div class="nav-links">
            <?php if ($mode === 'swimmer'): ?>
                <!-- SWIMMER-DIRECT MODE NAVBAR -->
                <?php if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'client'): ?>
                    <a href="index.php?page=client&mode=swimmer" class="btn-primary">My Swimmer Portal</a>
                    <a href="index.php?action=logout&mode=swimmer" class="btn-nav">Logout</a>
                <?php else: ?>
                    <a href="index.php?page=register&mode=swimmer" class="btn-primary">Register Swimmer Account</a>
                    <a href="index.php?page=login&mode=swimmer" class="btn-nav">Swimmer Login</a>
                <?php endif; ?>
            <?php else: ?>
                <!-- FULL WEBSITE NAVBAR -->
                <a href="index.php?page=home" class="btn-nav">Home</a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php $r = $_SESSION['role'] ?? ''; ?>
                    <?php if ($r === 'coach'): ?>
                        <a href="index.php?page=coach" class="btn-primary">Coach Portal</a>
                    <?php elseif ($r === 'manager'): ?>
                        <a href="index.php?page=manager" class="btn-primary">Manager Portal</a>
                    <?php else: ?>
                        <a href="index.php?page=client" class="btn-primary">Swimmer Portal</a>
                    <?php endif; ?>
                    <a href="index.php?action=logout" class="btn-nav">Logout</a>
                <?php else: ?>
                    <a href="index.php?page=login" class="btn-nav">Portal Login</a>
                    <a href="index.php?page=register" class="btn-primary">Register Swimmer</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">

        <!-- GLOBAL ALERT MESSAGES -->
        <?php if ($msg !== ''): ?>
            <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid var(--success); color: var(--success); padding: 10px; border-radius: 6px; margin-bottom: 15px; text-align: center; font-weight: bold;">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($err !== ''): ?>
            <div style="background: rgba(239, 68, 68, 0.2); border: 1px solid var(--danger); color: var(--danger); padding: 10px; border-radius: 6px; margin-bottom: 15px; text-align: center; font-weight: bold;">
                <?= htmlspecialchars($err) ?>
            </div>
        <?php endif; ?>


        <?php if ($page === 'login'): ?>
        <!-- ===================================================================
             LOGIN VIEW
             =================================================================== -->
        <div class="auth-wrapper">
            <div class="auth-box">
                <h2 style="margin-top:0; color: var(--accent-teal); text-align: center;">Portal Secure Login</h2>
                <form method="POST" action="index.php?page=login<?= ($mode === 'swimmer') ? '&mode=swimmer' : '' ?>" autocomplete="off">
                    <input type="hidden" name="form_type" value="login">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" required placeholder="e.g. 0972810511" autocomplete="off" value="">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="loginPass" name="password" required placeholder="••••••••" autocomplete="new-password">
                            <button type="button" class="toggle-password" onclick="togglePassword('loginPass', this)" title="Show/Hide Password">👁️</button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 10px; margin-top: 5px;">Log In</button>
                </form>
                <p style="margin-top:16px; font-size:0.82rem; text-align:center;">
                    Don't have an account? <a href="index.php?page=register<?= ($mode === 'swimmer') ? '&mode=swimmer' : '' ?>" style="color:var(--accent-teal);">Register here</a>
                </p>
            </div>
        </div>


        <?php elseif ($page === 'register'): ?>
        <!-- ===================================================================
             REGISTER VIEW
             =================================================================== -->
        <div class="auth-wrapper">
            <div class="auth-box">
                <h2 style="margin-top:0; color: var(--accent-teal); text-align: center;">Create Swimmer Account</h2>
                <form method="POST" action="index.php?page=register<?= ($mode === 'swimmer') ? '&mode=swimmer' : '' ?>" autocomplete="off">
                    <input type="hidden" name="form_type" value="register">
                    
                    <?php if ($mode === 'swimmer'): ?>
                        <input type="hidden" name="role" value="client">
                    <?php else: ?>
                        <div class="form-group">
                            <label>Role / Account Type</label>
                            <select name="role" required>
                                <option value="client">Client / Swimmer</option>
                                <option value="coach">Coach / Staff</option>
                                <option value="owner">Owner / Manager</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" required placeholder="Full Name" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" required placeholder="e.g. 0972810511" autocomplete="off" value="">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="age_category">
                            <option value="Kid">Kid (30 min sessions)</option>
                            <option value="Adult">Adult (40 min sessions)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Enrollment Package</label>
                        <select name="total_sessions">
                            <option value="15">15 Sessions / Month (Accelerated)</option>
                            <option value="10">10 Sessions / Month (Weekdays+Weekends)</option>
                            <option value="8">8 Sessions / Month (Weekend Only)</option>
                            <option value="4">4 Sessions / Month (Weekend Only)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="regPass" name="password" required placeholder="••••••••" autocomplete="new-password">
                            <button type="button" class="toggle-password" onclick="togglePassword('regPass', this)" title="Show/Hide Password">👁️</button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 10px; margin-top: 5px;">Register Now</button>
                </form>
                <p style="margin-top:16px; font-size:0.82rem; text-align:center;">
                    Already registered? <a href="index.php?page=login<?= ($mode === 'swimmer') ? '&mode=swimmer' : '' ?>" style="color:var(--accent-teal);">Login here</a>
                </p>
            </div>
        </div>


        <?php elseif ($page === 'coach'): ?>
        <!-- ===================================================================
             COACH DASHBOARD VIEW
             =================================================================== -->
        <?php
            if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'coach') {
                echo "<script>window.location.href='index.php?page=login';</script>";
                exit();
            }
            $swimmers = $pdo->query("
                SELECT c.*, 
                       COUNT(CASE WHEN a.status = 'Present' THEN 1 END) AS sessions_used,
                       GREATEST(0, c.total_sessions - COUNT(CASE WHEN a.status = 'Present' THEN 1 END)) AS sessions_remaining
                FROM client c
                LEFT JOIN attendance a ON c.id = a.client_id
                GROUP BY c.id
                ORDER BY c.name ASC
            ")->fetchAll();

            $my_completed_shifts = $pdo->prepare("SELECT * FROM coach_activity WHERE staff_id = ? ORDER BY activity_time DESC");
            $my_completed_shifts->execute([$_SESSION['user_id']]);
            $shift_history = $my_completed_shifts->fetchAll();
        ?>
        <div style="margin-bottom: 15px;">
            <img src="aqua_stroke_technique.jpg" alt="Coach Portal Banner" class="banner-card-img">
            <h2 style="color: #fff; margin:0 0 4px 0;">COACH OPERATIONS PORTAL</h2>
            <p style="color: var(--accent-teal); font-size: 0.85rem; margin:0;">Logged in as: Coach <?= htmlspecialchars($_SESSION['name'] ?? 'Gideon') ?></p>
        </div>

        <div class="card" style="margin-bottom: 20px; border-color: var(--accent-teal);">
            <h3 class="card-title">Finish Coaching Session / Clock Out Shift</h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0;">
                Click here after completing your coaching sessions so the manager can track your active session logs.
            </p>
            <form method="POST" action="index.php?page=coach">
                <input type="hidden" name="form_type" value="coach_log_shift">
                <div class="form-group">
                    <label>Shift Summary / Coaching Notes</label>
                    <input type="text" name="shift_notes" required placeholder="e.g. Conducted morning kids class & stroke drills for 5 swimmers">
                </div>
                <button type="submit" class="btn-primary" style="padding: 10px 20px;">
                    ✅ Complete Coaching Session & Notify Manager
                </button>
            </form>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <h3 class="card-title">Mark Attendance & Deduct Session</h3>
                <form method="POST" action="index.php?page=coach">
                    <input type="hidden" name="form_type" value="coach_attendance">
                    <div class="form-group">
                        <label>Select Swimmer</label>
                        <select name="client_id" required>
                            <option value="">-- Choose Swimmer --</option>
                            <?php foreach ($swimmers as $s): ?>
                                <option value="<?= $s['id'] ?>">
                                    <?= htmlspecialchars($s['name']) ?> (Remaining: <?= $s['sessions_remaining'] ?>/<?= $s['total_sessions'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Attendance Status</label>
                        <select name="status" required>
                            <option value="Present">Present (-1 Session)</option>
                            <option value="Absent">Absent</option>
                            <option value="Excused">Excused</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Session Notes</label>
                        <input type="text" name="notes" placeholder="e.g. Practiced breathing & kicking">
                    </div>
                    <button type="submit" class="btn-primary" style="width: 100%;">Record Attendance</button>
                </form>
            </div>

            <div class="card">
                <h3 class="card-title">Log Swimmer Progress</h3>
                <form method="POST" action="index.php?page=coach">
                    <input type="hidden" name="form_type" value="coach_progress">
                    <div class="form-group">
                        <label>Select Swimmer</label>
                        <select name="client_id" required>
                            <option value="">-- Choose Swimmer --</option>
                            <?php foreach ($swimmers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Current Skill Level / Stage</label>
                        <input type="text" name="skill_level" required placeholder="e.g. Level 2 - Kickboard Glide">
                    </div>
                    <div class="form-group">
                        <label>Coach Assessment / Remarks</label>
                        <textarea name="coach_remarks" rows="3" placeholder="Great horizontal posture..."></textarea>
                    </div>
                    <button type="submit" class="btn-primary" style="width: 100%;">Save Progress Record</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top: 20px;">
            <h3 class="card-title">My Recent Completed Coaching Shifts</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Shift Notes</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($shift_history)): ?>
                            <tr><td colspan="3" style="text-align: center; color: var(--text-muted);">No completed coaching shifts logged yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($shift_history as $sh): ?>
                                <tr>
                                    <td class="fw-600"><?= htmlspecialchars($sh['activity_time']) ?></td>
                                    <td><?= htmlspecialchars($sh['shift_notes'] ?? 'Completed session') ?></td>
                                    <td><span class="session-badge"><?= htmlspecialchars($sh['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>


        <?php elseif ($page === 'client'): ?>
        <!-- ===================================================================
             SWIMMER / CLIENT PORTAL VIEW
             =================================================================== -->
        <?php
            if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
                $redirect = ($mode === 'swimmer') ? 'index.php?page=login&mode=swimmer' : 'index.php?page=login';
                echo "<script>window.location.href='$redirect';</script>";
                exit();
            }
            $client_id = $_SESSION['user_id'];

            $client_stmt = $pdo->prepare("
                SELECT c.*, 
                       COUNT(CASE WHEN a.status = 'Present' THEN 1 END) AS sessions_used,
                       GREATEST(0, c.total_sessions - COUNT(CASE WHEN a.status = 'Present' THEN 1 END)) AS sessions_remaining
                FROM client c
                LEFT JOIN attendance a ON c.id = a.client_id
                WHERE c.id = ?
                GROUP BY c.id
            ");
            $client_stmt->execute([$client_id]);
            $me = $client_stmt->fetch();

            $my_progress = $pdo->prepare("SELECT * FROM progress_records WHERE client_id = ? ORDER BY record_date DESC");
            $my_progress->execute([$client_id]);
            $logs = $my_progress->fetchAll();

            $my_att = $pdo->prepare("SELECT * FROM attendance WHERE client_id = ? ORDER BY date DESC");
            $my_att->execute([$client_id]);
            $att_logs = $my_att->fetchAll();
        ?>
        <div style="margin-bottom: 15px;">
            <img src="aqua_kickboard_glide.jpg" alt="Swimmer Portal Banner" class="banner-card-img">
            <h2 style="color: #fff; margin:0 0 4px 0;">SWIMMER PORTAL</h2>
            <p style="color: var(--accent-teal); font-size: 0.85rem; margin:0;">Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'Swimmer') ?>!</p>
        </div>

        <div class="card" style="text-align: center; margin-bottom: 20px; border-color: var(--accent-teal);">
            <h3 class="card-title">My Enrollment Package Counter</h3>
            <p style="color: var(--text-muted); font-size: 0.82rem; margin: 4px 0;">
                Package: <strong><?= htmlspecialchars($me['package_name'] ?? '15 Sessions') ?></strong>
            </p>
            <div style="font-size: 2.5rem; font-weight: 900; color: var(--accent-teal); margin: 10px 0;">
                <?= $me['sessions_remaining'] ?> <span style="font-size: 1rem; color: #fff;">/ <?= $me['total_sessions'] ?> Sessions Left</span>
            </div>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 6px;">
                Completed Sessions: <strong><?= $me['sessions_used'] ?></strong>
            </p>
            <p style="font-size: 0.75rem; color: var(--accent-teal); font-style: italic; margin: 0;">
                ℹ️ Session allocations and deductions are managed directly by Swim Center Management.
            </p>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <h3 class="card-title">My Progress Reports</h3>
                <?php if (empty($logs)): ?>
                    <p style="color: var(--text-muted); font-size: 0.82rem;">No progress logs recorded yet by Coach Gideon.</p>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <div style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 8px; margin-bottom: 8px;">
                            <strong style="color: var(--accent-teal);"><?= htmlspecialchars($l['skill_level']) ?></strong>
                            <p style="margin: 2px 0; font-size: 0.78rem; color: var(--text-muted);"><?= htmlspecialchars($l['coach_remarks'] ?? '') ?></p>
                            <span style="font-size: 0.7rem; opacity: 0.7;"><?= htmlspecialchars($l['record_date'] ?? '') ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 class="card-title">My Attendance History</h3>
                <?php if (empty($att_logs)): ?>
                    <p style="color: var(--text-muted); font-size: 0.82rem;">No attendance records found.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($att_logs as $a): ?>
                                <tr>
                                    <td><?= htmlspecialchars($a['date']) ?></td>
                                    <td style="color: var(--accent-teal);"><?= htmlspecialchars($a['status']) ?></td>
                                    <td><?= htmlspecialchars($a['notes'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>


        <?php elseif ($page === 'manager'): ?>
        <!-- ===================================================================
             MANAGER PORTAL VIEW
             =================================================================== -->
        <?php
            if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
                echo "<script>window.location.href='index.php?page=login';</script>";
                exit();
            }

            $tab = $_GET['tab'] ?? 'swimmers';

            $total_swimmers = $pdo->query("SELECT COUNT(*) FROM client")->fetchColumn();
            $total_staff    = $pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();

            if ($tab === 'coaches') {
                $all_coaches = $pdo->query("
                    SELECT s.*, 
                           COUNT(ca.id) AS completed_shifts,
                           MAX(ca.activity_time) AS last_active
                    FROM staff s
                    LEFT JOIN coach_activity ca ON s.id = ca.staff_id
                    GROUP BY s.id
                    ORDER BY s.name ASC
                ")->fetchAll();

                $recent_coach_activity = $pdo->query("
                    SELECT ca.*, s.name AS coach_name, s.phone AS coach_phone
                    FROM coach_activity ca
                    JOIN staff s ON ca.staff_id = s.id
                    ORDER BY ca.activity_time DESC
                    LIMIT 15
                ")->fetchAll();
            } else {
                $all_swimmers = $pdo->query("
                    SELECT c.*, 
                           COUNT(CASE WHEN a.status = 'Present' THEN 1 END) AS sessions_used,
                           GREATEST(0, c.total_sessions - COUNT(CASE WHEN a.status = 'Present' THEN 1 END)) AS sessions_remaining
                    FROM client c
                    LEFT JOIN attendance a ON c.id = a.client_id
                    GROUP BY c.id
                    ORDER BY c.name ASC
                ")->fetchAll();
            }
        ?>
        <div style="margin-bottom: 15px;">
            <img src="aqua_facility_courtyard.jpg" alt="Manager Portal Banner" class="banner-card-img">
            <h2 style="color: #fff; margin:0 0 4px 0;">MANAGER DASHBOARD</h2>
            <p style="color: var(--accent-teal); font-size: 0.85rem; margin:0;">Aqua Learn Swim Center Operations Management</p>
        </div>

        <div class="dashboard-grid" style="margin-bottom: 20px;">
            <a href="index.php?page=manager&tab=swimmers" style="text-decoration: none;">
                <div class="card clickable-card" style="text-align: center; <?= ($tab === 'swimmers') ? 'border-color: var(--accent-teal); box-shadow: 0 0 25px rgba(0, 242, 254, 0.35);' : 'opacity: 0.7;' ?>">
                    <h3 class="card-title">Total Registered Swimmers</h3>
                    <p style="font-size: 2.2rem; font-weight: 900; color: var(--accent-teal); margin: 10px 0;"><?= $total_swimmers ?></p>
                    <span style="color: <?= ($tab === 'swimmers') ? 'var(--accent-teal)' : 'var(--text-muted)' ?>; font-size: 0.78rem; font-weight: bold;">
                        <?= ($tab === 'swimmers') ? '▶ Viewing Swimmers' : '👉 Click to View Swimmers' ?>
                    </span>
                </div>
            </a>

            <a href="index.php?page=manager&tab=coaches" style="text-decoration: none;">
                <div class="card clickable-card" style="text-align: center; <?= ($tab === 'coaches') ? 'border-color: var(--accent-teal); box-shadow: 0 0 25px rgba(0, 242, 254, 0.35);' : 'opacity: 0.7;' ?>">
                    <h3 class="card-title">Total Active Coaches</h3>
                    <p style="font-size: 2.2rem; font-weight: 900; color: var(--accent-teal); margin: 10px 0;"><?= $total_staff ?></p>
                    <span style="color: <?= ($tab === 'coaches') ? 'var(--accent-teal)' : 'var(--text-muted)' ?>; font-size: 0.78rem; font-weight: bold;">
                        <?= ($tab === 'coaches') ? '▶ Viewing Active Coaches' : '👉 Click to View Active Coaches' ?>
                    </span>
                </div>
            </a>
        </div>

        <?php if ($tab === 'coaches'): ?>
        <div class="card" style="border: 1px solid var(--accent-teal);">
            <h3 class="card-title">👨‍🏫 Active Coaches Roster</h3>
            <div style="overflow-x: auto; margin-bottom: 20px;">
                <table>
                    <thead>
                        <tr>
                            <th>Coach Name</th>
                            <th>Phone Number</th>
                            <th>Completed Shifts</th>
                            <th>Last Active Timestamp</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_coaches)): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No coaches registered yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_coaches as $coach): ?>
                                <tr>
                                    <td class="text-white-bold"><?= htmlspecialchars($coach['name']) ?></td>
                                    <td><?= htmlspecialchars($coach['phone'] ?? 'N/A') ?></td>
                                    <td><span class="session-badge"><?= $coach['completed_shifts'] ?> Shifts Logged</span></td>
                                    <td class="text-teal"><?= htmlspecialchars($coach['last_active'] ?? 'Never Logged') ?></td>
                                    <td>
                                        <form method="POST" action="index.php?page=manager&tab=coaches" class="d-inline" onsubmit="return confirm('Delete coach?');">
                                            <input type="hidden" name="form_type" value="delete_coach">
                                            <input type="hidden" name="staff_id" value="<?= $coach['id'] ?>">
                                            <button type="submit" class="btn-danger">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h4 style="color: var(--accent-teal); margin: 12px 0 6px 0; font-size: 0.95rem;">Recent Coach Shift Activity Logs</h4>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Coach Name</th>
                            <th>Phone Number</th>
                            <th>Shift Summary / Activity Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_coach_activity)): ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No coaching shifts completed yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_coach_activity as $act): ?>
                                <tr>
                                    <td><?= htmlspecialchars($act['activity_time']) ?></td>
                                    <td class="fw-600"><?= htmlspecialchars($act['coach_name']) ?></td>
                                    <td><?= htmlspecialchars($act['coach_phone'] ?? 'N/A') ?></td>
                                    <td class="text-muted-sub"><?= htmlspecialchars($act['shift_notes'] ?? 'Completed session') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <div class="card">
            <h3 class="card-title">🏊 Swimmer Package & Session Management</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Swimmer Name</th>
                            <th>Phone Number</th>
                            <th>Package</th>
                            <th>Total</th>
                            <th>Used</th>
                            <th>Remaining</th>
                            <th style="min-width: 210px;">Manager Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_swimmers)): ?>
                            <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No swimmers enrolled yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_swimmers as $sw): ?>
                                <tr>
                                    <td class="fw-600"><?= htmlspecialchars($sw['name']) ?></td>
                                    <td><?= htmlspecialchars($sw['phone'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($sw['package_name'] ?? '15 Sessions') ?></td>
                                    <td><?= $sw['total_sessions'] ?></td>
                                    <td><?= $sw['sessions_used'] ?></td>
                                    <td><span class="session-badge"><?= $sw['sessions_remaining'] ?> Left</span></td>
                                    <td>
                                        <div class="actions-flex">
                                            <form method="POST" action="index.php?page=manager&tab=swimmers" class="d-inline">
                                                <input type="hidden" name="form_type" value="manager_add_session">
                                                <input type="hidden" name="client_id" value="<?= $sw['id'] ?>">
                                                <input type="hidden" name="count" value="1">
                                                <button type="submit" class="btn-primary btn-sm" title="Add 1 session to swimmer package">+1 Session</button>
                                            </form>
                                            
                                            <form method="POST" action="index.php?page=manager&tab=swimmers" class="d-inline" onsubmit="return confirm('Deduct 1 session?');">
                                                <input type="hidden" name="form_type" value="manager_deduct_session">
                                                <input type="hidden" name="client_id" value="<?= $sw['id'] ?>">
                                                <button type="submit" class="btn-nav btn-sm" style="border-color: var(--accent-teal); color: var(--accent-teal);" title="Deduct 1 session" <?= ($sw['sessions_remaining'] <= 0) ? 'disabled' : '' ?>>-1 Deduct</button>
                                            </form>

                                            <form method="POST" action="index.php?page=manager&tab=swimmers" class="d-inline" onsubmit="return confirm('Delete swimmer?');">
                                                <input type="hidden" name="form_type" value="delete_client">
                                                <input type="hidden" name="client_id" value="<?= $sw['id'] ?>">
                                                <button type="submit" class="btn-danger">Remove</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>


        <?php else: ?>
        <!-- ===================================================================
             HOMEPAGE VIEW
             =================================================================== -->
        <section class="card" style="text-align: center; margin-bottom: 20px; padding: 22px 15px;">
            <h1 style="font-size: 1.8rem; font-weight: 900; color: #fff; margin: 4px 0;">DISCOVER THE JOY OF SWIMMING!</h1>
            <p style="color: var(--accent-teal); font-size: 0.88rem; font-weight: 600; margin-bottom: 14px;">
                Millennium Village's Premier Swimming Academy for Kids & Adults
            </p>
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="index.php?page=register" class="btn-primary">Enroll Swimmer Now</a>
                <a href="index.php?page=login" class="btn-nav" style="border-color: var(--accent-teal); color: var(--accent-teal);">Member Portal</a>
            </div>
        </section>

        <!-- PHOTO SHOWCASE GRID -->
        <section style="margin-bottom: 25px;">
            <div style="text-align: center; margin-bottom: 14px;">
                <h2 style="font-size: 1.3rem; color: #fff; margin: 0 0 3px 0;">FEATURED SWIMMER ACTION SHOTS</h2>
                <p style="color: var(--accent-teal); font-size: 0.8rem; font-weight: 600; margin: 0;">Real Progress & Water Confidence at Millennium Village</p>
            </div>

            <div class="dashboard-grid">
                <div class="photo-card">
                    <img src="aqua_water_confidence.jpg" alt="Water Confidence" class="photo-card-img">
                    <div class="photo-card-body">
                        <h3 class="photo-card-title">Water Confidence & Safety</h3>
                        <p class="photo-card-text">Building calm buoyancy, edge comfort, and natural floating control in the water.</p>
                    </div>
                </div>

                <div class="photo-card">
                    <img src="aqua_hero_splash.jpg" alt="Hero Splash" class="photo-card-img">
                    <div class="photo-card-body">
                        <h3 class="photo-card-title">Aqua Hero Splash</h3>
                        <p class="photo-card-text">High-energy water confidence and splashing joy in the pool.</p>
                    </div>
                </div>

                <div class="photo-card">
                    <img src="aqua_kickboard_glide.jpg" alt="Kickboard Glide" class="photo-card-img">
                    <div class="photo-card-body">
                        <h3 class="photo-card-title">Aqua Kickboard Glide</h3>
                        <p class="photo-card-text">Horizontal body alignment, kickboard propulsion, and stroke rotation.</p>
                    </div>
                </div>

                <div class="photo-card">
                    <img src="aqua_headon_swimmer.jpg" alt="Head-on Swimmer" class="photo-card-img">
                    <div class="photo-card-body">
                        <h3 class="photo-card-title">Aqua Head-on Swimmer</h3>
                        <p class="photo-card-text">Active lap practice focused directly on kicking technique and endurance.</p>
                    </div>
                </div>

                <div class="photo-card">
                    <img src="aqua_happy_splash.jpg" alt="Happy Splash" class="photo-card-img">
                    <div class="photo-card-body">
                        <h3 class="photo-card-title">Recreational Free Play</h3>
                        <p class="photo-card-text">Every guided training session includes complimentary splash play time.</p>
                    </div>
                </div>

                <div class="photo-card">
                    <img src="aqua_facility_courtyard.jpg" alt="Facility Courtyard" class="photo-card-img">
                    <div class="photo-card-body">
                        <h3 class="photo-card-title">Scenic Courtyard Pool</h3>
                        <p class="photo-card-text">Private, heated pool facility at Millennium Village with deck supervision.</p>
                    </div>
                </div>

                <div class="photo-card">
                    <img src="aqua_stroke_technique.jpg" alt="Stroke Technique" class="photo-card-img">
                    <div class="photo-card-body">
                        <h3 class="photo-card-title">Stroke Technique & Drills</h3>
                        <p class="photo-card-text">Synchronized kickboard drills designed for leg strength and rhythmic breathing.</p>
                    </div>
                </div>

                <div class="photo-card">
                    <img src="aqua_group_class.jpg" alt="Group Class" class="photo-card-img">
                    <div class="photo-card-body">
                        <h3 class="photo-card-title">Guided Small Group Class</h3>
                        <p class="photo-card-text">Small class sizes ensuring every student receives focused coaching instruction.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- PRICING & SCHEDULE -->
        <section style="margin-bottom: 25px;">
            <div style="text-align: center; margin-bottom: 14px;">
                <h2 style="font-size: 1.3rem; color: #fff; margin: 0 0 3px 0;">SWIMMING TRAINING FEES & SCHEDULE</h2>
                <p style="color: var(--accent-teal); font-size: 0.8rem; font-weight: 600; margin: 0;">Location: Millennium Village | Call or WhatsApp before visiting</p>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <h3 class="card-title">Weekend Only Packages</h3>
                    <div style="margin-bottom: 8px;">
                        <h4 style="color: #fff; margin: 0 0 2px 0; font-size: 0.9rem;">4 Lessons / Month</h4>
                        <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0 0 2px 0;">Saturday <em>OR</em> Sunday</p>
                        <p style="font-size: 0.95rem; font-weight: bold; margin: 0;">
                            Kids: <span style="color: var(--accent-teal);">K950</span> | Adults: <span style="color: var(--accent-teal);">K1,350</span>
                        </p>
                    </div>
                    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 6px 0;">
                    <div>
                        <h4 style="color: #fff; margin: 0 0 2px 0; font-size: 0.9rem;">8 Lessons / Month</h4>
                        <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0 0 2px 0;">Saturday <em>AND</em> Sunday</p>
                        <p style="font-size: 0.95rem; font-weight: bold; margin: 0;">
                            Kids: <span style="color: var(--accent-teal);">K1,350</span> | Adults: <span style="color: var(--accent-teal);">K1,750</span>
                        </p>
                    </div>
                </div>

                <div class="card" style="border-color: var(--accent-teal);">
                    <h3 class="card-title">Weekdays + Weekends</h3>
                    <div style="margin-bottom: 8px;">
                        <h4 style="color: #fff; margin: 0 0 2px 0; font-size: 0.9rem;">10 Lessons / Month</h4>
                        <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0 0 2px 0;">Flexible Weekday & Weekend Slots</p>
                        <p style="font-size: 0.95rem; font-weight: bold; margin: 0;">
                            Kids: <span style="color: var(--accent-teal);">K1,600</span> | Adults: <span style="color: var(--accent-teal);">K1,800</span>
                        </p>
                    </div>
                    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 6px 0;">
                    <div>
                        <h4 style="color: #fff; margin: 0 0 2px 0; font-size: 0.9rem;">15 Sessions / Month</h4>
                        <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0 0 2px 0;">Accelerated Progress Program</p>
                        <p style="font-size: 0.95rem; font-weight: bold; margin: 0;">
                            Kids: <span style="color: var(--accent-teal);">K1,850</span> | Adults: <span style="color: var(--accent-teal);">K2,150</span>
                        </p>
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title">Training Schedule & Timings</h3>
                    <p style="margin: 4px 0; font-size: 0.8rem;"><strong>Mon – Fri:</strong> 07:00–15:00 | 17:00–19:30</p>
                    <p style="margin: 4px 0; font-size: 0.8rem;"><strong>Saturday:</strong> 07:00 – 17:00</p>
                    <p style="margin: 4px 0; font-size: 0.8rem;"><strong>Sunday:</strong> 12:00 – 17:00</p>
                    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 6px 0;">
                    <p style="margin: 0; font-size: 0.8rem;">
                        <strong>Session Duration:</strong> Kids: 30 min | Adults: 40 min
                    </p>
                </div>
            </div>
        </section>

        <!-- CONTACT SECTION -->
        <section class="card" style="text-align: center; border: 1px solid var(--accent-teal); padding: 18px 12px;">
            <h2 style="color: #fff; margin: 0 0 3px 0; font-size: 1.15rem;">READY TO START YOUR SWIMMING JOURNEY?</h2>
            <p style="color: var(--accent-teal); font-size: 0.8rem; font-weight: 700; margin: 0 0 6px 0;">
                "Swim strong, swim safe, swim to a better you"
            </p>
            <p style="font-size: 1.05rem; font-weight: 900; color: #fff; margin: 4px 0;">
                Head Coach Gideon: <a href="tel:0972810511" style="color: var(--accent-teal); text-decoration: none;">0972810511</a>
            </p>
            <p style="color: var(--text-muted); font-size: 0.75rem; margin-bottom: 12px;">
                📍 Location: Millennium Village Pool | Please call or WhatsApp before visiting.
            </p>

            <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                <a href="https://wa.me/260972810511" target="_blank" class="btn-primary">WhatsApp Coach Gideon</a>
                <a href="index.php?page=register" class="btn-nav" style="border-color: var(--accent-teal); color: var(--accent-teal);">Register Swimmer Account</a>
            </div>
        </section>
        <?php endif; ?>

    </div>

    <footer style="text-align: center; padding: 15px 10px; margin-top: 25px; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 0.72rem; background: rgba(7, 14, 20, 0.95);">
        <p style="margin: 0 0 2px 0;">&copy; <?= date('Y') ?> Aqua Learn Swim Center Zambia. Millennium Village Pool Facility.</p>
        <p style="color: var(--accent-teal); font-style: italic; margin: 0;">"Swim strong, swim safe, swim to a better you"</p>
    </footer>

    <script>
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                btn.textContent = "🙈";
            } else {
                input.type = "password";
                btn.textContent = "👁️";
            }
        }
    </script>

</body>
</html>
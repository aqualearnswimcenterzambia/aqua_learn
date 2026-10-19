<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: login.php");
        exit();
    }
}

function require_role($allowed_roles = []) {
    require_login();
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        header("Location: index.php?error=unauthorized");
        exit();
    }
}
?>
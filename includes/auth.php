<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: /erp/login.php");
    exit();
}

// Prevent crash if file is loaded twice
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return $_SESSION['role'] === 'Admin';
    }
}
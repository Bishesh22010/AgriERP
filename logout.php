<?php
// logout.php
session_start();
require_once 'includes/db.php';

// Log the logout action if user was logged in
if (isset($_SESSION['user_id'])) {
    $log_stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, module, ip_address) VALUES (?, 'Logged Out', 'Authentication', ?)");
    $log_stmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login
header("Location: login.php");
exit();
?>
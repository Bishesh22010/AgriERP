<?php
// debug.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2 style='color:green;'>1. PHP is executing perfectly.</h2>";

require_once 'includes/db.php';
echo "<h2 style='color:green;'>2. Database connected successfully.</h2>";

require_once 'includes/auth.php';
echo "<h2 style='color:green;'>3. Authentication passed. User ID: " . ($_SESSION['user_id'] ?? 'Missing') . "</h2>";

require_once 'includes/header.php';
echo "<h2 style='color:green; position:relative; z-index:9999;'>4. Header loaded successfully.</h2>";

require_once 'includes/sidebar.php';
echo "<h2 style='color:green; position:relative; z-index:9999;'>5. Sidebar loaded successfully.</h2>";

// Test a simple dashboard query
$stmt = $pdo->query("SELECT COUNT(id) as c FROM users");
$count = $stmt->fetch()['c'];
echo "<h2 style='color:green; position:relative; z-index:9999;'>6. Queries working. Users found: $count</h2>";

echo "<h1 style='color:blue; position:relative; z-index:9999;'>If you see this, the core system works! The issue is just HTML/CSS formatting.</h1>";
?>
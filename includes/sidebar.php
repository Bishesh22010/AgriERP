<?php
// includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar" id="app-sidebar">
    <div class="sidebar-header" onclick="toggleSidebar()" title="Toggle Sidebar">
        <span class="nav-icon">☰</span>
        <span class="nav-text">AgriERP</span>
    </div>
    <nav class="sidebar-nav">
        <a href="index.php" class="nav-item <?= $current_page == 'index.php' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="bardan_purchase.php" class="nav-item <?= $current_page == 'bardan_purchase.php' ? 'active' : '' ?>">
            <span class="nav-icon">📥</span>
            <span class="nav-text">Bardan Purchase</span>
        </a>
        <a href="bardan_sell.php" class="nav-item <?= $current_page == 'bardan_sell.php' ? 'active' : '' ?>">
            <span class="nav-icon">📤</span>
            <span class="nav-text">Bardan Sell</span>
        </a>
        <a href="purchase_book.php" class="nav-item <?= $current_page == 'purchase_book.php' ? 'active' : '' ?>">
            <span class="nav-icon">🌾</span>
            <span class="nav-text">Purchase Book</span>
        </a>
        <a href="bill_board.php" class="nav-item <?= $current_page == 'bill_board.php' ? 'active' : '' ?>">
            <span class="nav-icon">🧾</span>
            <span class="nav-text">Bill Board</span>
        </a>
        <a href="reports.php" class="nav-item <?= $current_page == 'reports.php' ? 'active' : '' ?>">
            <span class="nav-icon">📈</span>
            <span class="nav-text">Reports</span>
        </a>
        <?php if(isAdmin()): ?>
        <a href="settings.php" class="nav-item <?= $current_page == 'settings.php' ? 'active' : '' ?>">
            <span class="nav-icon">⚙️</span>
            <span class="nav-text">Settings & Master</span>
        </a>
        <?php endif; ?>
    </nav>
</aside>

<div class="main-content">
    <header class="topbar">
        <div class="topbar-left">
            </div>
        <div class="topbar-right">
            <div class="user-profile">
                👤 <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?> (<?= htmlspecialchars($_SESSION['role'] ?? 'Staff') ?>)
            </div>
            <a href="logout.php" class="btn-logout" onclick="return confirm('Are you sure you want to log out?');">Logout</a>
        </div>
    </header>
    <main class="content-area">
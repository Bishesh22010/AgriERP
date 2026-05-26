<?php
// index.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Force strict absolute paths to prevent inclusion crashes
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php'; 
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

try {
    // Total Current Stock
    $stmt = $pdo->query("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'IN' THEN quantity ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN transaction_type = 'OUT' THEN quantity ELSE 0 END), 0) AS current_stock
        FROM bardan_stock_ledger
    ");
    $current_stock = $stmt->fetch()['current_stock'] ?? 0;

    // Today's Bardan Purchases
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_nos), 0) as today_purchased FROM bardan_purchase WHERE purchase_date = CURDATE()");
    $today_purchased = $stmt->fetch()['today_purchased'] ?? 0;

    // Today's Bardan Sales
    $stmt = $pdo->query("SELECT COALESCE(SUM(number_of_bardans), 0) as today_sold FROM bardan_sell WHERE sell_date = CURDATE()");
    $today_sold = $stmt->fetch()['today_sold'] ?? 0;

    // Total Registered Farmers
    $stmt = $pdo->query("SELECT COUNT(id) as farmer_count FROM farmers");
    $farmer_count = $stmt->fetch()['farmer_count'] ?? 0;

    // Recent Transactions
    $stmt = $pdo->query("SELECT transaction_type, quantity, transaction_date, created_at FROM bardan_stock_ledger ORDER BY created_at DESC LIMIT 5");
    $recent_transactions = $stmt->fetchAll();

} catch (PDOException $e) {
    die("<div style='padding:20px; color:red; background:#fff; z-index:9999; position:relative;'>Database Error: " . $e->getMessage() . "</div>");
}
?>

<div class="flex-between" style="margin-bottom: 20px;">
    <h1 class="page-title" style="margin-bottom: 0;">Enterprise Dashboard</h1>
    <div>
        <span class="badge badge-in">Active Session</span>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-title">Total Bardan Stock</div>
        <div class="kpi-value" style="color: <?= $current_stock < 500 ? 'var(--danger)' : 'var(--primary)' ?>;">
            <?= number_format($current_stock) ?>
        </div>
        <?php if($current_stock < 500): ?>
            <small style="color: var(--danger); margin-top: 5px;">⚠️ Low Stock Warning</small>
        <?php endif; ?>
    </div>
    
    <div class="kpi-card">
        <div class="kpi-title">Today's Purchases (Inward)</div>
        <div class="kpi-value" style="color: var(--success);"><?= number_format($today_purchased) ?></div>
    </div>
    
    <div class="kpi-card">
        <div class="kpi-title">Today's Sales (Outward)</div>
        <div class="kpi-value" style="color: var(--warning);"><?= number_format($today_sold) ?></div>
    </div>
    
    <div class="kpi-card">
        <div class="kpi-title">Total Registered Farmers</div>
        <div class="kpi-value"><?= number_format($farmer_count) ?></div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        Recent Inventory Transactions
    </div>
    <table>
        <thead>
            <tr>
                <th>Date / Time</th>
                <th>Transaction Type</th>
                <th>Quantity</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($recent_transactions) > 0): ?>
                <?php foreach ($recent_transactions as $txn): ?>
                    <tr>
                        <td><?= date('d M Y, h:i A', strtotime($txn['created_at'])) ?></td>
                        <td>
                            <span class="badge <?= $txn['transaction_type'] == 'IN' ? 'badge-in' : 'badge-out' ?>">
                                <?= $txn['transaction_type'] == 'IN' ? 'Purchase (IN)' : 'Sell (OUT)' ?>
                            </span>
                        </td>
                        <td><?= number_format($txn['quantity']) ?></td>
                        <td><span style="color: var(--success);">✔ Completed</span></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No recent transactions found. Begin by adding a Purchase or Sale.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
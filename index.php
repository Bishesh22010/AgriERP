<?php
// index.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php'; 
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

try {
    // 1. Total Current Stock
    $stmt = $pdo->query("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'IN' THEN quantity ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN transaction_type = 'OUT' THEN quantity ELSE 0 END), 0) AS current_stock
        FROM bardan_stock_ledger
    ");
    $current_stock = $stmt->fetch()['current_stock'] ?? 0;

    // 2. Today's Bardan Purchases
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_nos), 0) as today_purchased FROM bardan_purchase WHERE purchase_date = CURDATE()");
    $today_purchased = $stmt->fetch()['today_purchased'] ?? 0;

    // 3. Today's Bardan Sales
    $stmt = $pdo->query("SELECT COALESCE(SUM(number_of_bardans), 0) as today_sold FROM bardan_sell WHERE sell_date = CURDATE()");
    $today_sold = $stmt->fetch()['today_sold'] ?? 0;

    // 4. Total Registered Farmers & Villages
    $farmer_count = $pdo->query("SELECT COUNT(id) FROM farmers")->fetchColumn();
    $village_count = $pdo->query("SELECT COUNT(id) FROM villages")->fetchColumn();

    // 5. Farmers with Pending Bardans (Owe us bags)
    $stmtPending = $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT f.id,
            (
                 COALESCE((SELECT SUM(number_of_bardans) FROM bardan_sell WHERE farmer_id = f.id), 0) +
                 COALESCE((SELECT SUM(bardan_qty) FROM bardan_ledger WHERE farmer_id = f.id AND transaction_type IN ('SELL', 'HAVALO_IN')), 0)
            ) -
            (
                 COALESCE((SELECT SUM(pi.bardan_used) FROM purchase_book_items pi JOIN purchase_book pb ON pi.purchase_id = pb.id WHERE pb.farmer_id = f.id), 0) +
                 COALESCE((SELECT SUM(bardan_qty) FROM bardan_ledger WHERE farmer_id = f.id AND transaction_type IN ('RETURN', 'HAVALO_OUT')), 0)
            ) as balance
            FROM farmers f
        ) as balances WHERE balance > 0
    ");
    $pending_farmers_count = $stmtPending->fetchColumn();

    // 6. Recent Stock Transactions
    $recent_transactions = $pdo->query("SELECT transaction_type, quantity, transaction_date, created_at FROM bardan_stock_ledger ORDER BY created_at DESC LIMIT 5")->fetchAll();

    // 7. Recent Havalo Transfers
    $recent_havalos = $pdo->query("
        SELECT h.havalo_bill_no, h.bardan_qty, h.created_at, f1.full_name as from_name, f2.full_name as to_name 
        FROM bardan_havalo h
        JOIN farmers f1 ON h.from_farmer_id = f1.id
        JOIN farmers f2 ON h.to_farmer_id = f2.id
        ORDER BY h.created_at DESC LIMIT 5
    ")->fetchAll();

} catch (PDOException $e) {
    die("<div style='padding:20px; color:red; background:#fff;'>Database Error: " . $e->getMessage() . "</div>");
}
?>

<style>
    /* Dashboard specific grid enhancements */
    .dashboard-tables { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    @media (max-width: 1024px) {
        .dashboard-tables { grid-template-columns: 1fr; }
    }
    
    /* Clean blue/yellow accent adjustments */
    .kpi-card { border-top: 3px solid transparent; transition: 0.2s; }
    .kpi-card:hover { border-top-color: #005A9E; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transform: translateY(-2px); }
    .kpi-card.warning-card:hover { border-top-color: #d13438; }
</style>

<div class="flex-between" style="margin-bottom: 20px;">
    <h1 class="page-title" style="margin-bottom: 0;">Enterprise Dashboard</h1>
    <div>
        <span class="badge badge-in" style="background: #e1dfdd; color: #005A9E; font-size: 13px;">
            <span style="color: #FFC107;">✦</span> System Active
        </span>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-title">Total Bardan Stock</div>
        <div class="kpi-value" style="color: <?= $current_stock < 500 ? 'var(--danger)' : 'var(--primary)' ?>;">
            <?= number_format($current_stock) ?>
        </div>
        <?php if($current_stock < 500): ?>
            <small style="color: var(--danger); margin-top: 5px; font-weight:600;">⚠️ Low Stock Warning</small>
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
        <div class="kpi-title">Network Reach</div>
        <div class="kpi-value" style="color: var(--text-main);">
            <?= number_format($farmer_count) ?> <span style="font-size:14px; font-weight:normal; color:var(--text-muted);">Farmers in</span> 
            <?= number_format($village_count) ?> <span style="font-size:14px; font-weight:normal; color:var(--text-muted);">Villages</span>
        </div>
    </div>

    <div class="kpi-card warning-card">
        <div class="kpi-title">Pending Bardan Returns</div>
        <div class="kpi-value" style="color: var(--danger);">
            <?= number_format($pending_farmers_count) ?> 
            <span style="font-size:14px; font-weight:normal; color:var(--text-muted);">Farmers owe bags</span>
        </div>
        <small style="color: var(--text-muted); margin-top: 5px;">Check Khatavahi for details</small>
    </div>
</div>

<div class="dashboard-tables">
    
    <div class="table-container" style="margin-bottom: 0;">
        <div class="table-header">
            Warehouse Transactions
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Type</th>
                    <th style="text-align:right;">Qty</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($recent_transactions) > 0): ?>
                    <?php foreach ($recent_transactions as $txn): ?>
                        <tr>
                            <td style="font-size:12px; color:var(--text-muted);"><?= date('d M, h:i A', strtotime($txn['created_at'])) ?></td>
                            <td>
                                <span class="badge <?= $txn['transaction_type'] == 'IN' ? 'badge-in' : 'badge-out' ?>">
                                    <?= $txn['transaction_type'] ?>
                                </span>
                            </td>
                            <td style="text-align:right; font-weight:600;"><?= number_format($txn['quantity']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 20px;">No warehouse data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-container" style="margin-bottom: 0;">
        <div class="table-header flex-between">
            <span>Recent Havalo Transfers</span>
            <a href="havalo.php" style="font-size:12px; color:var(--primary); text-decoration:none; font-weight:600;">View All &rarr;</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Bill No.</th>
                    <th>Transfer Route</th>
                    <th style="text-align:right;">Bags</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($recent_havalos) > 0): ?>
                    <?php foreach ($recent_havalos as $hav): ?>
                        <tr>
                            <td style="font-weight: 600; font-size:12px; color: var(--primary);"><?= $hav['havalo_bill_no'] ?></td>
                            <td style="font-size: 13px;">
                                <span style="color:var(--danger); font-weight:600;"><?= htmlspecialchars(explode(' ', $hav['from_name'])[0]) ?></span> 
                                <span style="color:var(--text-muted);">→</span> 
                                <span style="color:var(--success); font-weight:600;"><?= htmlspecialchars(explode(' ', $hav['to_name'])[0]) ?></span>
                            </td>
                            <td style="text-align:right;">
                                <span style="background: #e1dfdd; padding: 2px 8px; border-radius: 12px; font-weight: 700; font-size:12px;">
                                    <?= number_format($hav['bardan_qty']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 20px;">No recent transfers.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
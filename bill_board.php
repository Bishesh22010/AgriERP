<?php
// bill_board.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// ---------------------------------------------------------
// 1. FETCH FINANCIAL KPIs
// ---------------------------------------------------------
// A. Total Payouts (Purchases)
$stmtPurchases = $pdo->query("SELECT COALESCE(SUM(final_amount), 0) as total_payout FROM purchase_book");
$total_payout = $stmtPurchases->fetchColumn();

// B. Total Bardans Sold
$stmtSales = $pdo->query("SELECT COALESCE(SUM(number_of_bardans), 0) as total_bardans_sold FROM bardan_sell");
$total_bardans_sold = $stmtSales->fetchColumn();

// C. Today's Total Bills Generated (Both Tables)
$stmtTodayBills = $pdo->query("
    SELECT 
        (SELECT COUNT(id) FROM purchase_book WHERE purchase_date = CURDATE()) + 
        (SELECT COUNT(id) FROM bardan_sell WHERE sell_date = CURDATE())
");
$today_bills = $stmtTodayBills->fetchColumn();


// ---------------------------------------------------------
// 2. FETCH LATEST BILLS FOR BOTH TABS
// ---------------------------------------------------------
// Grain Purchase Bills (Inward)
$purchase_bills = $pdo->query("
    SELECT p.id, p.purchase_no, p.purchase_date, p.final_amount, p.payment_status, f.full_name, f.mobile_no 
    FROM purchase_book p 
    LEFT JOIN farmers f ON p.farmer_id = f.id 
    ORDER BY p.purchase_date DESC, p.id DESC 
    LIMIT 100
")->fetchAll();

// Bardan Sell Bills (Outward)
$sale_bills = $pdo->query("
    SELECT s.id, s.yearly_bill_no, s.bill_year, s.sell_date, s.number_of_bardans, s.used_for, f.full_name, f.mobile_no 
    FROM bardan_sell s 
    LEFT JOIN farmers f ON s.farmer_id = f.id 
    ORDER BY s.sell_date DESC, s.id DESC 
    LIMIT 100
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    /* KPI Mini Cards */
    .mini-kpi-container { display: flex; gap: 20px; margin-bottom: 24px; }
    .mini-kpi { background: var(--card-bg); padding: 20px; border: 1px solid var(--border); border-radius: var(--radius); flex: 1; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .mini-kpi-title { font-size: 12px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; font-weight: 600; letter-spacing: 0.5px; }
    .mini-kpi-value { font-size: 26px; font-weight: 700; color: var(--primary); }

    /* Tabbed Interface Styling */
    .tab-container { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .tab-header { display: flex; border-bottom: 1px solid var(--border); background: #faf9f8; }
    .tab-btn { flex: 1; padding: 16px; border: none; background: none; font-size: 15px; font-weight: 600; color: var(--text-muted); cursor: pointer; transition: 0.2s; border-bottom: 3px solid transparent; outline: none; }
    .tab-btn:hover { background: #f3f2f1; color: var(--primary); }
    .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); background: #fff; }
    
    .tab-content { display: none; padding: 20px; }
    .tab-content.active { display: block; animation: fadeIn 0.3s ease-in-out; }
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* Search Bar */
    .search-wrapper { margin-bottom: 15px; display: flex; justify-content: flex-end; }
    .search-input { width: 350px; padding: 10px 14px; border: 1px solid #ccc; border-radius: var(--radius); outline: none; transition: border 0.2s; font-size: 14px; }
    .search-input:focus { border-color: var(--primary); }

    /* Buttons */
    .btn-sm { padding: 6px 12px; font-size: 12px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; }
    .btn-secondary { background-color: #e1dfdd; color: #333; }
    .btn-secondary:hover { background-color: #d2d0ce; }
</style>

<div class="flex-between" style="margin-bottom: 20px;">
    <h1 class="page-title" style="margin-bottom: 0;">🧾 Central Bill Board</h1>
    <div style="font-size: 13px; color: var(--text-muted); font-weight: 600;">Financial Year: 2026-2027</div>
</div>

<div class="mini-kpi-container">
    <div class="mini-kpi">
        <div class="mini-kpi-title">Total Accounts Payable (Purchases)</div>
        <div class="mini-kpi-value" style="color: var(--danger);">₹ <?= number_format($total_payout, 2) ?></div>
    </div>
    <div class="mini-kpi">
        <div class="mini-kpi-title">Total Bardans Outward (Sales)</div>
        <div class="mini-kpi-value" style="color: var(--success);"><?= number_format($total_bardans_sold) ?> <span style="font-size: 14px; font-weight: normal;">Units</span></div>
    </div>
    <div class="mini-kpi">
        <div class="mini-kpi-title">Bills Generated Today</div>
        <div class="mini-kpi-value"><?= number_format($today_bills) ?></div>
    </div>
</div>

<div class="tab-container">
    <div class="tab-header">
        <button class="tab-btn active" onclick="switchTab('tab-purchases', this)">🌾 Grain Purchase Bills (Inward)</button>
        <button class="tab-btn" onclick="switchTab('tab-sales', this)">📦 Bardan Sale Bills (Outward)</button>
    </div>

    <div id="tab-purchases" class="tab-content active">
        <div class="flex-between" style="margin-bottom: 15px;">
            <h3 style="color: var(--primary); font-size: 16px;">Recent Purchase Invoices</h3>
            <input type="text" id="searchPurchases" class="search-input" placeholder="Search Invoice No, Farmer, or Mobile..." onkeyup="filterTable('searchPurchases', 'tablePurchases')">
        </div>
        
        <table id="tablePurchases" style="width: 100%;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice No.</th>
                    <th>Farmer Name</th>
                    <th>Mobile</th>
                    <th>Amount (₹)</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(count($purchase_bills) > 0): ?>
                    <?php foreach($purchase_bills as $bill): ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($bill['purchase_date'])) ?></td>
                        <td style="font-weight: 600; color: var(--primary);"><?= htmlspecialchars($bill['purchase_no']) ?></td>
                        <td><?= htmlspecialchars($bill['full_name']) ?></td>
                        <td><?= htmlspecialchars($bill['mobile_no']) ?></td>
                        <td style="font-weight: 600;">₹ <?= number_format($bill['final_amount'], 2) ?></td>
                        <td>
                            <?php if($bill['payment_status'] === 'Paid'): ?>
                                <span class="badge badge-in">Paid</span>
                            <?php else: ?>
                                <span class="badge badge-out" style="background:#fff4ce; color:#9d5d00;">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-sm btn-secondary" onclick="window.open('print_purchase_bill.php?id=<?= $bill['id'] ?>', 'PrintPurchase', 'width=800,height=850,top=100,left=100,scrollbars=yes')">🖨️ Reprint</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center; padding: 20px; color: var(--text-muted);">No purchase bills found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="tab-sales" class="tab-content">
        <div class="flex-between" style="margin-bottom: 15px;">
            <h3 style="color: var(--primary); font-size: 16px;">Recent Bardan Invoices</h3>
            <input type="text" id="searchSales" class="search-input" placeholder="Search Bill No, Farmer, or Mobile..." onkeyup="filterTable('searchSales', 'tableSales')">
        </div>

        <table id="tableSales" style="width: 100%;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Bill No. (FY)</th>
                    <th>Farmer Name</th>
                    <th>Mobile</th>
                    <th>Bags Sold</th>
                    <th>Used For</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(count($sale_bills) > 0): ?>
                    <?php foreach($sale_bills as $bill): ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($bill['sell_date'])) ?></td>
                        <td style="font-weight: 600; color: var(--primary);"><?= $bill['yearly_bill_no'] ?> / <span style="font-size:12px; color:var(--text-muted);"><?= $bill['bill_year'] ?></span></td>
                        <td><?= htmlspecialchars($bill['full_name']) ?></td>
                        <td><?= htmlspecialchars($bill['mobile_no']) ?></td>
                        <td><span class="badge badge-out">-<?= number_format($bill['number_of_bardans']) ?></span></td>
                        <td><?= htmlspecialchars($bill['used_for']) ?></td>
                        <td>
                            <button class="btn-sm btn-secondary" onclick="window.open('print_bardan_bill.php?id=<?= $bill['id'] ?>', 'PrintReceipt', 'width=800,height=850,top=100,left=100,scrollbars=yes')">🖨️ Reprint</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center; padding: 20px; color: var(--text-muted);">No bardan sale bills found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Tab Switching Logic
    function switchTab(tabId, btnElement) {
        // Hide all contents
        let contents = document.getElementsByClassName('tab-content');
        for (let i = 0; i < contents.length; i++) {
            contents[i].classList.remove('active');
        }
        
        // Remove active class from all buttons
        let btns = document.getElementsByClassName('tab-btn');
        for (let i = 0; i < btns.length; i++) {
            btns[i].classList.remove('active');
        }
        
        // Show selected tab and highlight button
        document.getElementById(tabId).classList.add('active');
        btnElement.classList.add('active');
    }

    // Reusable Live Search Filter for both tables
    function filterTable(inputId, tableId) {
        let input = document.getElementById(inputId).value.toLowerCase();
        let table = document.getElementById(tableId);
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) { // Skip header row
            let rowText = tr[i].innerText.toLowerCase();
            if (rowText.includes(input)) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
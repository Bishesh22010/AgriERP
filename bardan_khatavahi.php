<?php
// bardan_khatavahi.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$message = '';
$msg_type = '';

// ---------------------------------------------------------
// 1. HANDLE MANUAL LEDGER ENTRIES (e.g., Empty Bags Returned)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_ledger_entry') {
    $farmer_id = (int)$_POST['farmer_id'];
    $transaction_type = $_POST['transaction_type']; // 'RETURN' or 'SELL'
    $bardan_qty = (int)$_POST['bardan_qty'];
    $remarks = trim($_POST['remarks']);
    $user_id = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();

        // Add to Ledger
        $stmt = $pdo->prepare("INSERT INTO bardan_ledger (farmer_id, transaction_type, bardan_qty, remarks) VALUES (?, ?, ?, ?)");
        $stmt->execute([$farmer_id, $transaction_type, $bardan_qty, $remarks]);
        $entry_id = $pdo->lastInsertId();

        // Adjust Main Stock Ledger
        $stock_type = ($transaction_type === 'RETURN') ? 'IN' : 'OUT'; // Farmer returning bags is IN for our stock
        $stmtStock = $pdo->prepare("INSERT INTO bardan_stock_ledger (transaction_type, reference_id, quantity, transaction_date) VALUES (?, ?, ?, CURDATE())");
        $stmtStock->execute([$stock_type, $entry_id, $bardan_qty]);

        $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Bardan Khatavahi')")
            ->execute([$user_id, "Manual $transaction_type of $bardan_qty bardans for Farmer ID: $farmer_id"]);

        $pdo->commit();
        $message = "Ledger entry added successfully!";
        $msg_type = "success";
        
        // Redirect to same farmer to prevent form resubmission
        header("Location: bardan_khatavahi.php?farmer_id=" . $farmer_id . "&msg=success");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'success') {
    $message = "Ledger entry added successfully!";
    $msg_type = "success";
}

// ---------------------------------------------------------
// 2. FETCH ALL FARMERS WITH LIVE BALANCES (Left Sidebar)
// ---------------------------------------------------------
// Calculate Given vs Returned to get Balance
$stmtFarmers = $pdo->query("
    SELECT f.id, f.full_name, f.full_name_gujarati, f.mobile_no, v.village_name, v.village_name_gujarati,
           (
             COALESCE((SELECT SUM(number_of_bardans) FROM bardan_sell WHERE farmer_id = f.id), 0) +
             COALESCE((SELECT SUM(bardan_qty) FROM bardan_ledger WHERE farmer_id = f.id AND transaction_type IN ('SELL', 'HAVALO_IN')), 0)
           ) as total_given,
           (
             COALESCE((SELECT SUM(pi.bardan_used) FROM purchase_book_items pi JOIN purchase_book pb ON pi.purchase_id = pb.id WHERE pb.farmer_id = f.id), 0) +
             COALESCE((SELECT SUM(bardan_qty) FROM bardan_ledger WHERE farmer_id = f.id AND transaction_type IN ('RETURN', 'HAVALO_OUT')), 0)
           ) as total_returned
    FROM farmers f
    LEFT JOIN villages v ON f.village_id = v.id
    ORDER BY v.village_name ASC, f.first_name ASC
");
$farmers = $stmtFarmers->fetchAll();

// ---------------------------------------------------------
// 3. FETCH SELECTED FARMER STATEMENT (Right Content)
// ---------------------------------------------------------
$active_farmer_id = isset($_GET['farmer_id']) ? (int)$_GET['farmer_id'] : null;
$active_farmer = null;
$transactions = [];
$total_given = 0;
$total_returned = 0;

if ($active_farmer_id) {
    // Find active farmer details
    foreach ($farmers as $f) {
        if ($f['id'] === $active_farmer_id) {
            $active_farmer = $f;
            $total_given = $f['total_given'];
            $total_returned = $f['total_returned'];
            break;
        }
    }

    // UNION Query to pull transactions from all modules into a timeline
    $stmtStatement = $pdo->prepare("
        SELECT sell_date as txn_date, CONCAT('Bill: ', yearly_bill_no, '/', bill_year) as details, number_of_bardans as given, 0 as returned, 'Bardan Sell' as type 
        FROM bardan_sell WHERE farmer_id = ?
        
        UNION ALL
        
        SELECT pb.purchase_date as txn_date, CONCAT('Pur. Bill: ', pb.purchase_no) as details, 0 as given, SUM(pi.bardan_used) as returned, 'Purchase Return' as type 
        FROM purchase_book pb
        JOIN purchase_book_items pi ON pb.id = pi.purchase_id
        WHERE pb.farmer_id = ?
        GROUP BY pb.id
        HAVING returned > 0
        
        UNION ALL
        
        SELECT DATE(created_at) as txn_date, remarks as details, 
               CASE WHEN transaction_type IN ('SELL', 'HAVALO_IN') THEN bardan_qty ELSE 0 END as given,
               CASE WHEN transaction_type IN ('RETURN', 'HAVALO_OUT') THEN bardan_qty ELSE 0 END as returned,
               transaction_type as type
        FROM bardan_ledger WHERE farmer_id = ?
        
        ORDER BY txn_date ASC
    ");
    $stmtStatement->execute([$active_farmer_id, $active_farmer_id, $active_farmer_id]);
    $transactions = $stmtStatement->fetchAll();
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    /* Master-Detail Layout */
    .ledger-container { display: flex; height: calc(100vh - 150px); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: var(--card-bg); box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    
    /* Left Pane - Farmer List */
    .ledger-sidebar { width: 350px; border-right: 1px solid var(--border); background: #faf9f8; display: flex; flex-direction: column; }
    .sidebar-header { padding: 15px; border-bottom: 1px solid var(--border); background: var(--card-bg); }
    .search-input { width: 100%; padding: 10px 14px; border: 1px solid #ccc; border-radius: var(--radius); outline: none; font-size: 13px; }
    .search-input:focus { border-color: var(--primary); }
    
    .farmer-list { flex: 1; overflow-y: auto; }
    .farmer-item { padding: 12px 15px; border-bottom: 1px solid var(--border); cursor: pointer; text-decoration: none; color: var(--text-main); display: block; transition: 0.2s; }
    .farmer-item:hover { background: #f3f2f1; }
    .farmer-item.active { background: #eef5fa; border-left: 4px solid var(--primary); }
    
    .f-name { font-weight: 600; font-size: 14px; margin-bottom: 2px; }
    .f-village { font-size: 12px; color: var(--text-muted); }
    .f-balance { float: right; font-weight: 700; font-size: 15px; }
    .bal-out { color: var(--danger); }
    .bal-clr { color: var(--success); }
    
    .village-divider { background: #e1dfdd; padding: 6px 15px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); position: sticky; top: 0; z-index: 2;}

    /* Right Pane - Statement */
    .ledger-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
    .statement-header { padding: 20px; border-bottom: 1px solid var(--border); background: var(--card-bg); }
    .statement-title { font-size: 20px; font-weight: 700; color: var(--primary); margin-bottom: 5px; }
    
    .kpi-row { display: flex; gap: 20px; margin-top: 15px; }
    .kpi-box { flex: 1; padding: 15px; border: 1px solid var(--border); border-radius: var(--radius); background: #faf9f8; text-align: center; }
    .kpi-box .label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-bottom: 5px; }
    .kpi-box .val { font-size: 22px; font-weight: 700; }

    .statement-body { flex: 1; overflow-y: auto; padding: 20px; background: #faf9f8; }
    
    /* Transaction Table */
    .txn-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .txn-table th { background: #f3f2f1; padding: 12px; text-align: left; font-size: 12px; color: var(--text-muted); font-weight: 600; position: sticky; top: 0; }
    .txn-table td { padding: 12px; border-bottom: 1px solid var(--border); font-size: 13px; }
    .qty-badge { padding: 4px 8px; border-radius: 12px; font-weight: 600; font-size: 12px; }
    .qty-given { background: #fde7e9; color: var(--danger); }
    .qty-ret { background: #dff6dd; color: var(--success); }

    /* Modals & Buttons */
    .btn { padding: 8px 16px; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
    .btn-primary { background-color: var(--primary); color: #fff; }
    .btn-secondary { background-color: #e1dfdd; color: var(--text-main); }
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
    .modal-content { background: var(--card-bg); width: 100%; max-width: 500px; border-radius: 6px; }
    .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 24px; }
    .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 15px; outline: none; }
    .form-control:focus { border-color: var(--primary); }
</style>

<div class="flex-between" style="margin-bottom: 20px;">
    <h1 class="page-title" style="margin-bottom: 0;">📖 Bardan Khatavahi (Ledger)</h1>
</div>

<?php if ($message): ?>
    <div style="padding: 12px 16px; border-radius: 2px; margin-bottom: 20px; font-weight: 500; background: <?= $msg_type == 'success' ? '#dff6dd' : '#fde7e9' ?>; color: <?= $msg_type == 'success' ? '#107c10' : '#d13438' ?>;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="ledger-container">
    
    <div class="ledger-sidebar">
        <div class="sidebar-header">
            <input type="text" id="searchFarmer" class="search-input" placeholder="Search farmer or village..." onkeyup="filterSidebar()">
        </div>
        <div class="farmer-list" id="farmerList">
            <?php 
            $current_village = null;
            foreach ($farmers as $f): 
                $balance = $f['total_given'] - $f['total_returned'];
                
                if ($f['village_name'] !== $current_village): 
                    $current_village = $f['village_name'];
            ?>
                <div class="village-divider search-group"><?= htmlspecialchars($current_village ?? 'Unassigned') ?></div>
            <?php endif; ?>
                
                <a href="?farmer_id=<?= $f['id'] ?>" class="farmer-item search-item <?= ($active_farmer_id === $f['id']) ? 'active' : '' ?>">
                    <span class="f-balance <?= $balance > 0 ? 'bal-out' : 'bal-clr' ?>"><?= $balance ?></span>
                    <div class="f-name search-name"><?= htmlspecialchars($f['full_name'] ?? '') ?></div>
                    <div class="f-village search-mobile">📞 <?= htmlspecialchars($f['mobile_no'] ?? '') ?> | <?= htmlspecialchars($f['full_name_gujarati'] ?? '') ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="ledger-main">
        <?php if ($active_farmer): 
            $current_balance = $total_given - $total_returned;
        ?>
            <div class="statement-header">
                <div class="flex-between">
                    <div>
                        <div class="statement-title"><?= htmlspecialchars($active_farmer['full_name'] ?? '') ?></div>
                        <div style="color: var(--text-muted); font-size: 13px;">
                            📍 <?= htmlspecialchars($active_farmer['village_name'] ?? '') ?> | 📞 <?= htmlspecialchars($active_farmer['mobile_no'] ?? '') ?>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="openModal()">+ Add Manual Entry</button>
                    </div>
                </div>

                <div class="kpi-row">
                    <div class="kpi-box">
                        <div class="label">Total Taken (આપેલા)</div>
                        <div class="val" style="color: var(--danger);"><?= $total_given ?></div>
                    </div>
                    <div class="kpi-box">
                        <div class="label">Total Returned (જમા)</div>
                        <div class="val" style="color: var(--success);"><?= $total_returned ?></div>
                    </div>
                    <div class="kpi-box" style="<?= $current_balance > 0 ? 'background: #fff4ce; border-color: #ffaa44;' : 'background: #dff6dd; border-color: #107c10;' ?>">
                        <div class="label">Outstanding Balance (બાકી)</div>
                        <div class="val" style="color: <?= $current_balance > 0 ? '#9d5d00' : '#107c10' ?>;"><?= $current_balance ?></div>
                    </div>
                </div>

                <?php if($current_balance > 0): ?>
                    <div style="margin-top: 15px; padding: 10px; background: #fde7e9; color: #d13438; border-left: 4px solid #d13438; font-weight: 600; font-size: 14px;">
                        ⚠️ Warning: <?= $current_balance ?> Bardan Aapvana Baki (બારદાન આપવાના બાકી છે)
                    </div>
                <?php endif; ?>
            </div>

            <div class="statement-body">
                <table class="txn-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Particulars / Details</th>
                            <th>Type</th>
                            <th style="text-align: center;">Given (+)</th>
                            <th style="text-align: center;">Returned (-)</th>
                            <th style="text-align: right;">Running Bal.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $running_bal = 0;
                        if (count($transactions) > 0): 
                            foreach ($transactions as $txn): 
                                $running_bal += $txn['given'];
                                $running_bal -= $txn['returned'];
                        ?>
                            <tr>
                                <td><?= date('d-m-Y', strtotime($txn['txn_date'])) ?></td>
                                <td><?= htmlspecialchars($txn['details']) ?></td>
                                <td><span style="background:#e1dfdd; padding:2px 6px; border-radius:4px; font-size:11px;"><?= $txn['type'] ?></span></td>
                                <td style="text-align: center;"><?= $txn['given'] > 0 ? "<span class='qty-badge qty-given'>+{$txn['given']}</span>" : "-" ?></td>
                                <td style="text-align: center;"><?= $txn['returned'] > 0 ? "<span class='qty-badge qty-ret'>-{$txn['returned']}</span>" : "-" ?></td>
                                <td style="text-align: right; font-weight: 600;"><?= $running_bal ?></td>
                            </tr>
                        <?php 
                            endforeach; 
                        else: 
                        ?>
                            <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">No transactions found for this farmer.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div style="flex: 1; display: flex; align-items: center; justify-content: center; flex-direction: column; color: var(--text-muted);">
                <div style="font-size: 40px; margin-bottom: 15px;">📖</div>
                <h3>Select a Farmer</h3>
                <p>Click on a farmer from the list on the left to view their complete Khatavahi.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="entryModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="font-size: 18px; color: var(--primary);">Add Manual Ledger Entry</h2>
            <button class="btn" style="background:none; font-size:24px; color:#666; padding:0;" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="bardan_khatavahi.php">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_ledger_entry">
                <input type="hidden" name="farmer_id" value="<?= $active_farmer_id ?>">
                
                <label class="form-label">Transaction Type *</label>
                <select name="transaction_type" class="form-control" required>
                    <option value="RETURN">Empty Bags Returned (જમા)</option>
                    <option value="SELL">Bags Given Manually (ઉધાર)</option>
                </select>
                
                <label class="form-label">Quantity *</label>
                <input type="number" name="bardan_qty" class="form-control" min="1" placeholder="Enter number of bardans" required>
                
                <label class="form-label">Remarks / Details</label>
                <input type="text" name="remarks" class="form-control" placeholder="e.g., Returned to warehouse">
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border); text-align: right; background: #faf9f8;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Entry</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() { document.getElementById('entryModal').style.display = 'flex'; }
    function closeModal() { document.getElementById('entryModal').style.display = 'none'; }

    // Sidebar Live Filter
    function filterSidebar() {
        let input = document.getElementById("searchFarmer").value.toLowerCase();
        let items = document.getElementsByClassName("search-item");
        let groups = document.getElementsByClassName("search-group");

        // Simple item filter
        for (let item of items) {
            let text = item.innerText.toLowerCase();
            item.style.display = text.includes(input) ? "block" : "none";
        }

        // Hide village headers if no visible items below them
        let currentHeader = null;
        let hasVisible = false;
        
        let children = document.getElementById('farmerList').children;
        for (let el of children) {
            if (el.classList.contains('search-group')) {
                if (currentHeader) currentHeader.style.display = hasVisible ? "block" : "none";
                currentHeader = el;
                hasVisible = false;
            } else if (el.classList.contains('search-item') && el.style.display === "block") {
                hasVisible = true;
            }
        }
        if (currentHeader) currentHeader.style.display = hasVisible ? "block" : "none";
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
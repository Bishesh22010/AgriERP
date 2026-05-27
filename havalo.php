<?php
// havalo.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$message = '';
$msg_type = '';
$user_id = $_SESSION['user_id'];

// ---------------------------------------------------------
// 1. HELPER: CALCULATE A FARMER'S EXACT BALANCE
// ---------------------------------------------------------
function getFarmerBalance($pdo, $farmer_id) {
    $stmt = $pdo->prepare("
        SELECT 
           (
             COALESCE((SELECT SUM(number_of_bardans) FROM bardan_sell WHERE farmer_id = ?), 0) +
             COALESCE((SELECT SUM(bardan_qty) FROM bardan_ledger WHERE farmer_id = ? AND transaction_type IN ('SELL', 'HAVALO_IN')), 0)
           ) -
           (
             COALESCE((SELECT SUM(pi.bardan_used) FROM purchase_book_items pi JOIN purchase_book pb ON pi.purchase_id = pb.id WHERE pb.farmer_id = ?), 0) +
             COALESCE((SELECT SUM(bardan_qty) FROM bardan_ledger WHERE farmer_id = ? AND transaction_type IN ('RETURN', 'HAVALO_OUT')), 0)
           ) as current_balance
    ");
    $stmt->execute([$farmer_id, $farmer_id, $farmer_id, $farmer_id]);
    return (int)$stmt->fetchColumn();
}

// ---------------------------------------------------------
// 2. HANDLE HAVALO SUBMISSION (DOUBLE-ENTRY)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_havalo') {
    $from_farmer_id = (int)$_POST['from_farmer_id'];
    $to_farmer_id   = (int)$_POST['to_farmer_id'];
    $bardan_qty     = (int)$_POST['bardan_qty'];
    $notes          = trim($_POST['notes']);

    try {
        if ($from_farmer_id === $to_farmer_id) {
            throw new Exception("You cannot transfer bardans to the same farmer.");
        }
        if ($bardan_qty <= 0) {
            throw new Exception("Transfer quantity must be greater than zero.");
        }

        // Server-Side Balance Validation (Security Check)
        $from_balance = getFarmerBalance($pdo, $from_farmer_id);
        if ($from_balance < $bardan_qty) {
            throw new Exception("Transfer failed: Farmer only has {$from_balance} bardans available to transfer.");
        }

        // Generate Havalo Bill No
        $stmtCount = $pdo->query("SELECT COUNT(id) FROM bardan_havalo WHERE DATE(created_at) = CURDATE()");
        $daily_count = $stmtCount->fetchColumn() + 1;
        $havalo_bill_no = 'HAV-' . date('Ymd') . '-' . str_pad($daily_count, 3, '0', STR_PAD_LEFT);

        // BEGIN DOUBLE-ENTRY TRANSACTION
        $pdo->beginTransaction();

        // A. Insert Master Havalo Record
        $stmtHavalo = $pdo->prepare("INSERT INTO bardan_havalo (from_farmer_id, to_farmer_id, bardan_qty, havalo_bill_no, notes) VALUES (?, ?, ?, ?, ?)");
        $stmtHavalo->execute([$from_farmer_id, $to_farmer_id, $bardan_qty, $havalo_bill_no, $notes]);
        $havalo_id = $pdo->lastInsertId();

        // B. Ledger Entry: FROM Farmer (Acts as a RETURN / Deduction)
        $stmtOut = $pdo->prepare("INSERT INTO bardan_ledger (farmer_id, transaction_type, bardan_qty, reference_bill, remarks) VALUES (?, 'HAVALO_OUT', ?, ?, ?)");
        $stmtOut->execute([$from_farmer_id, $bardan_qty, $havalo_bill_no, "Transferred to Farmer ID: $to_farmer_id. Notes: $notes"]);

        // C. Ledger Entry: TO Farmer (Acts as a SELL / Addition)
        $stmtIn = $pdo->prepare("INSERT INTO bardan_ledger (farmer_id, transaction_type, bardan_qty, reference_bill, remarks) VALUES (?, 'HAVALO_IN', ?, ?, ?)");
        $stmtIn->execute([$to_farmer_id, $bardan_qty, $havalo_bill_no, "Received from Farmer ID: $from_farmer_id. Notes: $notes"]);

        // D. Audit Log
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Havalo System')")
            ->execute([$user_id, "Havalo $havalo_bill_no: $bardan_qty bags from Farmer $from_farmer_id to $to_farmer_id"]);

        $pdo->commit();
        $message = "Havalo transfer completed successfully! Bill No: $havalo_bill_no";
        $msg_type = "success";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = $e->getMessage();
        $msg_type = "danger";
    }
}

// ---------------------------------------------------------
// 3. FETCH DATA FOR UI
// ---------------------------------------------------------
// Fetch all farmers and pre-calculate their balances for the JavaScript UI
$stmtFarmersList = $pdo->query("SELECT id, full_name, mobile_no, village_id FROM farmers ORDER BY full_name ASC");
$all_farmers = $stmtFarmersList->fetchAll();

$farmer_balances = [];
foreach ($all_farmers as $f) {
    $farmer_balances[$f['id']] = getFarmerBalance($pdo, $f['id']);
}

// Fetch Havalo History
$stmtHistory = $pdo->query("
    SELECT h.*, 
           f1.full_name as from_name, f1.mobile_no as from_mobile,
           f2.full_name as to_name, f2.mobile_no as to_mobile
    FROM bardan_havalo h
    JOIN farmers f1 ON h.from_farmer_id = f1.id
    JOIN farmers f2 ON h.to_farmer_id = f2.id
    ORDER BY h.created_at DESC
    LIMIT 100
");
$history = $stmtHistory->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    .form-control { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 14px; outline: none; background-color: #fff; transition: border 0.2s; }
    .form-control:focus { border-color: var(--primary); }
    .form-group { margin-bottom: 20px; }
    .form-label { font-weight: 600; font-size: 13px; margin-bottom: 6px; display: block; color: var(--text-main); }
    
    .btn { padding: 10px 20px; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .btn-primary { background-color: var(--primary); color: #fff; }
    .btn-secondary { background-color: #e1dfdd; color: var(--text-main); }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1050; align-items: center; justify-content: center; }
    .modal-content { background: var(--card-bg); width: 90%; max-width: 650px; border-radius: 6px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 24px; background: #faf9f8; }
    .modal-footer { padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; }
    .close-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); line-height: 1; }

    .search-wrapper { display: flex; justify-content: flex-end; margin-bottom: 15px; }
    .search-input { width: 100%; max-width: 350px; padding: 10px 14px; border: 1px solid #ccc; border-radius: var(--radius); outline: none; font-size: 14px; }
    
    .alert { padding: 16px; border-radius: var(--radius); margin-bottom: 20px; font-weight: 500; }
    .alert-success { background-color: #dff6dd; color: #107c10; border: 1px solid #c3f3c0; }
    .alert-danger { background-color: #fde7e9; color: #d13438; border: 1px solid #f9d2d5; }

    .transfer-box { background: #fff; border: 1px solid var(--border); padding: 20px; border-radius: 6px; position: relative; margin-bottom: 20px;}
    .transfer-arrow { text-align: center; font-size: 24px; color: var(--primary); padding: 10px 0; font-weight: bold;}
    .balance-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 700; margin-top: 8px; background: #e1dfdd; color: #333; }
</style>

<div class="flex-between" style="margin-bottom: 24px;">
    <h1 class="page-title" style="margin-bottom: 0;">🔄 Havalo Transfer System</h1>
    <button class="btn btn-primary" onclick="openModal()">
        <span>+</span> New Havalo Transfer
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="table-container">
    <div class="table-header flex-between">
        <span style="font-size: 16px;">Recent Havalo Transactions</span>
        <div class="search-wrapper" style="margin: 0;">
            <input type="text" id="searchInput" class="search-input" placeholder="Search bill, farmer name..." onkeyup="filterTable()">
        </div>
    </div>
    <table id="havaloTable">
        <thead>
            <tr>
                <th width="15%">Date & Time</th>
                <th width="15%">Havalo Bill No.</th>
                <th width="25%">From Farmer (Sender)</th>
                <th width="5%" style="text-align:center;"></th>
                <th width="25%">To Farmer (Receiver)</th>
                <th width="15%" style="text-align:right;">Bardan Qty</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($history) > 0): ?>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td style="color: var(--text-muted); font-size: 12px;"><?= date('d-m-Y h:i A', strtotime($row['created_at'])) ?></td>
                        <td style="font-weight: 600; color: var(--primary);"><?= htmlspecialchars($row['havalo_bill_no']) ?></td>
                        <td>
                            <div style="font-weight: 600; color: var(--danger);"><?= htmlspecialchars($row['from_name']) ?></div>
                            <div style="font-size: 11px; color: var(--text-muted);">📞 <?= htmlspecialchars($row['from_mobile']) ?></div>
                        </td>
                        <td style="text-align:center; font-size:18px; color: var(--text-muted);">→</td>
                        <td>
                            <div style="font-weight: 600; color: var(--success);"><?= htmlspecialchars($row['to_name']) ?></div>
                            <div style="font-size: 11px; color: var(--text-muted);">📞 <?= htmlspecialchars($row['to_mobile']) ?></div>
                        </td>
                        <td style="text-align:right;">
                            <span style="background: #e1dfdd; padding: 6px 12px; border-radius: 12px; font-weight: 700;">
                                <?= number_format($row['bardan_qty']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        No Havalo transfers recorded yet.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="havaloModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="font-size: 20px; color: var(--primary);">Execute Havalo Transfer</h2>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="havalo.php" id="havaloForm" onsubmit="return validateTransfer()">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_havalo">
                
                <div class="transfer-box">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">FROM: Sender Farmer (ખાતે ઉધાર) *</label>
                        <select name="from_farmer_id" id="from_farmer" class="form-control" required onchange="updateBalanceUi()">
                            <option value="">-- Select Sender Farmer --</option>
                            <?php foreach($all_farmers as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['full_name']) ?> (📞 <?= htmlspecialchars($f['mobile_no']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div id="balance_indicator" class="balance-badge" style="display:none;"></div>
                    </div>
                </div>

                <div class="transfer-arrow">⬇</div>

                <div class="transfer-box">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">TO: Receiver Farmer (ખાતે જમા) *</label>
                        <select name="to_farmer_id" id="to_farmer" class="form-control" required onchange="validateMatch()">
                            <option value="">-- Select Receiver Farmer --</option>
                            <?php foreach($all_farmers as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['full_name']) ?> (📞 <?= htmlspecialchars($f['mobile_no']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div id="match_error" style="color:var(--danger); font-size:12px; font-weight:600; margin-top:5px; display:none;">Cannot transfer to the same farmer!</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-top: 20px;">
                    <div class="form-group">
                        <label class="form-label">Transfer Quantity *</label>
                        <input type="number" name="bardan_qty" id="transfer_qty" class="form-control" min="1" required placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Internal Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Reason for transfer (Optional)">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel Transfer</button>
                <button type="submit" class="btn btn-primary" id="btnSubmit">Confirm Havalo</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Pass the calculated PHP balances safely into a JavaScript Object
    const balances = <?= json_encode($farmer_balances) ?>;

    function openModal() { document.getElementById('havaloModal').style.display = 'flex'; }
    function closeModal() { document.getElementById('havaloModal').style.display = 'none'; document.getElementById('havaloForm').reset(); updateBalanceUi(); }

    function updateBalanceUi() {
        let fromId = document.getElementById('from_farmer').value;
        let badge = document.getElementById('balance_indicator');
        let qtyInput = document.getElementById('transfer_qty');
        
        if (fromId && balances[fromId] !== undefined) {
            let bal = balances[fromId];
            badge.style.display = 'inline-block';
            badge.innerText = 'Available Balance: ' + bal + ' Bardans';
            
            if (bal > 0) {
                badge.style.background = '#dff6dd'; badge.style.color = '#107c10';
                qtyInput.max = bal; // Dynamically set the max HTML attribute
            } else {
                badge.style.background = '#fde7e9'; badge.style.color = '#d13438';
                badge.innerText = '⚠️ Insufficient Balance (0 Bardans)';
                qtyInput.max = 0;
            }
        } else {
            badge.style.display = 'none';
            qtyInput.removeAttribute('max');
        }
        validateMatch();
    }

    function validateMatch() {
        let fromId = document.getElementById('from_farmer').value;
        let toId = document.getElementById('to_farmer').value;
        let errorMsg = document.getElementById('match_error');
        let btnSubmit = document.getElementById('btnSubmit');

        if (fromId && toId && fromId === toId) {
            errorMsg.style.display = 'block';
            btnSubmit.disabled = true;
            btnSubmit.style.opacity = '0.5';
        } else {
            errorMsg.style.display = 'none';
            btnSubmit.disabled = false;
            btnSubmit.style.opacity = '1';
        }
    }

    function validateTransfer() {
        let fromId = document.getElementById('from_farmer').value;
        let qty = parseInt(document.getElementById('transfer_qty').value);
        let bal = balances[fromId] || 0;

        if (qty > bal) {
            alert("Transfer failed! The selected farmer only has " + bal + " bardans available.");
            return false;
        }
        return true;
    }

    function filterTable() {
        let input = document.getElementById("searchInput").value.toLowerCase();
        let table = document.getElementById("havaloTable");
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let rowText = tr[i].innerText.toLowerCase();
            tr[i].style.display = rowText.includes(input) ? "" : "none";
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
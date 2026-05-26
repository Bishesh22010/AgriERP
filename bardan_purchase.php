<?php
// bardan_purchase.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$message = '';
$msg_type = '';

// Check if there are any suppliers, if not, create a dummy one for testing
$supplier_check = $pdo->query("SELECT COUNT(*) FROM bardan_suppliers")->fetchColumn();
if ($supplier_check == 0) {
    $pdo->query("INSERT INTO bardan_suppliers (supplier_name, contact_no) VALUES ('Default Supplier', '9876543210')");
}

// ---------------------------------------------------------
// 1. HANDLE FORM SUBMISSIONS (ADD PURCHASE)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_purchase') {
    // Sanitize and assign inputs
    $purchase_date     = $_POST['purchase_date'];
    $supplier_id       = $_POST['supplier_id'];
    $number_of_bundles = (int)$_POST['number_of_bundles'];
    $total_nos         = (int)$_POST['total_nos'];
    $fare              = (float)($_POST['fare'] ?: 0);
    $hamali            = (float)($_POST['hamali'] ?: 0);
    $vehicle_number    = trim($_POST['vehicle_number']);
    $purchase_bill_no  = trim($_POST['purchase_bill_no']);
    $note              = trim($_POST['note']);
    $created_by        = $_SESSION['user_id'];

    try {
        // Begin Transaction for Data Integrity
        $pdo->beginTransaction();

        // A. Insert into Bardan Purchase Table
        $stmt = $pdo->prepare("
            INSERT INTO bardan_purchase 
            (purchase_date, supplier_id, number_of_bundles, total_nos, fare, hamali, vehicle_number, purchase_bill_no, note, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $purchase_date, $supplier_id, $number_of_bundles, $total_nos, 
            $fare, $hamali, $vehicle_number, $purchase_bill_no, $note, $created_by
        ]);
        $purchase_id = $pdo->lastInsertId();

        // B. Insert into Stock Ledger (Auto Stock Increase)
        $stmtStock = $pdo->prepare("
            INSERT INTO bardan_stock_ledger 
            (transaction_type, reference_id, quantity, transaction_date) 
            VALUES ('IN', ?, ?, ?)
        ");
        $stmtStock->execute([$purchase_id, $total_nos, $purchase_date]);

        // C. Audit Log
        $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Bardan Purchase')");
        $stmtLog->execute([$created_by, "Added Purchase ID: $purchase_id"]);

        // Commit Transaction
        $pdo->commit();
        $message = "Purchase recorded successfully! Stock has been updated.";
        $msg_type = "success";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error recording purchase: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// ---------------------------------------------------------
// 2. FETCH DATA FOR UI
// ---------------------------------------------------------
// Fetch Suppliers for dropdown
$suppliers = $pdo->query("SELECT id, supplier_name FROM bardan_suppliers ORDER BY supplier_name")->fetchAll();

// Fetch Purchase History
$purchases = $pdo->query("
    SELECT p.*, s.supplier_name, u.full_name as creator_name 
    FROM bardan_purchase p 
    LEFT JOIN bardan_suppliers s ON p.supplier_id = s.id 
    LEFT JOIN users u ON p.created_by = u.id 
    ORDER BY p.purchase_date DESC
")->fetchAll();

// Load Layout
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    /* Minimalistic form elements with neutral borders */
    .form-control {
        width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: var(--radius);
        font-size: 14px; background-color: #fff; transition: border-color 0.2s;
        margin-top: 4px; outline: none;
    }
    /* Strictly neutral gray border on focus */
    .form-control:focus { border-color: #888; }
    
    .form-group { margin-bottom: 15px; }
    .form-label { font-weight: 600; font-size: 13px; color: var(--text-main); }
    
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
    
    /* Branding Buttons */
    .btn { padding: 8px 16px; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 13px; }
    .btn-primary { background-color: #005A9E; color: #fff; display: flex; align-items: center; gap: 6px; }
    .btn-primary .icon { color: #FFC107; font-weight: bold; } /* Yellow Accent Icon */
    .btn-primary:hover { background-color: #004578; }
    .btn-secondary { background-color: #e1dfdd; color: var(--text-main); }
    .btn-secondary:hover { background-color: #d2d0ce; }

    /* Modal Styling */
    .modal-overlay {
        display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.4); z-index: 1000; justify-content: center; align-items: center;
    }
    .modal-content {
        background: var(--card-bg); width: 100%; max-width: 700px; border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto;
    }
    .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 24px; }
    .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; background: #faf9f8; }
    .close-btn { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted); }
    
    /* Search Bar */
    .search-wrapper { margin-bottom: 15px; display: flex; justify-content: flex-end; }
    .search-input { width: 300px; padding: 8px 12px; border: 1px solid #ccc; border-radius: var(--radius); outline: none; }
    .search-input:focus { border-color: #888; }
    
    .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; font-weight: 500; }
    .alert-success { background-color: #dff6dd; color: #107c10; border: 1px solid #c3f3c0; }
    .alert-danger { background-color: #fde7e9; color: #d13438; border: 1px solid #f9d2d5; }
</style>

<div class="flex-between" style="margin-bottom: 20px;">
    <h1 class="page-title" style="margin-bottom: 0;">📥 Bardan Purchase (Inward)</h1>
    <button class="btn btn-primary" onclick="openModal()">
        <span class="icon">✦</span> New Purchase
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="table-container">
    <div class="table-header flex-between">
        <span>Purchase Ledger</span>
        <div class="search-wrapper" style="margin: 0;">
            <input type="text" id="searchInput" class="search-input" placeholder="Search by Bill No, Supplier, or Vehicle..." onkeyup="filterTable()">
        </div>
    </div>
    <table id="purchaseTable">
        <thead>
            <tr>
                <th>Date</th>
                <th>Bill No.</th>
                <th>Supplier</th>
                <th>Bundles</th>
                <th>Total Nos.</th>
                <th>Vehicle</th>
                <th>Entered By</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($purchases) > 0): ?>
                <?php foreach ($purchases as $row): ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($row['purchase_date'])) ?></td>
                        <td style="font-weight: 600; color: var(--primary);"><?= htmlspecialchars($row['purchase_bill_no']) ?></td>
                        <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                        <td><?= number_format($row['number_of_bundles']) ?></td>
                        <td><span class="badge badge-in">+<?= number_format($row['total_nos']) ?></span></td>
                        <td><?= htmlspecialchars($row['vehicle_number']) ?></td>
                        <td style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($row['creator_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">
                        No purchase records found. Click "New Purchase" to add stock.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="addModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="font-size: 18px; color: var(--primary);">Add New Bardan Purchase</h2>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="bardan_purchase.php">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_purchase">
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Purchase Date *</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Bill No.</label>
                        <input type="text" name="purchase_bill_no" class="form-control" placeholder="e.g. INV-1004" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Supplier *</label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">-- Select Supplier --</option>
                        <?php foreach($suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Number of Bundles *</label>
                        <input type="number" name="number_of_bundles" class="form-control" min="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Total Nos (Pieces) *</label>
                        <input type="number" name="total_nos" class="form-control" min="1" required>
                    </div>
                </div>

                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label">Fare (₹)</label>
                        <input type="number" step="0.01" name="fare" class="form-control" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Hamali (₹)</label>
                        <input type="number" step="0.01" name="hamali" class="form-control" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Vehicle Number</label>
                        <input type="text" name="vehicle_number" class="form-control" placeholder="e.g. GJ-23-AB-1234">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Additional Notes</label>
                    <textarea name="note" class="form-control" rows="2" placeholder="Any remarks..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Purchase</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() {
        document.getElementById('addModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('addModal').style.display = 'none';
    }

    // Live search filtering for the table
    function filterTable() {
        let input = document.getElementById("searchInput").value.toLowerCase();
        let table = document.getElementById("purchaseTable");
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let rowText = tr[i].innerText.toLowerCase();
            if (rowText.includes(input)) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
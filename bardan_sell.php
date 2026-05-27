<?php
// bardan_sell.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/media_handler.php';

$message = '';
$msg_type = '';

// Check if there are any farmers, if not, create a dummy one for testing
$farmer_check = $pdo->query("SELECT COUNT(*) FROM farmers")->fetchColumn();
if ($farmer_check == 0) {
    $pdo->query("INSERT INTO farmers (full_name, mobile_no, town_city) VALUES ('Default Farmer', '9876543210', 'Anand')");
}

// ---------------------------------------------------------
// 1. HANDLE FORM SUBMISSIONS (ADD SALE)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_sell') {
    $sell_date         = $_POST['sell_date'];
    $farmer_id         = $_POST['farmer_id'];
    $number_of_bardans = (int)$_POST['number_of_bardans'];
    $used_for          = $_POST['used_for'];
    $remarks           = trim($_POST['remarks']);
    $signature_base64  = $_POST['signature_base64'] ?? ''; // NEW: Capture signature data
    $created_by        = $_SESSION['user_id'];

    // Step A: Calculate Financial Year (April to March)
    $sell_timestamp = strtotime($sell_date);
    $sell_month = (int)date('m', $sell_timestamp);
    $sell_year = (int)date('Y', $sell_timestamp);
    
    if ($sell_month >= 4) {
        $bill_year = $sell_year . '-' . ($sell_year + 1);
    } else {
        $bill_year = ($sell_year - 1) . '-' . $sell_year;
    }

    try {
        $pdo->beginTransaction();

        // Step B: Stock Validation Check
        $stmtStockCheck = $pdo->query("
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'IN' THEN quantity ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN transaction_type = 'OUT' THEN quantity ELSE 0 END), 0) 
            FROM bardan_stock_ledger
        ");
        $current_stock = (int)$stmtStockCheck->fetchColumn();

        if ($current_stock < $number_of_bardans) {
            throw new Exception("Insufficient stock! You only have {$current_stock} bardans available.");
        }

        // Step C: Auto-generate Yearly Bill Number
        $stmtBillNo = $pdo->prepare("SELECT MAX(yearly_bill_no) FROM bardan_sell WHERE bill_year = ?");
        $stmtBillNo->execute([$bill_year]);
        $max_bill = $stmtBillNo->fetchColumn();
        $yearly_bill_no = $max_bill ? $max_bill + 1 : 1;

        // Step D: Save Signature if provided
        $signature_path = null;
        if (!empty($signature_base64)) {
            $signature_path = saveBase64Image($signature_base64, 'signatures');
        }

        // Step E: Insert into Bardan Sell Table (Updated with signature fields)
        $stmt = $pdo->prepare("
            INSERT INTO bardan_sell 
            (yearly_bill_no, bill_year, sell_date, farmer_id, number_of_bardans, used_for, remarks, signature_path, signed_at, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)
        ");
        $stmt->execute([
            $yearly_bill_no, $bill_year, $sell_date, $farmer_id, 
            $number_of_bardans, $used_for, $remarks, $signature_path, $created_by
        ]);
        $sell_id = $pdo->lastInsertId();

        // Step F: Insert into Stock Ledger (Auto Stock Deduction)
        $stmtLedger = $pdo->prepare("
            INSERT INTO bardan_stock_ledger 
            (transaction_type, reference_id, quantity, transaction_date) 
            VALUES ('OUT', ?, ?, ?)
        ");
        $stmtLedger->execute([$sell_id, $number_of_bardans, $sell_date]);

        // Step G: Audit Log
        $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Bardan Sell')");
        $stmtLog->execute([$created_by, "Sold {$number_of_bardans} Bardans to Farmer ID: {$farmer_id} (Bill: {$yearly_bill_no}/{$bill_year})"]);

        $pdo->commit();
        $message = "Sale generated successfully! Bill No: {$yearly_bill_no}/{$bill_year}";
        $msg_type = "success";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = $e->getMessage();
        $msg_type = "danger";
    }
}

// ---------------------------------------------------------
// 2. FETCH DATA FOR UI
// ---------------------------------------------------------
$farmers = $pdo->query("SELECT id, full_name, mobile_no FROM farmers ORDER BY full_name")->fetchAll();

$sales = $pdo->query("
    SELECT s.*, f.full_name, f.mobile_no, u.full_name as creator_name 
    FROM bardan_sell s 
    LEFT JOIN farmers f ON s.farmer_id = f.id 
    LEFT JOIN users u ON s.created_by = u.id 
    ORDER BY s.sell_date DESC, s.id DESC
")->fetchAll();

// Get Current Live Stock for UI Display
$stmtStockUI = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN transaction_type = 'IN' THEN quantity ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN transaction_type = 'OUT' THEN quantity ELSE 0 END), 0) 
    FROM bardan_stock_ledger
");
$live_stock = (int)$stmtStockUI->fetchColumn();

// Load Layout
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    .form-control { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: var(--radius); font-size: 14px; background-color: #fff; transition: border-color 0.2s; margin-top: 4px; outline: none; }
    .form-control:focus { border-color: #888; }
    .form-group { margin-bottom: 15px; }
    .form-label { font-weight: 600; font-size: 13px; color: var(--text-main); }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    
    .btn { padding: 8px 16px; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 13px; }
    .btn-primary { background-color: #005A9E; color: #fff; display: flex; align-items: center; gap: 6px; }
    .btn-primary .icon { color: #FFC107; font-weight: bold; }
    .btn-primary:hover { background-color: #004578; }
    .btn-secondary { background-color: #e1dfdd; color: var(--text-main); }
    .btn-secondary:hover { background-color: #d2d0ce; }
    .btn-sm { padding: 4px 8px; font-size: 11px; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 1000; justify-content: center; align-items: center; }
    .modal-content { background: var(--card-bg); width: 100%; max-width: 600px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 95vh; overflow-y: auto;}
    .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 24px; }
    .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; background: #faf9f8; }
    .close-btn { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted); }
    
    .search-wrapper { margin-bottom: 15px; display: flex; justify-content: flex-end; }
    .search-input { width: 300px; padding: 8px 12px; border: 1px solid #ccc; border-radius: var(--radius); outline: none; }
    .search-input:focus { border-color: #888; }
    
    .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; font-weight: 500; }
    .alert-success { background-color: #dff6dd; color: #107c10; border: 1px solid #c3f3c0; }
    .alert-danger { background-color: #fde7e9; color: #d13438; border: 1px solid #f9d2d5; }
    
    .stock-indicator { background: var(--card-bg); border: 1px solid var(--border); padding: 10px 16px; border-radius: var(--radius); font-weight: 600; display: inline-flex; align-items: center; gap: 10px; }
</style>

<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title" style="margin-bottom: 5px;">📤 Bardan Sell (Outward)</h1>
        <div class="stock-indicator">
            Live Available Stock: <span style="color: <?= $live_stock > 0 ? 'var(--primary)' : 'var(--danger)' ?>; font-size: 16px;"><?= number_format($live_stock) ?></span>
        </div>
    </div>
    <button class="btn btn-primary" onclick="openModal()">
        <span class="icon">✦</span> Generate New Bill
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="table-container">
    <div class="table-header flex-between">
        <span>Outward Ledger / Bills</span>
        <div class="search-wrapper" style="margin: 0;">
            <input type="text" id="searchInput" class="search-input" placeholder="Search Farmer, Mobile or Bill..." onkeyup="filterTable()">
        </div>
    </div>
    <table id="salesTable">
        <thead>
            <tr>
                <th>Date</th>
                <th>Bill No. (Year)</th>
                <th>Farmer Name</th>
                <th>Mobile</th>
                <th>Quantity Sold</th>
                <th>Used For</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($sales) > 0): ?>
                <?php foreach ($sales as $row): ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($row['sell_date'])) ?></td>
                        <td style="font-weight: 600; color: var(--primary);">
                            <?= $row['yearly_bill_no'] ?> / <span style="font-size:12px; color:var(--text-muted);"><?= $row['bill_year'] ?></span>
                        </td>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= htmlspecialchars($row['mobile_no']) ?></td>
                        <td><span class="badge badge-out">-<?= number_format($row['number_of_bardans']) ?></span></td>
                        <td><?= htmlspecialchars($row['used_for']) ?></td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick="window.open('print_bardan_bill.php?id=<?= $row['id'] ?>', 'PrintReceipt', 'width=800,height=850,top=100,left=100,toolbars=no,scrollbars=yes,status=no,resizable=yes')">🖨️ Print</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">
                        No sale records found. Click "Generate New Bill" to begin.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="addModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="font-size: 18px; color: var(--primary);">Generate Bardan Bill</h2>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="bardan_sell.php">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_sell">
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Sell Date *</label>
                        <input type="date" name="sell_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Farmer *</label>
                        <select name="farmer_id" class="form-control" required>
                            <option value="">-- Select Farmer --</option>
                            <?php foreach($farmers as $farm): ?>
                                <option value="<?= $farm['id'] ?>"><?= htmlspecialchars($farm['full_name']) ?> (<?= htmlspecialchars($farm['mobile_no']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Quantity to Sell *</label>
                        <input type="number" name="number_of_bardans" class="form-control" min="1" max="<?= $live_stock ?>" placeholder="Max: <?= $live_stock ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Used For *</label>
                        <select name="used_for" class="form-control" required>
                            <option value="Dangar">Dangar</option>
                            <option value="Ghau">Ghau</option>
                            <option value="Bajri">Bajri</option>
                            <option value="Mafri">Mafri</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Remarks / Note</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="Optional details..."></textarea>
                </div>
                
                <div style="border: 1px solid var(--border); border-radius: var(--radius); margin-top: 15px; overflow: hidden;">
                    <div style="background: #f3f2f1; padding: 10px 15px; font-weight: 600; font-size: 13px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between;">
                        <span>Digital Signature (ખેડૂતની સહી)</span>
                        <button type="button" class="btn-sm btn-secondary" onclick="clearSignature()" style="font-size: 11px;">Clear</button>
                    </div>
                    <canvas id="sig-canvas" width="550" height="150" style="width: 100%; height: 150px; touch-action: none; background: #fff; cursor: crosshair; display: block;"></canvas>
                    <input type="hidden" name="signature_base64" id="signature_base64">
                </div>

                <div style="background: #fff8e1; border-left: 3px solid #FFC107; padding: 10px; font-size: 12px; margin-top: 15px;">
                    <strong>Note:</strong> Bill Number will be auto-generated based on the financial year.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= $live_stock <= 0 ? 'disabled title="Out of stock"' : '' ?>>Generate Bill</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() { document.getElementById('addModal').style.display = 'flex'; }
    
    function closeModal() { 
        document.getElementById('addModal').style.display = 'none'; 
        clearSignature(); // Wipe signature if they close without saving
    }

    // Live search filtering
    function filterTable() {
        let input = document.getElementById("searchInput").value.toLowerCase();
        let table = document.getElementById("salesTable");
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

    // --- Signature Pad Logic (Tablet Optimized) ---
    const canvas = document.getElementById('sig-canvas');
    const ctx = canvas.getContext('2d');
    let drawing = false;

    // Set line styles for signature
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#005A9E'; // Enterprise Blue ink

    function getMousePos(canvasDom, mouseEvent) {
        let rect = canvasDom.getBoundingClientRect();
        // Scale mouse coordinates to canvas coordinates (solves CSS width stretching issues)
        let scaleX = canvasDom.width / rect.width;
        let scaleY = canvasDom.height / rect.height;
        return {
            x: (mouseEvent.clientX - rect.left) * scaleX,
            y: (mouseEvent.clientY - rect.top) * scaleY
        };
    }

    function getTouchPos(canvasDom, touchEvent) {
        let rect = canvasDom.getBoundingClientRect();
        let scaleX = canvasDom.width / rect.width;
        let scaleY = canvasDom.height / rect.height;
        return {
            x: (touchEvent.touches[0].clientX - rect.left) * scaleX,
            y: (touchEvent.touches[0].clientY - rect.top) * scaleY
        };
    }

    // Mouse Events (Desktop)
    canvas.addEventListener("mousedown", function(e) {
        drawing = true;
        let mousePos = getMousePos(canvas, e);
        ctx.beginPath();
        ctx.moveTo(mousePos.x, mousePos.y);
    }, false);
    
    canvas.addEventListener("mouseup", function(e) { drawing = false; updateHiddenSig(); }, false);
    canvas.addEventListener("mousemove", function(e) {
        if (!drawing) return;
        let mousePos = getMousePos(canvas, e);
        ctx.lineTo(mousePos.x, mousePos.y);
        ctx.stroke();
    }, false);

    // Touch Events (Tablets/Phones)
    canvas.addEventListener("touchstart", function(e) {
        e.preventDefault(); // Prevent scrolling when signing
        drawing = true;
        let touchPos = getTouchPos(canvas, e);
        ctx.beginPath();
        ctx.moveTo(touchPos.x, touchPos.y);
    }, { passive: false });

    canvas.addEventListener("touchend", function(e) { drawing = false; updateHiddenSig(); }, false);
    canvas.addEventListener("touchmove", function(e) {
        e.preventDefault();
        if (!drawing) return;
        let touchPos = getTouchPos(canvas, e);
        ctx.lineTo(touchPos.x, touchPos.y);
        ctx.stroke();
    }, { passive: false });

    function clearSignature() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        document.getElementById('signature_base64').value = '';
    }

    function updateHiddenSig() {
        // Save canvas as Base64 to hidden input for PHP processing
        let dataUrl = canvas.toDataURL("image/png");
        document.getElementById('signature_base64').value = dataUrl;
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php
// bardan_purchase.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/media_handler.php'; // Required for Base64 processing

$message = '';
$msg_type = '';
$user_id = $_SESSION['user_id'];

// Ensure upload directory exists for receipts
$upload_dir = __DIR__ . '/uploads/receipts/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ---------------------------------------------------------
// 1. HANDLE FORM SUBMISSIONS (ADD PURCHASE)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_purchase') {
    $purchase_date = $_POST['purchase_date'];
    $supplier_name = trim($_POST['supplier_name']);
    $supplier_mobile = trim($_POST['supplier_mobile']);
    $total_nos = (int)$_POST['total_nos'];
    $rate_per_bardan = (float)$_POST['rate_per_bardan'];
    $total_amount = $total_nos * $rate_per_bardan;
    $note = trim($_POST['note']);
    $receipt_base64 = $_POST['receipt_base64'] ?? '';

    // Handle File Upload Function for Receipts
    function handleReceiptUpload($fileArray) {
        global $upload_dir;
        if (isset($fileArray) && $fileArray['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $fileArray['tmp_name'];
            $name = basename($fileArray['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            if (!in_array($ext, $allowed)) throw new Exception("Only JPG, PNG, WebP, and PDF allowed for receipts.");
            
            $new_filename = uniqid('receipt_') . '.' . $ext;
            if (move_uploaded_file($tmp_name, $upload_dir . $new_filename)) {
                return 'uploads/receipts/' . $new_filename;
            }
        }
        return null;
    }

    try {
        $pdo->beginTransaction();

        // 1. Handle Receipt Media (File upload takes priority, then camera)
        $receipt_photo = handleReceiptUpload($_FILES['receipt_upload'] ?? null);
        
        if (!$receipt_photo && !empty($receipt_base64)) {
            // saveBase64Image is defined in media_handler.php
            $receipt_photo = saveBase64Image($receipt_base64, 'receipts'); 
        }

        // 2. Insert into Bardan Purchase Table
        $stmt = $pdo->prepare("
            INSERT INTO bardan_purchase 
            (purchase_date, supplier_name, supplier_mobile, total_nos, rate_per_bardan, total_amount, note, receipt_photo, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$purchase_date, $supplier_name, $supplier_mobile, $total_nos, $rate_per_bardan, $total_amount, $note, $receipt_photo, $user_id]);
        $purchase_id = $pdo->lastInsertId();

        // 3. Insert into Stock Ledger (INWARD)
        $stmtLedger = $pdo->prepare("INSERT INTO bardan_stock_ledger (transaction_type, reference_id, quantity, transaction_date) VALUES ('IN', ?, ?, ?)");
        $stmtLedger->execute([$purchase_id, $total_nos, $purchase_date]);

        // 4. Audit Log
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Bardan Purchase')")
            ->execute([$user_id, "Purchased {$total_nos} bags from {$supplier_name}"]);

        $pdo->commit();
        $message = "Bardan purchase recorded and stock updated successfully!";
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
$purchases = $pdo->query("
    SELECT p.*, u.full_name as creator_name 
    FROM bardan_purchase p 
    LEFT JOIN users u ON p.created_by = u.id 
    ORDER BY p.purchase_date DESC, p.id DESC
")->fetchAll();

// KPI: Total Stock & Total Value
$stmtStock = $pdo->query("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'IN' THEN quantity ELSE -quantity END), 0) FROM bardan_stock_ledger");
$live_stock = (int)$stmtStock->fetchColumn();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    /* Enterprise UI Framework */
    .form-control { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 14px; outline: none; }
    .form-control:focus { border-color: var(--primary); }
    .form-group { margin-bottom: 15px; }
    .form-label { font-weight: 600; font-size: 13px; margin-bottom: 6px; display: block; color: var(--text-main); }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    
    .btn { padding: 8px 16px; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
    .btn-primary { background-color: var(--primary); color: #fff; }
    .btn-secondary { background-color: #e1dfdd; color: var(--text-main); }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    
    .search-input { width: 100%; max-width: 300px; padding: 8px 12px; border: 1px solid #ccc; border-radius: var(--radius); outline: none; font-size: 14px; }
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; }
    .modal-content { background: var(--card-bg); width: 95%; max-width: 650px; border-radius: 6px; max-height: 90vh; overflow-y: auto; }
    .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: var(--card-bg); z-index: 10; }
    .modal-body { padding: 24px; }
    .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; background: #faf9f8; position: sticky; bottom: 0; }
    .close-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); }
    
    .alert { padding: 16px; border-radius: var(--radius); margin-bottom: 20px; font-weight: 500; }
    .alert-success { background-color: #dff6dd; color: #107c10; border: 1px solid #c3f3c0; }
    .alert-danger { background-color: #fde7e9; color: #d13438; border: 1px solid #f9d2d5; }
</style>

<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title" style="margin-bottom: 5px;">📥 Bardan Purchase (Inward)</h1>
        <div style="background: var(--card-bg); border: 1px solid var(--border); padding: 8px 16px; border-radius: var(--radius); font-weight: 600; font-size: 14px;">
            Total Live Stock: <span style="color: var(--primary); font-size: 16px;"><?= number_format($live_stock) ?> Bardans</span>
        </div>
    </div>
    <button class="btn btn-primary" onclick="openModal('addModal')">
        <span style="font-size:18px;">+</span> Add Purchase
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="table-container">
    <div class="table-header flex-between">
        <span>Recent Supplier Purchases</span>
        <input type="text" id="searchInput" class="search-input" placeholder="Search supplier..." onkeyup="filterTable()">
    </div>
    <table id="purchaseTable">
        <thead>
            <tr>
                <th>Date</th>
                <th>Supplier Name</th>
                <th style="text-align:center;">Qty Bought</th>
                <th>Rate (₹)</th>
                <th>Total Amount (₹)</th>
                <th style="text-align:center;">Merchant Receipt</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($purchases) > 0): ?>
                <?php foreach ($purchases as $row): ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($row['purchase_date'])) ?></td>
                        <td style="font-weight: 600; color: var(--primary);">
                            <?= htmlspecialchars($row['supplier_name'] ?? 'Legacy Record') ?><br>
                            <span style="font-size:11px; color:var(--text-muted);">📞 <?= htmlspecialchars($row['supplier_mobile'] ?? 'N/A') ?></span>
                        </td>
                        <td style="text-align:center;"><span class="badge badge-in">+<?= number_format($row['total_nos'] ?? 0) ?></span></td>
                        <td><?= number_format($row['rate_per_bardan'] ?? 0, 2) ?></td>
                        <td style="font-weight: 600;">₹ <?= number_format($row['total_amount'] ?? 0, 2) ?></td>
                        <td style="text-align:center;">
                            <?php if (!empty($row['receipt_photo'])): ?>
                                <button class="btn btn-secondary btn-sm" onclick="viewReceipt('<?= htmlspecialchars($row['receipt_photo']) ?>')">🧾 View Bill</button>
                            <?php else: ?>
                                <span style="color: #aaa; font-size: 12px;">No Receipt</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">No purchases found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="addModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="font-size: 18px; color: var(--primary);">Record Bardan Purchase</h2>
            <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" action="bardan_purchase.php" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_purchase">
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Purchase Date *</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Supplier Name *</label>
                        <input type="text" name="supplier_name" class="form-control" placeholder="e.g. Ramdev Polymers" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Supplier Mobile</label>
                        <input type="text" name="supplier_mobile" class="form-control" placeholder="10-digit number">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quantity Bought (Bags) *</label>
                        <input type="number" name="total_nos" id="calc_qty" class="form-control" min="1" required oninput="calcTotal()">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Rate Per Bardan (₹) *</label>
                        <input type="number" step="0.01" name="rate_per_bardan" id="calc_rate" class="form-control" required oninput="calcTotal()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Total Amount (₹)</label>
                        <input type="text" id="calc_total" class="form-control" readonly style="background:#f3f2f1; font-weight:bold; color:var(--success);">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Merchant Receipt / Bill Document</label>
                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <input type="file" name="receipt_upload" class="form-control" accept=".jpg, .jpeg, .png, .pdf" style="padding: 7px; flex: 1;">
                        <button type="button" class="btn btn-secondary" onclick="startScanner()" title="Scan Document">📷 Scan Bill</button>
                    </div>
                    
                    <div id="scanner-container" style="display: none; border: 1px solid var(--border); border-radius: var(--radius); padding: 10px; background: #faf9f8; text-align: center;">
                        <div style="margin-bottom: 10px; font-size: 12px; color: var(--text-muted); font-weight:600;">Point rear camera at the receipt document</div>
                        <video id="live-video" width="100%" height="auto" autoplay playsinline style="max-height: 300px; background: #000; border-radius: 4px;"></video>
                        <canvas id="capture-canvas" style="display: none;"></canvas>
                        <img id="capture-preview" style="display: none; width: 100%; max-height: 300px; border-radius: 4px; object-fit: contain; border: 1px solid #ccc;" />
                        
                        <div style="margin-top: 10px; display: flex; justify-content: center; gap: 10px;">
                            <button type="button" id="btn-snap" class="btn btn-primary btn-sm" onclick="takeSnapshot()">📸 Capture Receipt</button>
                            <button type="button" id="btn-retake" class="btn btn-secondary btn-sm" onclick="retakePhoto()" style="display:none;">🔄 Retake</button>
                            <button type="button" class="btn btn-sm" style="background:#fde7e9; color:#d13438;" onclick="stopScanner()">✖ Close Scanner</button>
                        </div>
                        <input type="hidden" name="receipt_base64" id="receipt_base64">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Internal Note</label>
                    <input type="text" name="note" class="form-control" placeholder="Optional details...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Purchase</button>
            </div>
        </form>
    </div>
</div>

<div id="receiptModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2 style="font-size: 18px; color: var(--primary);">Merchant Receipt</h2>
            <button class="close-btn" onclick="closeModal('receiptModal')">&times;</button>
        </div>
        <div class="modal-body" style="text-align: center; background: #222; padding: 20px;">
            <img id="receiptImage" src="" style="max-width: 100%; max-height: 60vh; object-fit: contain; border-radius: 4px; box-shadow: 0 4px 15px rgba(0,0,0,0.5);">
        </div>
        <div class="modal-footer">
            <a id="downloadBtn" href="" download class="btn btn-primary">⬇️ Download Receipt</a>
            <button type="button" class="btn btn-secondary" onclick="closeModal('receiptModal')">Close</button>
        </div>
    </div>
</div>

<script>
    // Live Calculation
    function calcTotal() {
        let qty = parseFloat(document.getElementById('calc_qty').value) || 0;
        let rate = parseFloat(document.getElementById('calc_rate').value) || 0;
        document.getElementById('calc_total').value = (qty * rate).toFixed(2);
    }

    // Modal Handling
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { 
        document.getElementById(id).style.display = 'none'; 
        if(id === 'addModal') stopScanner(); 
    }

    // Receipt Viewer
    function viewReceipt(path) {
        document.getElementById('receiptImage').src = path;
        document.getElementById('downloadBtn').href = path;
        openModal('receiptModal');
    }

    // Live Search
    function filterTable() {
        let input = document.getElementById("searchInput").value.toLowerCase();
        let tr = document.getElementById("purchaseTable").getElementsByTagName("tr");
        for (let i = 1; i < tr.length; i++) {
            let td = tr[i].getElementsByTagName("td")[1]; // Search by Supplier Name
            if (td) {
                let txt = td.textContent || td.innerText;
                tr[i].style.display = txt.toLowerCase().indexOf(input) > -1 ? "" : "none";
            }
        }
    }

    // --- Live WebRTC Document Scanner Logic ---
    let video = document.getElementById('live-video');
    let canvasCam = document.getElementById('capture-canvas');
    let ctxCam = canvasCam.getContext('2d');
    let preview = document.getElementById('capture-preview');
    let stream = null;

    async function startScanner() {
        document.getElementById('scanner-container').style.display = 'block';
        preview.style.display = 'none';
        video.style.display = 'block';
        document.getElementById('btn-snap').style.display = 'inline-flex';
        document.getElementById('btn-retake').style.display = 'none';

        try {
            // Request camera (Prefer rear/environment camera for scanning documents)
            stream = await navigator.mediaDevices.getUserMedia({ 
                video: { facingMode: "environment" }, 
                audio: false 
            });
            video.srcObject = stream;
        } catch (err) {
            alert("Error accessing camera: " + err.message);
            stopScanner();
        }
    }

    function takeSnapshot() {
        canvasCam.width = video.videoWidth;
        canvasCam.height = video.videoHeight;
        ctxCam.drawImage(video, 0, 0, canvasCam.width, canvasCam.height);
        
        let dataUrl = canvasCam.toDataURL('image/jpeg', 0.85);
        document.getElementById('receipt_base64').value = dataUrl;
        
        preview.src = dataUrl;
        preview.style.display = 'block';
        video.style.display = 'none';
        
        document.getElementById('btn-snap').style.display = 'none';
        document.getElementById('btn-retake').style.display = 'inline-flex';
    }

    function retakePhoto() {
        document.getElementById('receipt_base64').value = '';
        preview.style.display = 'none';
        video.style.display = 'block';
        document.getElementById('btn-snap').style.display = 'inline-flex';
        document.getElementById('btn-retake').style.display = 'none';
    }

    function stopScanner() {
        if (stream) stream.getTracks().forEach(track => track.stop());
        document.getElementById('scanner-container').style.display = 'none';
        document.getElementById('receipt_base64').value = '';
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
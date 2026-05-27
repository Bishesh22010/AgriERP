<?php
// purchase_book.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_purchase') {
    $purchase_date  = $_POST['purchase_date'];
    $farmer_id      = $_POST['farmer_id'];
    $deductions     = (float)($_POST['deductions'] ?: 0);
    $payment_status = $_POST['payment_status'];
    $created_by     = $_SESSION['user_id'];

    // 1. Calculate Grand Totals from arrays
    $total_amount = 0;
    $items_data = [];
    
    if (isset($_POST['grain_type']) && is_array($_POST['grain_type'])) {
        for ($i = 0; $i < count($_POST['grain_type']); $i++) {
            $w = (float)$_POST['weight'][$i];
            $r = (float)$_POST['rate'][$i];
            $amt = $w * $r;
            $total_amount += $amt;
            
            $items_data[] = [
                'grain' => $_POST['grain_type'][$i],
                'bardan' => (int)($_POST['bardan_used'][$i] ?: 0),
                'weight' => $w,
                'rate' => $r,
                'amount' => $amt
            ];
        }
    }

    $final_amount = $total_amount - $deductions;

    // 2. Generate Unique Purchase Number
    $stmtCount = $pdo->prepare("SELECT COUNT(id) FROM purchase_book WHERE purchase_date = ?");
    $stmtCount->execute([$purchase_date]);
    $daily_count = $stmtCount->fetchColumn() + 1;
    $purchase_no = 'PUR-' . date('Ymd', strtotime($purchase_date)) . '-' . str_pad($daily_count, 3, '0', STR_PAD_LEFT);

    try {
        $pdo->beginTransaction();

        // 3. Insert Master Record
        $stmt = $pdo->prepare("
            INSERT INTO purchase_book 
            (purchase_no, purchase_date, farmer_id, total_amount, deductions, final_amount, payment_status, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$purchase_no, $purchase_date, $farmer_id, $total_amount, $deductions, $final_amount, $payment_status, $created_by]);
        $purchase_id = $pdo->lastInsertId();

        // 4. Insert Detail Items
        $stmtItem = $pdo->prepare("INSERT INTO purchase_book_items (purchase_id, grain_type, bardan_used, weight, rate, amount) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($items_data as $item) {
            $stmtItem->execute([$purchase_id, $item['grain'], $item['bardan'], $item['weight'], $item['rate'], $item['amount']]);
        }

        // 5. Audit Log
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Purchase Book')")->execute([$created_by, "Recorded Grain Purchase: {$purchase_no}"]);

        $pdo->commit();
        $message = "Purchase recorded successfully! ID: {$purchase_no}";
        $msg_type = "success";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// FETCH DATA FOR UI
$farmers = $pdo->query("SELECT id, full_name, mobile_no FROM farmers ORDER BY full_name")->fetchAll();

$purchases = $pdo->query("
    SELECT p.*, f.full_name, f.town_city,
           (SELECT COUNT(id) FROM purchase_book_items WHERE purchase_id = p.id) as item_count,
           (SELECT SUM(weight) FROM purchase_book_items WHERE purchase_id = p.id) as total_weight
    FROM purchase_book p 
    LEFT JOIN farmers f ON p.farmer_id = f.id 
    ORDER BY p.purchase_date DESC, p.id DESC
")->fetchAll();

$kpi = $pdo->query("
    SELECT COUNT(p.id) as today_entries, COALESCE(SUM(p.final_amount), 0) as today_amount,
           (SELECT COALESCE(SUM(weight), 0) FROM purchase_book_items pi JOIN purchase_book pb ON pi.purchase_id = pb.id WHERE pb.purchase_date = CURDATE()) as today_weight
    FROM purchase_book p WHERE p.purchase_date = CURDATE()
")->fetch();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    .form-control { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: var(--radius); font-size: 14px; background-color: #fff; transition: border-color 0.2s; outline: none; }
    .form-control:focus { border-color: #888; }
    .form-control[readonly] { background-color: #f3f2f1; color: var(--text-muted); cursor: not-allowed; font-weight: 600; }
    .form-group { margin-bottom: 15px; }
    .form-label { font-weight: 600; font-size: 13px; color: var(--text-main); display: block; margin-bottom: 5px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
    .btn { padding: 8px 16px; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 13px; }
    .btn-primary { background-color: #005A9E; color: #fff; display: flex; align-items: center; gap: 6px; }
    .btn-primary:hover { background-color: #004578; }
    .btn-secondary { background-color: #e1dfdd; color: var(--text-main); }
    .btn-secondary:hover { background-color: #d2d0ce; }
    .btn-sm { padding: 4px 8px; font-size: 11px; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 1000; justify-content: center; align-items: center; }
    .modal-content { background: var(--card-bg); width: 100%; max-width: 900px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto;}
    .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 24px; }
    .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; background: #faf9f8; }
    
    .item-table { width: 100%; border-collapse: collapse; }
    .item-table th, .item-table td { padding: 8px; text-align: left; border-bottom: 1px solid var(--border); }
    .item-table th { font-size: 12px; font-weight: 600; color: var(--text-muted); }
    
    .mini-kpi-container { display: flex; gap: 20px; margin-bottom: 20px; }
    .mini-kpi { background: var(--card-bg); padding: 15px; border: 1px solid var(--border); border-radius: var(--radius); flex: 1; }
    .mini-kpi-title { font-size: 11px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 5px; }
    .mini-kpi-value { font-size: 20px; font-weight: 600; color: var(--primary); }
</style>

<div class="flex-between" style="margin-bottom: 20px;">
    <h1 class="page-title" style="margin-bottom: 0;">🌾 Purchase Book (Multiple Items)</h1>
    <button class="btn btn-primary" onclick="openModal()">✦ Add Purchase Entry</button>
</div>

<?php if ($message): ?>
    <div style="padding: 12px 16px; border-radius: 2px; margin-bottom: 20px; font-weight: 500; background: #dff6dd; color: #107c10;"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="mini-kpi-container">
    <div class="mini-kpi"><div class="mini-kpi-title">Today's Entries</div><div class="mini-kpi-value"><?= number_format($kpi['today_entries']) ?></div></div>
    <div class="mini-kpi"><div class="mini-kpi-title">Total Weight Today</div><div class="mini-kpi-value"><?= number_format($kpi['today_weight'], 2) ?> <span style="font-size:12px;">Kg</span></div></div>
    <div class="mini-kpi"><div class="mini-kpi-title">Total Payout Today</div><div class="mini-kpi-value" style="color: var(--success);">₹ <?= number_format($kpi['today_amount'], 2) ?></div></div>
</div>

<div class="table-container">
    <table id="purchaseTable">
        <thead>
            <tr>
                <th>Date</th>
                <th>Purchase No.</th>
                <th>Farmer Name</th>
                <th>Items Count</th>
                <th>Total Weight</th>
                <th>Final Amount (₹)</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($purchases as $row): ?>
                <tr>
                    <td><?= date('d-m-Y', strtotime($row['purchase_date'])) ?></td>
                    <td style="font-weight: 600; color: var(--primary);"><?= htmlspecialchars($row['purchase_no']) ?></td>
                    <td><?= htmlspecialchars($row['full_name']) ?></td>
                    <td><span style="background:#e1dfdd; padding:4px 8px; border-radius:12px; font-size:11px;"><?= $row['item_count'] ?> Item(s)</span></td>
                    <td><?= number_format($row['total_weight'], 2) ?> kg</td>
                    <td style="font-weight: 600;"><?= number_format($row['final_amount'], 2) ?></td>
                    <td><?= $row['payment_status'] === 'Paid' ? '<span style="color:green;">Paid</span>' : '<span style="color:orange;">Pending</span>' ?></td>
                    <td><button class="btn btn-secondary btn-sm" onclick="window.open('print_purchase_bill.php?id=<?= $row['id'] ?>', 'Print', 'width=800,height=850,scrollbars=yes')">🖨️ Receipt</button></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="addModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="font-size: 18px; color: var(--primary);">New Multiple Grain Purchase</h2>
            <button class="btn" style="background:none; font-size:20px;" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="purchase_book.php">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_purchase">
                <div class="grid-2" style="margin-bottom:20px;">
                    <div class="form-group"><label class="form-label">Purchase Date *</label><input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group">
                        <label class="form-label">Farmer *</label>
                        <select name="farmer_id" class="form-control" required>
                            <option value="">-- Select Farmer --</option>
                            <?php foreach($farmers as $farm): ?>
                                <option value="<?= $farm['id'] ?>"><?= htmlspecialchars($farm['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="border: 1px solid var(--border); border-radius: 4px; margin-bottom: 20px;">
                    <table class="item-table">
                        <thead style="background: #faf9f8;">
                            <tr>
                                <th>Grain Type *</th>
                                <th width="15%">Bags</th>
                                <th width="20%">Weight (Kg) *</th>
                                <th width="20%">Rate (₹) *</th>
                                <th width="20%">Amount (₹)</th>
                                <th width="5%"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            </tbody>
                    </table>
                    <div style="padding: 10px; text-align: center; background: #faf9f8; border-top: 1px solid var(--border);">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addRow()">+ Add Another Grain</button>
                    </div>
                </div>

                <div class="grid-3">
                    <div class="form-group"><label class="form-label">Total Amount (₹)</label><input type="text" id="calc_total" class="form-control" readonly value="0.00"></div>
                    <div class="form-group"><label class="form-label">Deductions (₹)</label><input type="number" step="0.01" name="deductions" id="calc_deductions" class="form-control" placeholder="0.00" oninput="calcTotals()"></div>
                    <div class="form-group">
                        <label class="form-label">Payment Status *</label>
                        <select name="payment_status" class="form-control" required>
                            <option value="Pending">Pending (ઉધાર)</option>
                            <option value="Paid">Paid (રોકડ)</option>
                        </select>
                    </div>
                </div>
                <div style="text-align: right; font-size: 20px; font-weight: bold; margin-top: 10px;">
                    Final Payable: <span id="calc_final" style="color: var(--success);">₹ 0.00</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Invoice</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() { 
        document.getElementById('addModal').style.display = 'flex'; 
        if(document.getElementById('itemsBody').children.length === 0) addRow();
    }
    function closeModal() { document.getElementById('addModal').style.display = 'none'; }

    function addRow() {
        let tr = document.createElement('tr');
        tr.innerHTML = `
            <td><select name="grain_type[]" class="form-control" required><option value="Dangar">Dangar</option><option value="Ghau">Ghau</option><option value="Bajri">Bajri</option><option value="Mafri">Mafri</option><option value="Other">Other</option></select></td>
            <td><input type="number" name="bardan_used[]" class="form-control" placeholder="0" min="0"></td>
            <td><input type="number" step="0.01" name="weight[]" class="form-control item-weight" required oninput="calcTotals()" placeholder="0.00"></td>
            <td><input type="number" step="0.01" name="rate[]" class="form-control item-rate" required oninput="calcTotals()" placeholder="0.00"></td>
            <td><input type="text" class="form-control item-amount" readonly value="0.00"></td>
            <td><button type="button" class="btn btn-sm" style="color:var(--danger); background:none;" onclick="this.closest('tr').remove(); calcTotals();">✖</button></td>
        `;
        document.getElementById('itemsBody').appendChild(tr);
    }

    function calcTotals() {
        let total = 0;
        let rows = document.getElementById('itemsBody').getElementsByTagName('tr');
        
        for (let row of rows) {
            let w = parseFloat(row.querySelector('.item-weight').value) || 0;
            let r = parseFloat(row.querySelector('.item-rate').value) || 0;
            let amt = w * r;
            row.querySelector('.item-amount').value = amt.toFixed(2);
            total += amt;
        }

        let ded = parseFloat(document.getElementById('calc_deductions').value) || 0;
        document.getElementById('calc_total').value = total.toFixed(2);
        document.getElementById('calc_final').innerText = '₹ ' + (total - ded).toFixed(2);
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
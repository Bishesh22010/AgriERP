<?php
// villages.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$message = '';
$msg_type = '';

// ---------------------------------------------------------
// 1. HANDLE FORM SUBMISSIONS (ADD & EDIT VILLAGE)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $village_name = trim($_POST['village_name']);
    $village_name_gujarati = trim($_POST['village_name_gujarati']);
    $user_id = $_SESSION['user_id'];

    if ($action === 'add_village') {
        try {
            // Check for duplicates
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM villages WHERE LOWER(village_name) = LOWER(?)");
            $stmtCheck->execute([$village_name]);
            
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("A village named '{$village_name}' already exists.");
            }

            $stmt = $pdo->prepare("INSERT INTO villages (village_name, village_name_gujarati) VALUES (?, ?)");
            $stmt->execute([$village_name, $village_name_gujarati]);
            
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Village Master')")
                ->execute([$user_id, "Added Village: {$village_name}"]);

            $message = "Village '{$village_name}' successfully added.";
            $msg_type = "success";
        } catch (Exception $e) {
            $message = $e->getMessage();
            $msg_type = "danger";
        }
    } 
    elseif ($action === 'edit_village') {
        $village_id = (int)$_POST['village_id'];
        try {
            // Check for duplicates excluding current village
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM villages WHERE LOWER(village_name) = LOWER(?) AND id != ?");
            $stmtCheck->execute([$village_name, $village_id]);
            
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("Another village named '{$village_name}' already exists.");
            }

            $stmt = $pdo->prepare("UPDATE villages SET village_name = ?, village_name_gujarati = ? WHERE id = ?");
            $stmt->execute([$village_name, $village_name_gujarati, $village_id]);
            
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Village Master')")
                ->execute([$user_id, "Updated Village ID: {$village_id} to {$village_name}"]);

            $message = "Village successfully updated.";
            $msg_type = "success";
        } catch (Exception $e) {
            $message = $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// ---------------------------------------------------------
// 2. FETCH DATA FOR UI
// ---------------------------------------------------------
$stmt = $pdo->query("
    SELECT v.*, 
           (SELECT COUNT(id) FROM farmers WHERE village_id = v.id) as farmer_count 
    FROM villages v 
    ORDER BY v.village_name ASC
");
$villages = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    /* Tablet Friendly Enterprise UI Enhancements */
    .form-control { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 15px; background-color: #fff; transition: border-color 0.2s; outline: none; }
    .form-control:focus { border-color: var(--primary); }
    .form-group { margin-bottom: 20px; }
    .form-label { font-weight: 600; font-size: 14px; color: var(--text-main); display: block; margin-bottom: 6px; }
    
    /* Touch Friendly Buttons */
    .btn { padding: 10px 20px; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 14px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .btn-primary { background-color: var(--primary); color: #fff; }
    .btn-primary:hover { background-color: var(--primary-hover); }
    .btn-secondary { background-color: #e1dfdd; color: var(--text-main); }
    .btn-secondary:hover { background-color: #d2d0ce; }
    .btn-action { padding: 6px 12px; font-size: 12px; min-height: 36px; }

    /* Modals */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1050; align-items: center; justify-content: center; }
    .modal-content { background: var(--card-bg); width: 90%; max-width: 500px; border-radius: 6px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: slideDown 0.3s ease-out; }
    @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 24px; }
    .modal-footer { padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; background: #faf9f8; border-radius: 0 0 6px 6px; }
    .close-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); line-height: 1; }

    .search-wrapper { display: flex; justify-content: flex-end; margin-bottom: 15px; }
    .search-input { width: 100%; max-width: 350px; padding: 12px 16px; border: 1px solid #ccc; border-radius: var(--radius); outline: none; font-size: 15px; }
    
    .alert { padding: 16px; border-radius: var(--radius); margin-bottom: 20px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
    .alert-success { background-color: #dff6dd; color: #107c10; border: 1px solid #c3f3c0; }
    .alert-danger { background-color: #fde7e9; color: #d13438; border: 1px solid #f9d2d5; }
</style>

<div class="flex-between" style="margin-bottom: 24px; flex-wrap: wrap; gap: 15px;">
    <h1 class="page-title" style="margin-bottom: 0;">📍 Village Master System</h1>
    <button class="btn btn-primary" onclick="openModal('addModal')">
        <span style="font-size:18px;">+</span> Add New Village
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="table-container">
    <div class="table-header flex-between" style="flex-wrap: wrap; gap: 15px;">
        <span style="font-size: 16px;">Registered Villages / ગામ</span>
        <div class="search-wrapper" style="margin: 0;">
            <input type="text" id="searchInput" class="search-input" placeholder="Search village in English or Gujarati..." onkeyup="filterTable()">
        </div>
    </div>
    <table id="villageTable">
        <thead>
            <tr>
                <th width="10%">ID</th>
                <th width="35%">Village Name (English)</th>
                <th width="35%">ગામનું નામ (Gujarati)</th>
                <th width="10%">Registered Farmers</th>
                <th width="10%">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($villages) > 0): ?>
                <?php foreach ($villages as $row): ?>
                    <tr>
                        <td style="color: var(--text-muted);">V-<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($row['village_name']) ?></td>
                        <td style="font-size: 16px;"><?= htmlspecialchars($row['village_name_gujarati']) ?></td>
                        <td>
                            <span class="badge" style="background:#e1dfdd; color:#333; font-size:12px; padding:6px 12px;">
                                <?= $row['farmer_count'] ?> Farmers
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-secondary btn-action" 
                                    onclick="openEditModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['village_name'])) ?>', '<?= htmlspecialchars(addslashes($row['village_name_gujarati'])) ?>')">
                                ✏️ Edit
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        No villages found. Click "Add New Village" to create your directory.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="addModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="font-size: 20px; color: var(--primary);">Add New Village</h2>
            <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" action="villages.php">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_village">
                <div class="form-group">
                    <label class="form-label">Village Name (English) *</label>
                    <input type="text" name="village_name" class="form-control" placeholder="e.g. Anand" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label">ગામનું નામ (Gujarati)</label>
                    <input type="text" name="village_name_gujarati" class="form-control" placeholder="દા.ત. આણંદ">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Village</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="font-size: 20px; color: var(--primary);">Edit Village</h2>
            <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" action="villages.php">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_village">
                <input type="hidden" name="village_id" id="edit_village_id">
                <div class="form-group">
                    <label class="form-label">Village Name (English) *</label>
                    <input type="text" name="village_name" id="edit_village_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">ગામનું નામ (Gujarati)</label>
                    <input type="text" name="village_name_gujarati" id="edit_village_name_gujarati" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Village</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    function openEditModal(id, name, name_guj) {
        document.getElementById('edit_village_id').value = id;
        document.getElementById('edit_village_name').value = name;
        document.getElementById('edit_village_name_gujarati').value = name_guj;
        openModal('editModal');
    }

    function filterTable() {
        let input = document.getElementById("searchInput").value.toLowerCase();
        let table = document.getElementById("villageTable");
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let tdEng = tr[i].getElementsByTagName("td")[1];
            let tdGuj = tr[i].getElementsByTagName("td")[2];
            if (tdEng || tdGuj) {
                let txtEng = tdEng.textContent || tdEng.innerText;
                let txtGuj = tdGuj.textContent || tdGuj.innerText;
                if (txtEng.toLowerCase().indexOf(input) > -1 || txtGuj.indexOf(input) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }       
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
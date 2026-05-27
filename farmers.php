<?php
// farmers.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/media_handler.php'; // Required for Base64 processing

$message = '';
$msg_type = '';

// Ensure upload directory exists
$upload_dir = __DIR__ . '/uploads/photos/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ---------------------------------------------------------
// 1. HANDLE FORM SUBMISSIONS (ADD & EDIT)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_SESSION['user_id'];

    // Sanitize common inputs
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $full_name_gujarati = trim($_POST['full_name_gujarati']);
    $village_id = (int)$_POST['village_id'];
    $mobile_no = trim($_POST['mobile_no']);
    $address = trim($_POST['address']);
    $notes = trim($_POST['notes']);
    $live_photo_base64 = $_POST['live_photo_base64'] ?? ''; // Camera Capture Data
    
    // Auto-generate English Full Name
    $full_name = trim(preg_replace('/\s+/', ' ', "$first_name $middle_name $last_name"));

    // Handle File Upload Function
    function handlePhotoUpload($fileArray, $existingPath = null) {
        global $upload_dir;
        if (isset($fileArray) && $fileArray['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $fileArray['tmp_name'];
            $name = basename($fileArray['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            
            // Validate extension
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowed)) throw new Exception("Only JPG, PNG, and WebP images are allowed.");
            
            // Generate unique filename
            $new_filename = uniqid('farmer_') . '.' . $ext;
            $destination = $upload_dir . $new_filename;
            
            if (move_uploaded_file($tmp_name, $destination)) {
                // Delete old photo if replacing
                if ($existingPath && file_exists(__DIR__ . '/' . $existingPath)) {
                    unlink(__DIR__ . '/' . $existingPath);
                }
                return 'uploads/photos/' . $new_filename;
            }
        }
        return $existingPath; // Return old path if no new file
    }

    try {
        if ($action === 'add_farmer') {
            // Check Duplicate Mobile
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM farmers WHERE mobile_no = ?");
            $stmtCheck->execute([$mobile_no]);
            if ($stmtCheck->fetchColumn() > 0) throw new Exception("Mobile number {$mobile_no} is already registered.");

            $photo_path = handlePhotoUpload($_FILES['photo'] ?? null);
            $live_photo_path = null;

            // If no file uploaded but a camera photo was taken, use the camera photo
            if (!$photo_path && !empty($live_photo_base64)) {
                $live_photo_path = saveBase64Image($live_photo_base64, 'photos');
                $photo_path = $live_photo_path; // Set main photo to captured photo
            }

            $stmt = $pdo->prepare("
                INSERT INTO farmers (first_name, middle_name, last_name, full_name, full_name_gujarati, village_id, mobile_no, address, photo_path, live_photo_path, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$first_name, $middle_name, $last_name, $full_name, $full_name_gujarati, $village_id, $mobile_no, $address, $photo_path, $live_photo_path, $notes]);
            
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Farmer Master')")->execute([$user_id, "Added Farmer: {$full_name}"]);
            
            $message = "Farmer profile created successfully!";
            $msg_type = "success";

        } elseif ($action === 'edit_farmer') {
            $farmer_id = (int)$_POST['farmer_id'];
            
            // Check Duplicate Mobile (excluding self)
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM farmers WHERE mobile_no = ? AND id != ?");
            $stmtCheck->execute([$mobile_no, $farmer_id]);
            if ($stmtCheck->fetchColumn() > 0) throw new Exception("Mobile number {$mobile_no} is already used by another farmer.");

            // Get existing photo paths
            $existing = $pdo->query("SELECT photo_path, live_photo_path FROM farmers WHERE id = $farmer_id")->fetch();
            $photo_path = handlePhotoUpload($_FILES['photo'] ?? null, $existing['photo_path']);
            $live_photo_path = $existing['live_photo_path']; // Keep existing by default

            // If they took a NEW live photo during edit, override the paths
            if (empty($_FILES['photo']['name']) && !empty($live_photo_base64)) {
                $live_photo_path = saveBase64Image($live_photo_base64, 'photos');
                $photo_path = $live_photo_path; // Make the new capture the main photo
            }

            $stmt = $pdo->prepare("
                UPDATE farmers SET 
                    first_name=?, middle_name=?, last_name=?, full_name=?, full_name_gujarati=?, 
                    village_id=?, mobile_no=?, address=?, photo_path=?, live_photo_path=?, notes=? 
                WHERE id=?
            ");
            $stmt->execute([$first_name, $middle_name, $last_name, $full_name, $full_name_gujarati, $village_id, $mobile_no, $address, $photo_path, $live_photo_path, $notes, $farmer_id]);
            
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, module) VALUES (?, ?, 'Farmer Master')")->execute([$user_id, "Updated Farmer: {$full_name}"]);
            
            $message = "Farmer profile updated successfully!";
            $msg_type = "success";
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $msg_type = "danger";
    }
}

// ---------------------------------------------------------
// 2. FETCH DATA FOR UI
// ---------------------------------------------------------
$villages = $pdo->query("SELECT id, village_name, village_name_gujarati FROM villages ORDER BY village_name")->fetchAll();

// Fetch farmers sorted by village name, then farmer name for grouped UI
$stmtFarmers = $pdo->query("
    SELECT f.*, v.village_name, v.village_name_gujarati 
    FROM farmers f 
    LEFT JOIN villages v ON f.village_id = v.id 
    ORDER BY v.village_name ASC, f.first_name ASC
");
$farmers = $stmtFarmers->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
    /* Card Directory Layout */
    .village-group { margin-bottom: 40px; }
    .village-header { font-size: 18px; font-weight: 700; color: var(--primary); margin-bottom: 15px; border-bottom: 2px solid var(--border); padding-bottom: 8px; display: flex; align-items: center; gap: 10px; }
    .village-header .badge { font-size: 12px; background: #e1dfdd; color: #333; }
    
    .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
    
    .farmer-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 20px; display: flex; flex-direction: column; box-shadow: 0 2px 5px rgba(0,0,0,0.02); transition: box-shadow 0.2s, transform 0.2s; position: relative; overflow: hidden; }
    .farmer-card:hover { box-shadow: 0 5px 15px rgba(0,0,0,0.08); transform: translateY(-2px); border-color: #c7e0f4; }
    
    .card-top { display: flex; gap: 15px; align-items: flex-start; margin-bottom: 15px; }
    .avatar { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; background: #e1dfdd; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); flex-shrink: 0; }
    .avatar-placeholder { width: 60px; height: 60px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; flex-shrink: 0; }
    
    .info h3 { margin: 0 0 4px 0; font-size: 16px; color: var(--text-main); line-height: 1.2; }
    .info .guj-name { font-size: 14px; color: var(--text-muted); margin-bottom: 8px; }
    .info .mobile { font-size: 13px; font-weight: 600; color: #107c10; display: flex; align-items: center; gap: 5px; }
    
    .card-actions { border-top: 1px solid var(--border); padding-top: 12px; display: flex; justify-content: flex-end; gap: 10px; margin-top: auto; }

    /* Form Styles */
    .form-control { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 14px; outline: none; }
    .form-control:focus { border-color: var(--primary); }
    .form-group { margin-bottom: 15px; }
    .form-label { font-weight: 600; font-size: 13px; margin-bottom: 6px; display: block; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

    .btn { padding: 8px 16px; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
    .btn-primary { background-color: var(--primary); color: #fff; }
    .btn-secondary { background-color: #e1dfdd; color: var(--text-main); }
    .btn-sm { padding: 5px 10px; font-size: 12px; }

    /* Search */
    .search-input { width: 100%; max-width: 400px; padding: 12px 16px; border: 1px solid #ccc; border-radius: var(--radius); outline: none; font-size: 15px; }
    
    /* Modals */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
    .modal-content { background: var(--card-bg); width: 95%; max-width: 700px; border-radius: 6px; max-height: 90vh; overflow-y: auto; }
    .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: var(--card-bg); z-index: 10; }
    .modal-body { padding: 24px; }
    .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; background: #faf9f8; position: sticky; bottom: 0; }
    .close-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); }

    .alert { padding: 16px; border-radius: var(--radius); margin-bottom: 20px; font-weight: 500; }
    .alert-success { background-color: #dff6dd; color: #107c10; border: 1px solid #c3f3c0; }
    .alert-danger { background-color: #fde7e9; color: #d13438; border: 1px solid #f9d2d5; }
</style>

<div class="flex-between" style="margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
    <h1 class="page-title" style="margin-bottom: 0;">👥 Farmer Directory Master</h1>
    <div style="display: flex; gap: 15px; width: 100%; max-width: 600px; justify-content: flex-end;">
        <input type="text" id="searchInput" class="search-input" placeholder="Search by name, village, or mobile..." onkeyup="filterCards()">
        <button class="btn btn-primary" onclick="openModal('farmerModal')" style="white-space: nowrap;">
            <span style="font-size:18px;">+</span> Add Farmer
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div id="directoryContainer">
    <?php 
    $current_village = null;
    $village_count = 0;

    foreach ($farmers as $farmer): 
        // Logic to group by village
        if ($farmer['village_name'] !== $current_village): 
            if ($current_village !== null) echo "</div></div>"; // Close previous grid and group
            $current_village = $farmer['village_name'];
            $display_village = $current_village ? $current_village . ' (' . $farmer['village_name_gujarati'] . ')' : 'Unassigned / No Village';
    ?>
        <div class="village-group" data-village="<?= strtolower($current_village ?? '') ?>">
            <div class="village-header">
                📍 <?= htmlspecialchars($display_village) ?>
            </div>
            <div class="card-grid">
    <?php endif; ?>

        <div class="farmer-card search-target">
            <div class="card-top">
                <?php if (!empty($farmer['photo_path']) && file_exists(__DIR__ . '/' . $farmer['photo_path'])): ?>
                    <img src="<?= htmlspecialchars($farmer['photo_path']) ?>" class="avatar" alt="Photo">
                <?php else: ?>
                    <div class="avatar-placeholder"><?= strtoupper(substr($farmer['first_name'], 0, 1)) ?></div>
                <?php endif; ?>
                
                <div class="info">
                    <h3 class="search-name"><?= htmlspecialchars($farmer['full_name'] ?? '') ?></h3>
                    <div class="guj-name"><?= htmlspecialchars($farmer['full_name_gujarati'] ?? '') ?></div>
                    <div class="mobile search-mobile">📞 <?= htmlspecialchars($farmer['mobile_no'] ?? '') ?></div>
                </div>
            </div>
            
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px; line-height: 1.4;">
                <?= !empty($farmer['address']) ? '🏠 ' . htmlspecialchars(substr($farmer['address'], 0, 50)) . '...' : '<i>No address recorded</i>' ?>
            </div>

            <div class="card-actions">
                <button class="btn btn-secondary btn-sm" onclick='editFarmer(<?= json_encode($farmer, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏️ Edit Profile</button>
            </div>
        </div>

    <?php endforeach; ?>
    <?php if ($current_village !== null) echo "</div></div>"; // Close final group ?>
    
    <?php if (count($farmers) === 0): ?>
        <div style="text-align: center; padding: 50px; background: #fff; border: 1px dashed #ccc; border-radius: 8px; color: #666;">
            No farmers registered yet. Add your first farmer to build the directory.
        </div>
    <?php endif; ?>
</div>

<div id="farmerModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle" style="font-size: 18px; color: var(--primary);">Add New Farmer</h2>
            <button class="close-btn" onclick="closeModal('farmerModal')">&times;</button>
        </div>
        <form method="POST" action="farmers.php" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add_farmer">
                <input type="hidden" name="farmer_id" id="formFarmerId" value="">

                <h4 style="margin-bottom:10px; color:var(--text-main); border-bottom:1px solid #eee; padding-bottom:5px;">Personal Details</h4>
                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" id="f_first" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" id="f_middle" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" id="f_last" class="form-control" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">પૂરું નામ (Gujarati Full Name) *</label>
                        <input type="text" name="full_name_gujarati" id="f_guj" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Profile Photo (Upload or Capture)</label>
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <input type="file" name="photo" class="form-control" accept=".jpg, .jpeg, .png, .webp" style="padding: 7px; flex: 1;">
                            <button type="button" class="btn btn-secondary" onclick="startCamera()" title="Open Live Camera">📷 Capture</button>
                        </div>
                        
                        <div id="camera-container" style="display: none; border: 1px solid var(--border); border-radius: var(--radius); padding: 10px; background: #faf9f8; text-align: center;">
                            <video id="live-video" width="100%" height="auto" autoplay playsinline style="max-height: 250px; background: #000; border-radius: 4px;"></video>
                            <canvas id="capture-canvas" style="display: none;"></canvas>
                            <img id="capture-preview" style="display: none; width: 100%; max-height: 250px; border-radius: 4px; object-fit: cover;" />
                            
                            <div style="margin-top: 10px; display: flex; justify-content: center; gap: 10px;">
                                <button type="button" id="btn-snap" class="btn btn-primary btn-sm" onclick="takeSnapshot()">📸 Snap Photo</button>
                                <button type="button" id="btn-retake" class="btn btn-secondary btn-sm" onclick="retakePhoto()" style="display:none;">🔄 Retake</button>
                                <button type="button" class="btn btn-sm" style="background:#fde7e9; color:#d13438;" onclick="stopCamera()">✖ Close</button>
                            </div>
                            <input type="hidden" name="live_photo_base64" id="live_photo_base64">
                        </div>
                    </div>
                </div>

                <h4 style="margin-bottom:10px; margin-top:10px; color:var(--text-main); border-bottom:1px solid #eee; padding-bottom:5px;">Contact & Location</h4>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Mobile Number *</label>
                        <input type="text" name="mobile_no" id="f_mobile" class="form-control" required pattern="[0-9]{10}" title="Must be a 10 digit number">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Village *</label>
                        <select name="village_id" id="f_village" class="form-control" required>
                            <option value="">-- Select Village --</option>
                            <?php foreach($villages as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['village_name']) ?> (<?= htmlspecialchars($v['village_name_gujarati']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Full Address / Landmark</label>
                    <input type="text" name="address" id="f_address" class="form-control">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Internal Notes / Remarks</label>
                    <textarea name="notes" id="f_notes" class="form-control" rows="2" placeholder="Any specific requirements or history..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('farmerModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmit">Save Farmer Profile</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
        // Reset form for "Add" by default if opening via the top button
        if(event && event.target.innerText.includes('Add')) {
            document.getElementById('modalTitle').innerText = 'Add New Farmer';
            document.getElementById('formAction').value = 'add_farmer';
            document.getElementById('btnSubmit').innerText = 'Save Farmer Profile';
            document.querySelector('form').reset();
            stopCamera(); // Ensure camera UI is closed
            retakePhoto(); // Clear any previous captures
        }
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        stopCamera(); // Turn off webcam when closing modal
    }

    // Populate Modal with JSON data for Editing
    function editFarmer(farmer) {
        document.getElementById('modalTitle').innerText = 'Edit Farmer Profile';
        document.getElementById('formAction').value = 'edit_farmer';
        document.getElementById('btnSubmit').innerText = 'Update Profile';
        
        document.getElementById('formFarmerId').value = farmer.id;
        document.getElementById('f_first').value = farmer.first_name;
        document.getElementById('f_middle').value = farmer.middle_name;
        document.getElementById('f_last').value = farmer.last_name;
        document.getElementById('f_guj').value = farmer.full_name_gujarati;
        document.getElementById('f_mobile').value = farmer.mobile_no;
        document.getElementById('f_village').value = farmer.village_id;
        document.getElementById('f_address').value = farmer.address;
        document.getElementById('f_notes').value = farmer.notes;
        
        stopCamera(); // Ensure camera UI is closed
        retakePhoto(); // Clear any previous captures

        document.getElementById('farmerModal').style.display = 'flex';
    }

    // Live Card Filtering Logic
    function filterCards() {
        let input = document.getElementById("searchInput").value.toLowerCase();
        let groups = document.getElementsByClassName("village-group");

        for (let group of groups) {
            let villageName = group.getAttribute('data-village');
            let cards = group.getElementsByClassName("farmer-card");
            let groupHasVisibleCard = false;

            for (let card of cards) {
                let name = card.querySelector('.search-name').innerText.toLowerCase();
                let mobile = card.querySelector('.search-mobile').innerText.toLowerCase();
                
                // If input matches name, mobile, OR the village name of this group
                if (name.includes(input) || mobile.includes(input) || villageName.includes(input)) {
                    card.style.display = "flex";
                    groupHasVisibleCard = true;
                } else {
                    card.style.display = "none";
                }
            }

            // Hide the entire village block if no cards inside it match the search
            if (groupHasVisibleCard || villageName.includes(input)) {
                group.style.display = "block";
                // If the search matches the village name directly, show ALL cards in that village
                if (villageName.includes(input) && input.length > 2) {
                     for (let card of cards) card.style.display = "flex";
                }
            } else {
                group.style.display = "none";
            }
        }
    }

    // --- Live WebRTC Camera Logic ---
    let video = document.getElementById('live-video');
    let canvasCam = document.getElementById('capture-canvas');
    let ctxCam = canvasCam.getContext('2d');
    let preview = document.getElementById('capture-preview');
    let stream = null;

    async function startCamera() {
        document.getElementById('camera-container').style.display = 'block';
        preview.style.display = 'none';
        video.style.display = 'block';
        document.getElementById('btn-snap').style.display = 'inline-flex';
        document.getElementById('btn-retake').style.display = 'none';

        try {
            // Request camera (prefer user-facing for portraits)
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" }, audio: false });
            video.srcObject = stream;
        } catch (err) {
            alert("Error accessing camera: " + err.message);
            stopCamera();
        }
    }

    function takeSnapshot() {
        // Set canvas to actual video dimensions
        canvasCam.width = video.videoWidth;
        canvasCam.height = video.videoHeight;
        
        // Draw video frame to canvas
        ctxCam.drawImage(video, 0, 0, canvasCam.width, canvasCam.height);
        
        // Get Base64 data
        let dataUrl = canvasCam.toDataURL('image/jpeg', 0.8); // 80% quality to save space
        document.getElementById('live_photo_base64').value = dataUrl;
        
        // Show preview, hide video
        preview.src = dataUrl;
        preview.style.display = 'block';
        video.style.display = 'none';
        
        // Toggle buttons
        document.getElementById('btn-snap').style.display = 'none';
        document.getElementById('btn-retake').style.display = 'inline-flex';
    }

    function retakePhoto() {
        document.getElementById('live_photo_base64').value = '';
        preview.style.display = 'none';
        video.style.display = 'block';
        document.getElementById('btn-snap').style.display = 'inline-flex';
        document.getElementById('btn-retake').style.display = 'none';
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
        }
        document.getElementById('camera-container').style.display = 'none';
        document.getElementById('live_photo_base64').value = '';
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
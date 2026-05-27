<?php
// print_purchase_bill.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!isset($_GET['id'])) {
    die("<h2 style='text-align:center; padding:50px;'>Error: Purchase ID not provided.</h2>");
}

$purchase_id = (int)$_GET['id'];

// Fetch the purchase and farmer details
$stmt = $pdo->prepare("
    SELECT p.*, f.full_name, f.town_city, f.mobile_no 
    FROM purchase_book p 
    LEFT JOIN farmers f ON p.farmer_id = f.id 
    WHERE p.id = ?
");
$stmt->execute([$purchase_id]);
$bill = $stmt->fetch();

if (!$bill) {
    die("<h2 style='text-align:center; padding:50px;'>Error: Purchase record not found.</h2>");
}

// Helper function to convert English numbers to Gujarati numbers
function toGujarati($num) {
    $eng = ['0','1','2','3','4','5','6','7','8','9'];
    $guj = ['૦','૧','૨','૩','૪','૫','૬','૭','૮','૯'];
    return str_replace($eng, $guj, (string)$num);
}
// Translate Grain Types (Map kept for the table loop below)
$grain_map = [
    'Dangar' => 'ડાંગર',
    'Ghau' => 'ઘઉં',
    'Bajri' => 'બાજરી',
    'Mafri' => 'મગફળી',
    'Other' => 'અન્ય'
];
// NOTE: We deleted the $guj_grain variable here because grain_type is now handled in the items loop!

// Translate Payment Status
$status_map = ['Paid' => 'રોકડ (Paid)', 'Pending' => 'ઉધાર (Pending)'];
$guj_status = $status_map[$bill['payment_status']] ?? $bill['payment_status'];
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Receipt - <?= htmlspecialchars($bill['purchase_no']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Mukta+Vaani:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Mukta Vaani', sans-serif; }
        body { background-color: #555; display: flex; flex-direction: column; align-items: center; padding-top: 80px; padding-bottom: 40px; }
        
        /* Action Bar */
        .action-bar { position: fixed; top: 0; left: 0; width: 100%; background-color: #2b2b2b; padding: 15px 20px; display: flex; justify-content: center; gap: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.5); z-index: 1000; }
        .action-btn { padding: 10px 24px; font-size: 14px; font-weight: 600; border: none; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-family: 'Segoe UI', sans-serif; }
        .btn-print { background-color: #005A9E; color: white; }
        .btn-print:hover { background-color: #004578; }
        .btn-close { background-color: #e1dfdd; color: #333; }
        .btn-close:hover { background-color: #d2d0ce; }

        /* The actual receipt slip (Light Yellow for Purchases) */
        .receipt-container { background-color: #fffde7; width: 148mm; color: #111; padding: 15px 20px; position: relative; box-shadow: 0 4px 15px rgba(0,0,0,0.4); border: 1px solid #ccc; }

        .header-gods { display: flex; justify-content: space-between; font-size: 11px; font-weight: 600; color: #d32f2f; }
        .main-title { text-align: center; font-size: 30px; font-weight: 700; margin-top: 5px; letter-spacing: 1px; color: #d32f2f; }
        .subtitle-wrapper { text-align: center; margin-bottom: 5px; }
        .subtitle { display: inline-block; border: 1.5px solid #d32f2f; color: #d32f2f; border-radius: 20px; padding: 2px 25px; font-size: 17px; font-weight: 600; }

        .address-block { text-align: center; font-size: 14px; line-height: 1.4; font-weight: 600; margin-bottom: 10px; color: #d32f2f; }
        .phone-grid { display: grid; grid-template-columns: 1fr 1fr; text-align: center; font-size: 12px; font-weight: 600; margin-bottom: 12px; line-height: 1.4; color: #d32f2f; }
        
        .bill-meta { display: flex; justify-content: space-between; font-size: 14px; font-weight: 600; margin-bottom: 15px; border-bottom: 2px solid #333; padding-bottom: 10px;}
        .receipt-title { text-align: center; font-size: 22px; font-weight: 700; margin-bottom: 15px; text-decoration: underline; }

        .customer-info { font-size: 15px; font-weight: 600; line-height: 2.2; margin-bottom: 20px; }
        .info-line { display: flex; align-items: flex-end; }
        .info-label { width: 55px; }
        .info-value { flex: 1; border-bottom: 1px dashed #666; padding-left: 10px; color: #000; line-height: 1.2; padding-bottom: 2px; font-size: 16px; }

        /* Purchase Table */
        .item-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 2px solid #333; text-align: center; }
        .item-table th { background-color: #f5f5f5; border: 1px solid #333; padding: 8px; font-size: 14px; }
        .item-table td { border: 1px solid #333; padding: 10px; font-size: 16px; font-weight: 600; }
        
        /* Summary Box */
        .summary-box { width: 50%; float: right; border: 2px solid #333; border-radius: 4px; margin-bottom: 30px; font-size: 15px; font-weight: 600; background: #fff; }
        .summary-row { display: flex; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #ccc; }
        .summary-row:last-child { border-bottom: none; background-color: #e0f7fa; font-size: 17px; font-weight: 700; }
        .clearfix::after { content: ""; clear: both; display: table; }

        .footer-sig { font-size: 15px; font-weight: 600; line-height: 2.5; display: flex; justify-content: space-between; margin-top: 40px; }
        .sig-block { text-align: center; width: 150px; border-top: 1px solid #333; padding-top: 5px; }

        /* =========================================
           PRINT SPECIFIC CSS
           ========================================= */
        @media print {
            @page { 
                size: A5 portrait; 
                margin: 0; 
            }
            html, body { 
                background-color: #fffde7 !important; 
                width: 148mm; 
                height: 209mm; /* Strictly bound to A5 height */
                padding: 0; 
                margin: 0; 
                overflow: hidden; /* Instantly kills the blank second page */
                display: block; 
            }
            .action-bar { 
                display: none !important; 
            }
            .receipt-container { 
                box-shadow: none; 
                margin: 0; 
                width: 100%; 
                height: 100%; 
                padding: 12px 18px; /* Slightly tighter padding */
                border: none; 
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
                page-break-inside: avoid; 
            }
        }
    </style>
</head>
<body>

    <div class="action-bar no-print">
        <button class="action-btn btn-print" onclick="window.print()">🖨️ Print Purchase Receipt</button>
        <button class="action-btn btn-close" onclick="window.close()">❌ Close Window</button>
    </div>

    <div class="receipt-container">
        
        <div class="header-gods">
            <span>જય શ્રી શક્તિ માં</span>
            <span>જય શ્રી મહાકાલી માતાજી</span>
            <span>જય શ્રી અંબે માતાજી</span>
        </div>

        <div class="main-title">શક્તિ કૃપા કું.</div>
        <div class="subtitle-wrapper"><div class="subtitle">જે. બી. ઝાલા</div></div>
        <div class="address-block">સુરાશામળ, અલિન્દ્રા રોડ, તા. નડીયાદ.<br></div>

        <div class="phone-grid">
            <div>જે. બી. ઝાલા ૯૯૨૫૨ ૭૩૫૯૫</div>
            <div>જગદિશભાઈ ૯૯૦૯૧ ૫૧૩૨૧</div>
            <div>પ્રભાતસિંહ ૯૮૭૯૬ ૩૨૩૯૯</div>
            <div>ભરત ૯૯૭૯૭ ૨૭૯૯૯</div>
            <div>ભાવિન ૯૯૭૯૮ ૭૨૩૮૯</div>
            <div>પ્રિયંક ૯૯૦૪૩ ૪૮૦૧૧</div>
        </div>

        <div class="bill-meta">
            <div>ખરીદી નં. : <span style="color:#000; font-size:15px; font-weight:normal; font-family:sans-serif;"><?= htmlspecialchars($bill['purchase_no']) ?></span></div>
            <div>તા. : <span style="color:#000;"><?= toGujarati(date('d-m-Y', strtotime($bill['purchase_date']))) ?></span></div>
        </div>

        <div class="receipt-title">ખેતપેદાશ ખરીદી પહોંચ</div>

        <div class="customer-info">
            <div class="info-line">
                <div class="info-label">ખેડૂત :</div>
                <div class="info-value"><?= htmlspecialchars($bill['full_name']) ?></div>
            </div>
            <div class="info-line">
                <div class="info-label">ગામ :</div>
                <div class="info-value"><?= htmlspecialchars($bill['town_city']) ?></div>
            </div>
            <div class="info-line">
                <div class="info-label">મો. :</div>
                <div class="info-value"><?= toGujarati($bill['mobile_no']) ?></div>
            </div>
        </div>

        <?php
        // Fetch items specifically for this new architecture
        $stmtItems = $pdo->prepare("SELECT * FROM purchase_book_items WHERE purchase_id = ?");
        $stmtItems->execute([$purchase_id]);
        $items = $stmtItems->fetchAll();
        ?>
        <table class="item-table">
            <tr>
                <th>વિગત (Grain)</th>
                <th>બારદાન (Bags)</th>
                <th>વજન (Weight)</th>
                <th>ભાવ (Rate/Kg)</th>
                <th>રકમ (Amount)</th>
            </tr>
            <?php foreach($items as $item): 
                $guj_item_grain = $grain_map[$item['grain_type']] ?? $item['grain_type'];
            ?>
            <tr>
                <td><?= $guj_item_grain ?></td>
                <td><?= toGujarati($item['bardan_used']) ?></td>
                <td><?= toGujarati($item['weight']) ?> કિલો</td>
                <td>₹ <?= toGujarati($item['rate']) ?></td>
                <td>₹ <?= toGujarati($item['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="clearfix">
            <div class="summary-box">
                <div class="summary-row">
                    <span>કુલ રકમ:</span>
                    <span>₹ <?= toGujarati($bill['total_amount']) ?></span>
                </div>
                <div class="summary-row">
                    <span>કપાત:</span>
                    <span>- ₹ <?= toGujarati($bill['deductions']) ?></span>
                </div>
                <div class="summary-row">
                    <span>ચોખ્ખી રકમ:</span>
                    <span>₹ <?= toGujarati($bill['final_amount']) ?></span>
                </div>
            </div>
        </div>

        <div style="font-size: 14px; font-weight: 600; margin-bottom: 20px;">
            ચુકવણી વિગત: <span style="border: 1px solid #333; padding: 2px 8px; border-radius: 4px;"><?= $guj_status ?></span>
        </div>

        <div class="footer-sig">
            <div class="sig-block">ખેડૂતની સહી</div>
            <div class="sig-block">વેપારીની સહી</div>
        </div>

    </div>

</body>
</html>
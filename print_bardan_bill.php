<?php
// print_bardan_bill.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!isset($_GET['id'])) {
    die("<h2 style='text-align:center; padding:50px;'>Error: Bill ID not provided.</h2>");
}

$bill_id = (int)$_GET['id'];

// Fetch the bill and farmer details
$stmt = $pdo->prepare("
    SELECT s.*, f.full_name, f.town_city, f.mobile_no 
    FROM bardan_sell s 
    LEFT JOIN farmers f ON s.farmer_id = f.id 
    WHERE s.id = ?
");
$stmt->execute([$bill_id]);
$bill = $stmt->fetch();

if (!$bill) {
    die("<h2 style='text-align:center; padding:50px;'>Error: Bill not found.</h2>");
}

// Helper function to convert English numbers to Gujarati numbers
function toGujarati($num) {
    $eng = ['0','1','2','3','4','5','6','7','8','9'];
    $guj = ['૦','૧','૨','૩','૪','૫','૬','૭','૮','૯'];
    return str_replace($eng, $guj, (string)$num);
}

// Map the DB 'used_for' to the correct table column
$qty_mafri = ($bill['used_for'] === 'Mafri') ? toGujarati($bill['number_of_bardans']) : '';
$qty_dangar = ($bill['used_for'] === 'Dangar') ? toGujarati($bill['number_of_bardans']) : '';
$qty_ghau = ($bill['used_for'] === 'Ghau') ? toGujarati($bill['number_of_bardans']) : '';
$qty_bajri = ($bill['used_for'] === 'Bajri') ? toGujarati($bill['number_of_bardans']) : '';
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Bill - <?= htmlspecialchars($bill['yearly_bill_no']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Mukta+Vaani:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Mukta Vaani', sans-serif;
        }
        body {
            background-color: #555; 
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 80px; /* Space for the top action bar */
            padding-bottom: 40px;
        }
        
        /* Action Bar (Screen Only) */
        .action-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background-color: #2b2b2b;
            padding: 15px 20px;
            display: flex;
            justify-content: center;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .action-btn {
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .btn-print { background-color: #005A9E; color: white; }
        .btn-print:hover { background-color: #004578; }
        .btn-close { background-color: #e1dfdd; color: #333; }
        .btn-close:hover { background-color: #d2d0ce; }

        /* The actual receipt slip */
        .receipt-container {
            background-color: #ffb1c1; 
            width: 148mm; /* Standard A5 width approx */
            color: #a30015; 
            padding: 15px 20px;
            position: relative;
            box-shadow: 0 4px 15px rgba(0,0,0,0.4);
        }

        .header-gods { display: flex; justify-content: space-between; font-size: 11px; font-weight: 600; }
        .main-title { text-align: center; font-size: 30px; font-weight: 700; margin-top: 5px; letter-spacing: 1px; }
        
        .subtitle-wrapper { text-align: center; margin-bottom: 5px; }
        .subtitle { display: inline-block; border: 1.5px solid #a30015; border-radius: 20px; padding: 2px 25px; font-size: 17px; font-weight: 600; }

        .address-block { text-align: center; font-size: 14px; line-height: 1.4; font-weight: 600; margin-bottom: 10px; }
        .phone-grid { display: grid; grid-template-columns: 1fr 1fr; text-align: center; font-size: 12px; font-weight: 600; margin-bottom: 12px; line-height: 1.4; }
        .bill-meta { display: flex; justify-content: space-between; font-size: 15px; font-weight: 600; margin-bottom: 15px; }
        
        .receipt-title { text-align: center; font-size: 20px; font-weight: 700; margin-bottom: 15px; }

        .customer-info { font-size: 15px; font-weight: 600; line-height: 2.2; margin-bottom: 20px; }
        .info-line { display: flex; align-items: flex-end; }
        .info-label { width: 45px; }
        .info-value { flex: 1; border-bottom: 1px solid #a30015; padding-left: 10px; color: #000; line-height: 1.2; padding-bottom: 2px; }

        /* Custom Data Table */
        .item-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; border: 1.5px solid #a30015; text-align: center; }
        .item-table th, .item-table td { border: 1.5px solid #a30015; padding: 6px; font-size: 15px; font-weight: 600; }
        .item-table .main-header th { padding: 10px; }
        .user-input-qty { color: #000; font-size: 18px; height: 35px; }

        .footer-sig { font-size: 15px; font-weight: 600; line-height: 2.5; }
        .sig-line { display: flex; align-items: flex-end; margin-bottom: 15px; }
        .sig-label { width: 45px; }
        .sig-value { flex: 1; border-bottom: 1px solid #a30015; }
        .signature-img {
            max-height: 45px;
            margin-bottom: -8px; /* Pulls the image down to sit exactly on the line */
            margin-left: 10px;
        }

        /* =========================================
           PRINT SPECIFIC CSS
           ========================================= */
        @media print {
            @page {
                size: A5 portrait; 
                margin: 0; /* Remove physical margins completely */
            }
            html, body { 
                background-color: #ffb1c1 !important; /* Force the whole paper to be pink */
                height: 100%; /* Stretch to bottom of page */
                padding: 0; 
                margin: 0;
                display: block;
            }
            .action-bar { 
                display: none !important; 
            }
            .receipt-container { 
                box-shadow: none; 
                margin: 0 auto;
                width: 100%; 
                min-height: 100vh; /* Ensure content box stretches full height */
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
                padding: 15px 20px; /* Keep internal spacing */
            }
        }
    </style>
</head>
<body>

    <div class="action-bar no-print">
        <button class="action-btn btn-print" onclick="window.print()">🖨️ Print Receipt</button>
        <button class="action-btn btn-close" onclick="window.close()">❌ Close Window</button>
    </div>

    <div class="receipt-container">
        
        <div class="header-gods">
            <span>જય શ્રી શક્તિ માં</span>
            <span>જય શ્રી મહાકાલી માતાજી</span>
            <span>જય શ્રી અંબે માતાજી</span>
        </div>

        <div class="main-title">શક્તિ કૃપા કું.</div>
        
        <div class="subtitle-wrapper">
            <div class="subtitle">જે. બી. ઝાલા</div>
        </div>

        <div class="address-block">
            સુરાશામળ, અલિન્દ્રા રોડ, તા. નડીયાદ.<br>
        </div>

        <div class="phone-grid">
            <div>જે. બી. ઝાલા ૯૯૨૫૨ ૭૩૫૯૫</div>
            <div>જગદિશભાઈ ૯૯૦૯૧ ૫૧૩૨૧</div>
            <div>પ્રભાતસિંહ ૯૮૭૯૬ ૩૨૩૯૯</div>
            <div>ભરત ૯૯૭૯૭ ૨૭૯૯૯</div>
            <div>ભાવિન ૯૯૭૯૮ ૭૨૩૮૯</div>
            <div>પ્રિયંક ૯૯૦૪૩ ૪૮૦૧૧</div>
        </div>

        <div class="bill-meta">
            <div>નં. : <span style="color:#000; font-size:17px;"><?= toGujarati($bill['yearly_bill_no']) ?></span></div>
            <div>તા. : <span style="color:#000;"><?= toGujarati(date('d-m-Y', strtotime($bill['sell_date']))) ?></span></div>
        </div>

        <div class="receipt-title">બારદાન પહોંચ</div>

        <div class="customer-info">
            <div class="info-line">
                <div class="info-label">નામ :</div>
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

        <table class="item-table">
            <tr class="main-header">
                <th width="20%">નંગ</th>
                <th colspan="4">વિગત</th>
            </tr>
            <tr>
                <td></td> 
                <td width="20%">મગફળી</td>
                <td width="20%">ડાંગર</td>
                <td width="20%">ઘઉં</td>
                <td width="20%">બાજરી</td>
            </tr>
            <tr>
                <td class="user-input-qty"><?= toGujarati($bill['number_of_bardans']) ?></td>
                <td class="user-input-qty"><?= $qty_mafri ?></td>
                <td class="user-input-qty"><?= $qty_dangar ?></td>
                <td class="user-input-qty"><?= $qty_ghau ?></td>
                <td class="user-input-qty"><?= $qty_bajri ?></td>
            </tr>
        </table>

        <div class="footer-sig">
            <div class="sig-line">
                <div class="sig-label">સહી</div>
                <div class="sig-value">
                    <?php if (!empty($bill['signature_path']) && file_exists(__DIR__ . '/' . $bill['signature_path'])): ?>
                        <img src="<?= htmlspecialchars($bill['signature_path']) ?>" class="signature-img" alt="Signature">
                    <?php endif; ?>
                </div>
            </div>
            <div class="sig-line">
                <div class="sig-label">મો.નં.</div>
                <div class="sig-value"></div>
            </div>
        </div>

    </div>

</body>
</html>
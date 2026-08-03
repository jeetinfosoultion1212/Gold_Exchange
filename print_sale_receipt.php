<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata');
session_start();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$company_id = $_SESSION['company_id'] ?? 1;
$company_name = $_SESSION['company_name'] ?? 'Gold Trading Company';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/desktop_print_helper.php';

$silentPrint = ge_wants_silent_print();

$transaction_id = $_GET['id'] ?? null;
if (!$transaction_id) {
    die("No transaction ID provided");
}

// Fetch transaction details - Filter by Sale
$sql = "SELECT t.*, p.party_name 
        FROM transactions t 
        LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.id = ? AND t.company_id = ? AND t.transaction_type = 'Sale'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $transaction_id, $company_id);
$stmt->execute();
$result = $stmt->get_result();
$transaction = $result->fetch_assoc();

if (!$transaction) {
    die("Transaction not found");
}

$tid = (int) $transaction['id'];
$stock_q = $conn->prepare("SELECT GROUP_CONCAT(DISTINCT stock_name ORDER BY id SEPARATOR ', ') AS names FROM gold_sale_items WHERE transaction_id = ? AND company_id = ?");
$stock_q->bind_param("ii", $tid, $company_id);
$stock_q->execute();
$stock_row = $stock_q->get_result()->fetch_assoc();
$transaction['sale_stock_names'] = trim((string)($stock_row['names'] ?? ''));
if ($transaction['sale_stock_names'] === '') {
    $transaction['sale_stock_names'] = '—';
}

// Parse created_at with IST timezone
$created_dt = new DateTime($transaction['date_of_transaction'], new DateTimeZone('Asia/Kolkata'));
$date = $created_dt->format('d/m/Y');
$time = $created_dt->format('h:i A');

$paymentTypeLabel = ($transaction['payment_type'] ?? '') === 'Payment_In' ? 'Received' : 'Paid';

$h = static function ($str) {
    return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
};

// HTML print preview (browser only)
if (!$silentPrint && (!isset($_GET['format']) || $_GET['format'] !== 'pdf')) {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= $h($transaction['receipt_id']) ?></title>
    <style>
        @page { size: 80mm auto; margin: 4mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-weight: bold;
            background: #525659;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 16px;
        }
        .receipt {
            background: #fff;
            width: 72mm;
            max-width: 100%;
            padding: 6mm 4mm;
            color: #000;
            font-size: 11px;
            line-height: 1.35;
        }
        .center { text-align: center; }
        .divider { border-bottom: 1.5px dashed #000; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; margin: 3px 0; }
        .label { flex: 0 0 auto; }
        .value { text-align: right; flex: 1; }
        .amount-big { font-size: 13px; }
        .footer { text-align: center; border-top: 1.5px dashed #000; padding-top: 6px; margin-top: 8px; font-size: 9px; }
        @media print {
            body { background: #fff; padding: 0; display: block; }
            .receipt { width: 72mm; margin: 0 auto; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="center" style="font-size:15px; margin-bottom:2px;"><?= $h($company_name) ?></div>
        <div class="center" style="font-size:11px; margin-bottom:4px;">GOLD SALE RECEIPT</div>
        <div class="divider"></div>

        <div class="row"><span class="label">Receipt:</span><span class="value"><?= $h($transaction['receipt_id']) ?></span></div>
        <div class="row"><span class="label">Date:</span><span class="value"><?= $h($date . ' ' . $time) ?></span></div>
        <div class="row"><span class="label">Party:</span><span class="value"><?= $h($transaction['party_name']) ?></span></div>
        <div class="divider"></div>

        <div class="row"><span class="label">Weight:</span><span class="value"><?= number_format((float) $transaction['gold_weight'], 3) ?> g</span></div>
        <div class="row"><span class="label">Stock:</span><span class="value"><?= $h($transaction['sale_stock_names']) ?></span></div>
        <div class="row"><span class="label">Rate:</span><span class="value">Rs.<?= number_format((float) $transaction['rate'], 2) ?>/g</span></div>
        <div class="divider"></div>

        <div class="row"><span class="label">Total Amount:</span><span class="value amount-big">Rs.<?= number_format((float) $transaction['gold_amount'], 2) ?></span></div>
        <div class="row"><span class="label"><?= $h($paymentTypeLabel) ?>:</span><span class="value">Rs.<?= number_format((float) $transaction['payment_amount'], 2) ?></span></div>
        <div class="row"><span class="label">Pay Mode:</span><span class="value"><?= $h($transaction['payment_method']) ?></span></div>
        <div class="row"><span class="label">Status:</span><span class="value"><?= $h($transaction['payment_status']) ?></span></div>

        <?php if (!empty($transaction['narration'])): ?>
        <div class="divider"></div>
        <div style="font-size:10px; margin-bottom:2px;">Note:</div>
        <div><?= $h($transaction['narration']) ?></div>
        <?php endif; ?>

        <div class="footer">Thank you for your business!</div>
    </div>
    <script>
        window.addEventListener('load', function () {
        if (!window.GE_ELECTRON_APP) {
            setTimeout(function () { window.print(); }, 300);
        }
        });
    </script>
</body>
</html>
    <?php
    exit;
}

// Calculate page height based on content
$baseHeightMm = 110;
$remarksExtraMm = !empty($transaction['narration']) ? 10 : 0;
$pageHeightMm = $baseHeightMm + $remarksExtraMm;

$tcpdfPath = __DIR__ . '/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    die('TCPDF not found. Please install TCPDF library in the root directory.');
}
require_once $tcpdfPath;

// 79mm width thermal paper
$pdf = new TCPDF('P', 'mm', array(79, $pageHeightMm), true, 'UTF-8', false);
$pdf->SetCreator('Gold Exchange System');
$pdf->SetTitle('Receipt ' . $transaction['receipt_id']);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(1.5, 0.2, 1.5);
$pdf->SetAutoPageBreak(true, 0.2);
$pdf->SetFont('courier', '', 9);

function renderThermalReceipt($pdf, $company_name, $transaction, $date, $time) {
    $pdf->AddPage();
    
    $s = function($str) {
        return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
    };
    
    // CSS styles
    $style = '<style>
        table { border-collapse: collapse; width: 100%; }
        td, th { padding: 0.5mm; margin: 0; }
        .no-pad { padding: 0; margin: 0; line-height: 1; }
    </style>';
    
    $html = $style;
    
    // Header - Bolder fonts
    $html .= '<table style="margin-bottom: 0.2mm;">
                <tr><td style="text-align:center; font-size:13px; font-weight:bold; padding:0; margin:0; line-height:1.2;">' . $s($company_name) . '</td></tr>
                <tr><td style="text-align:center; font-size:12px; font-weight:bold; padding:0; margin:0; line-height:1.1;">GOLD SALE RECEIPT</td></tr>
                <tr><td style="border-bottom:1px solid #666; padding:0; margin:0.3mm 0 0 0;"></td></tr>
              </table>';
    
    // Receipt Grid - Bolder fonts
    $html .= '<table border="1" style="margin: 0.4mm 0; border: 1px solid #666;">
                <tr>
                    <td width="50%" style="text-align:center; font-size:7.5px; color:#666; font-weight:bold; padding:0.2mm; border-right:1px solid #ccc;">RECEIPT ID</td>
                    <td width="50%" style="text-align:center; font-size:7.5px; color:#666; font-weight:bold; padding:0.2mm;">DATE & TIME</td>
                </tr>
                <tr>
                    <td style="text-align:center; font-size:10px; font-weight:bold; padding:0.4mm; border-right:1px solid #ccc;">' . $s($transaction['receipt_id']) . '</td>
                    <td style="text-align:center; padding:0.4mm;">
                        <div style="font-size:9.5px; font-weight:bold; line-height:1.0; margin:0;">' . $date . '</div>
                        <div style="font-size:8px; font-weight:bold; color:#000; line-height:1.0; margin:0;">' . $time . '</div>
                    </td>
                </tr>
              </table>';
    
    // Party info - Bolder
    $html .= '<div style="font-size:7.5px; color:#666; font-weight:bold; margin:0.8mm 0 0.1mm 0;">CUSTOMER NAME</div>';
    $html .= '<div style="font-size:11px; font-weight:bold; margin:0 0 0.6mm 0;">' . $s($transaction['party_name']) . '</div>';
    $html .= '<div style="border-bottom:1px dashed #999; margin:0.2mm 0;"></div>';
    
    // Weight Details Table — Weight, Stock name(s), Rate (no purity)
    $html .= '<table style="font-size:9px; margin:0.6mm 0;">
                <tr>
                    <td width="50%" style="padding:0.3mm 0; color:#666; font-weight:bold;">Weight:</td>
                    <td width="50%" style="text-align:right; padding:0.3mm 0; font-weight:bold;">' . number_format((float)$transaction['gold_weight'], 3) . ' g</td>
                </tr>
                <tr>
                    <td style="padding:0.3mm 0; color:#666; font-weight:bold;">Stock:</td>
                    <td style="text-align:right; padding:0.3mm 0; font-weight:bold;">' . $s($transaction['sale_stock_names']) . '</td>
                </tr>
                <tr>
                    <td style="padding:0.3mm 0; color:#666; font-weight:bold;">Rate:</td>
                    <td style="text-align:right; padding:0.3mm 0; font-weight:bold;">Rs.' . number_format((float)$transaction['rate'], 2) . '/g</td>
                </tr>
              </table>';
    
    $html .= '<div style="border-bottom:1px dashed #999; margin:0.4mm 0;"></div>';
    
    // Amount Section - Bolder, Rs. instead of ₹
    $paymentTypeLabel = $transaction['payment_type'] === 'Payment_In' ? 'Received' : 'Paid';
    $amountColor = '#28a745'; 
    
    // For Sale: Amount is Total. Payment is what client paid.
    
    $html .= '<table style="font-size:10px; margin:0.6mm 0;">
                <tr>
                    <td width="50%" style="padding:0.4mm 0; font-weight:bold;">Total Amount:</td>
                    <td width="50%" style="text-align:right; padding:0.4mm 0; font-weight:bold; font-size:12px;">Rs.' . number_format((float)$transaction['gold_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td style="padding:0.4mm 0; color:#666; font-weight:bold;">' . $paymentTypeLabel . ':</td>
                    <td style="text-align:right; padding:0.4mm 0; font-weight:bold; color:' . $amountColor . ';">Rs.' . number_format((float)$transaction['payment_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td style="padding:0.4mm 0; color:#666; font-weight:bold;">Payment Method:</td>
                    <td style="text-align:right; padding:0.4mm 0; font-weight:bold;">' . $s($transaction['payment_method']) . '</td>
                </tr>
                <tr>
                    <td style="padding:0.4mm 0; color:#666; font-weight:bold;">Status:</td>
                    <td style="text-align:right; padding:0.4mm 0; font-weight:bold;">' . $s($transaction['payment_status']) . '</td>
                </tr>
              </table>';
    
    // Remarks - Bolder
    if (!empty($transaction['narration'])) {
        $html .= '<div style="border-top:1px dashed #999; margin:0.6mm 0;"></div>';
        $html .= '<table style="margin:0.4mm 0;">
                    <tr>
                        <td style="padding:0.2mm;">
                            <div style="font-size:7px; color:#666; font-weight:bold; margin:0; line-height:1;">REMARKS</div>
                            <div style="font-size:9px; font-weight:bold; margin:0; line-height:1.1;">' . $s($transaction['narration']) . '</div>
                        </td>
                    </tr>
                  </table>';
    }
    
    // Footer - Bolder
    $html .= '<div style="text-align:center; border-top:1px dashed #666; padding-top:0.4mm; margin-top:0.8mm;">
                <div style="font-size:8px; font-weight:bold; line-height:1.2;">Thank you for your business!</div>
              </div>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
}

// Generate single copy
renderThermalReceipt($pdf, $company_name, $transaction, $date, $time);

if (!$silentPrint) {
    $pdf->SetViewerPreferences(array('PrintScaling' => 'None'));
    $pdf->IncludeJS('print(true);');
}

while (ob_get_level()) {
    ob_end_clean();
}

if ($silentPrint) {
    ge_finish_silent_print(ge_silent_print_pdf_string($pdf->Output('', 'S')));
}

$pdf->Output('sale_receipt_' . $transaction['receipt_id'] . '.pdf', 'I');
exit;

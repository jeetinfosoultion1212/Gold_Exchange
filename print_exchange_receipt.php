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

// Fetch transaction details
$sql = "SELECT t.*, p.party_name 
        FROM transactions t 
        LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.id = ? AND t.company_id = ? AND t.transaction_type = 'Exchange'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $transaction_id, $company_id);
$stmt->execute();
$result = $stmt->get_result();
$transaction = $result->fetch_assoc();

if (!$transaction) {
    die("Transaction not found");
}

// Fetch exchange items for this transaction
$items_sql = "SELECT * FROM exchange_items WHERE transaction_id = ? AND item_type = 'received' ORDER BY id";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("i", $transaction_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

$received_items = [];
while ($item = $items_result->fetch_assoc()) {
    $received_items[] = $item;
}

// If no items found (old transaction), use aggregated data
if (empty($received_items) && !empty($transaction['received_weight'])) {
    $received_items[] = [
        'weight' => $transaction['received_weight'],
        'purity' => $transaction['purity'],
        'fine_weight' => $transaction['fine_weight']
    ];
}

// Parse created_at with IST timezone
$created_dt = new DateTime($transaction['date_of_transaction'], new DateTimeZone('Asia/Kolkata'));
$date = $created_dt->format('d/m/Y');
$time = $created_dt->format('h:i A');

$paymentTypeLabel = $transaction['payment_type'] === 'Payment_In' ? 'Received' : 'Paid';
$amountVal = (float)$transaction['amount'];
$paidVal = (float)$transaction['payment_amount'];
$balanceVal = max(0, (float)($transaction['due_amount'] ?? ($amountVal - $paidVal)));

$totalFine = 0;
foreach ($received_items as $item) {
    $totalFine += (float)$item['fine_weight'];
}

$h = static function ($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
};

// HTML print preview (browser only — desktop uses silent PDF print)
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
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 4px 0; }
        th, td { padding: 3px 2px; }
        th { border-bottom: 1.5px dashed #000; text-align: right; }
        th:first-child { text-align: center; }
        td:first-child { text-align: center; }
        td { text-align: right; }
        .total-row td { border-top: 1.5px dashed #000; padding-top: 5px; font-size: 11px; }
        .amount-big { font-size: 13px; }
        .section-title { font-size: 10px; margin: 4px 0 2px; }
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
        <div class="center" style="font-size:11px; margin-bottom:4px;">GOLD EXCHANGE RECEIPT</div>
        <div class="divider"></div>

        <div class="row"><span class="label">Receipt:</span><span class="value"><?= $h($transaction['receipt_id']) ?></span></div>
        <div class="row"><span class="label">Date:</span><span class="value"><?= $h($date . ' ' . $time) ?></span></div>
        <div class="row"><span class="label">Party:</span><span class="value"><?= $h($transaction['party_name']) ?></span></div>
        <div class="divider"></div>

        <div class="section-title">Received Items:</div>
        <table>
            <thead>
                <tr>
                    <th style="width:10%">#</th>
                    <th style="width:30%">Wt(g)</th>
                    <th style="width:25%">Pur%</th>
                    <th style="width:35%">Fine(g)</th>
                </tr>
            </thead>
            <tbody>
                <?php $itemNumber = 1; foreach ($received_items as $item): ?>
                <tr>
                    <td><?= $itemNumber++ ?></td>
                    <td><?= number_format((float)$item['weight'], 3) ?></td>
                    <td><?= number_format((float)$item['purity'], 2) ?></td>
                    <td><?= number_format((float)$item['fine_weight'], 3) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3">TOTAL FINE:</td>
                    <td><?= number_format($totalFine, 3) ?></td>
                </tr>
            </tbody>
        </table>
        <div class="divider"></div>

        <div class="row"><span class="label">Issue Weight:</span><span class="value"><?= number_format((float)$transaction['delivered_weight'], 3) ?> g</span></div>
        <div class="row"><span class="label">Difference:</span><span class="value"><?= number_format((float)$transaction['difference_weight'], 3) ?> g</span></div>
        <div class="row"><span class="label">Rate:</span><span class="value">Rs.<?= number_format((float)$transaction['rate'], 2) ?>/g</span></div>
        <div class="divider"></div>

        <div class="row"><span class="label">Amount:</span><span class="value amount-big">Rs.<?= number_format($amountVal, 2) ?></span></div>
        <div class="row"><span class="label"><?= $h($paymentTypeLabel) ?>:</span><span class="value">Rs.<?= number_format($paidVal, 2) ?></span></div>
        <div class="row"><span class="label">Balance:</span><span class="value">Rs.<?= number_format($balanceVal, 2) ?></span></div>
        <div class="row"><span class="label">Pay Mode:</span><span class="value"><?= $h($transaction['payment_method']) ?></span></div>
        <div class="row"><span class="label">Status:</span><span class="value"><?= $h($transaction['payment_status']) ?></span></div>

        <?php if (!empty($transaction['narration'])): ?>
        <div class="divider"></div>
        <div class="section-title">Note:</div>
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
$baseHeightMm = 125;
$itemsExtraMm = count($received_items) * 8; // Increased to 8mm per row for larger font
$remarksExtraMm = !empty($transaction['narration']) ? 15 : 0;
$pageHeightMm = $baseHeightMm + $itemsExtraMm + $remarksExtraMm;

$tcpdfPath = __DIR__ . '/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    die('TCPDF not found. Please install TCPDF library in the root directory.');
}
require_once $tcpdfPath;

// 79mm width thermal paper, dynamic height
$pdf = new TCPDF('P', 'mm', array(79, $pageHeightMm), true, 'UTF-8', false);
$pdf->SetCreator('Gold Exchange System');
$pdf->SetTitle('Receipt ' . $transaction['receipt_id']);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(1.5, 0.2, 1.5);
$pdf->SetAutoPageBreak(false); 
// Set Default Font to BOLD for better visibility
$pdf->SetFont('courier', 'B', 10);

function renderThermalReceipt($pdf, $company_name, $transaction, $received_items, $date, $time) {
    $pdf->AddPage();
    
    $s = function($str) {
        return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
    };
    
    // CSS styles - Increased font sizes and added font-weight:bold everywhere
    $style = '<style>
        table { border-collapse: collapse; width: 100%; font-weight: bold; }
        td, th { padding: 0.8mm 0.5mm; margin: 0; }
        .no-pad { padding: 0; margin: 0; line-height: 1; }
        .divider { border-bottom: 1.5px dashed #000; }
        .label { color: #000; font-weight: bold; }
        .value { color: #000; font-weight: bold; }
    </style>';
    
    $html = $style;
    
    // Header
    $html .= '<table style="margin-bottom: 0.2mm;">
                <tr><td style="text-align:center; font-size:14px; font-weight:bold; padding:0; margin:0; line-height:1.2;">' . $s($company_name) . '</td></tr>
                <tr><td style="text-align:center; font-size:11px; font-weight:bold; padding:0; margin:0; line-height:1.1;">GOLD EXCHANGE RECEIPT</td></tr>
                <tr><td class="divider" style="padding:0; margin:0.3mm 0 0 0;"></td></tr>
              </table>';
    
    // Receipt Info & Party
    $html .= '<table style="margin: 0.4mm 0; font-size:10px;">
                <tr>
                    <td width="30%" class="label">Receipt:</td>
                    <td width="70%" class="value" style="font-size:11px;">' . $s($transaction['receipt_id']) . '</td>
                </tr>
                <tr>
                    <td class="label">Date:</td>
                    <td class="value">' . $date . ' ' . $time . '</td>
                </tr>
                <tr>
                    <td class="label">Party:</td>
                    <td class="value" style="font-size:11px;">' . $s($transaction['party_name']) . '</td>
                </tr>
              </table>';
              
    $html .= '<div class="divider" style="margin:0.2mm 0;"></div>';
    
    // Received Items Table
    $html .= '<div style="font-size:10px; font-weight:bold; margin:0.6mm 0 0.2mm 0;">Received Items:</div>';
    $html .= '<table border="0" style="font-size:10px; margin:0.2mm 0;">
                <tr>
                    <th width="10%" style="border-bottom:1.5px dashed #000; text-align:center;">#</th>
                    <th width="30%" style="border-bottom:1.5px dashed #000; text-align:right;">Wt(g)</th>
                    <th width="25%" style="border-bottom:1.5px dashed #000; text-align:right;">Pur%</th>
                    <th width="35%" style="border-bottom:1.5px dashed #000; text-align:right;">Fine(g)</th>
                </tr>';
    
    // Add rows for each received item
    $itemNumber = 1;
    $totalFine = 0;
    foreach ($received_items as $item) {
        $fine = (float)$item['fine_weight'];
        $totalFine += $fine;
        
        $html .= '<tr>
                    <td style="text-align:center; padding:1mm 0.5mm;">' . $itemNumber . '</td>
                    <td style="text-align:right; padding:1mm 0.5mm;">' . number_format((float)$item['weight'], 3) . '</td>
                    <td style="text-align:right; padding:1mm 0.5mm;">' . number_format((float)$item['purity'], 2) . '</td>
                    <td style="text-align:right; padding:1mm 0.5mm;">' . number_format($fine, 3) . '</td>
                  </tr>';
        $itemNumber++;
    }
    
    // Total fine row
    $html .= '<tr>
                <td colspan="3" style="text-align:right; padding:1mm 0.5mm; border-top:1.5px dashed #000; font-size:11px;">TOTAL FINE:</td>
                <td style="text-align:right; padding:1mm 0.5mm; border-top:1.5px dashed #000; font-size:11px;">' . number_format($totalFine, 3) . '</td>
              </tr>';
    
    $html .= '</table>';
    
    $html .= '<div class="divider" style="margin:0.4mm 0;"></div>';
    
    // Issue & Difference
    $html .= '<table style="font-size:10px; margin:0.4mm 0;">
                <tr>
                    <td width="50%" class="label">Issue Weight:</td>
                    <td width="50%" style="text-align:right; font-weight:bold;">' . number_format((float)$transaction['delivered_weight'], 3) . ' g</td>
                </tr>
                <tr>
                    <td class="label">Difference:</td>
                    <td style="text-align:right; font-weight:bold;">' . number_format((float)$transaction['difference_weight'], 3) . ' g</td>
                </tr>
                <tr>
                    <td class="label">Rate:</td>
                    <td style="text-align:right; font-weight:bold;">Rs.' . number_format((float)$transaction['rate'], 2) . '/g</td>
                </tr>
              </table>';
    
    $html .= '<div class="divider" style="margin:0.4mm 0;"></div>';
    
    // Amount Section
    $paymentTypeLabel = $transaction['payment_type'] === 'Payment_In' ? 'Received' : 'Paid';
    $amountVal = (float)$transaction['amount'];
    $paidVal = (float)$transaction['payment_amount'];
    $balanceVal = max(0, (float)($transaction['due_amount'] ?? ($amountVal - $paidVal)));
    
    $html .= '<table style="font-size:11px; margin:0.6mm 0;">
                <tr>
                    <td width="50%" class="label">Amount:</td>
                    <td width="50%" style="text-align:right; font-weight:bold; font-size:13px;">Rs.' . number_format((float)$transaction['amount'], 2) . '</td>
                </tr>
                <tr>
                    <td class="label">' . $paymentTypeLabel . ':</td>
                    <td style="text-align:right; font-weight:bold;">Rs.' . number_format($paidVal, 2) . '</td>
                </tr>
                <tr>
                    <td class="label">Balance:</td>
                    <td style="text-align:right; font-weight:bold;">Rs.' . number_format($balanceVal, 2) . '</td>
                </tr>
                <tr>
                    <td class="label">Pay Mode:</td>
                    <td style="text-align:right; font-weight:bold;">' . $s($transaction['payment_method']) . '</td>
                </tr>
                <tr>
                    <td class="label">Status:</td>
                    <td style="text-align:right; font-weight:bold;">' . $s($transaction['payment_status']) . '</td>
                </tr>
              </table>';
    
    // Remarks
    if (!empty($transaction['narration'])) {
        $html .= '<div class="divider" style="margin:0.6mm 0;"></div>';
        $html .= '<div style="font-size:9px; font-weight:bold; margin-top:0.2mm;">Note:</div>';
        $html .= '<div style="font-size:10px; font-weight:bold; margin-bottom:0.2mm;">' . $s($transaction['narration']) . '</div>';
    }
    
    // Footer
    $html .= '<div style="text-align:center; border-top:1.5px dashed #000; padding-top:1mm; margin-top:1mm;">
                <div style="font-size:9px; font-weight:bold;">Thank you for your business!</div>
              </div>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
}

// Generate single copy
renderThermalReceipt($pdf, $company_name, $transaction, $received_items, $date, $time);

// Auto-trigger print dialog (browser PDF preview only)
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

$pdf->Output('exchange_receipt_' . $transaction['receipt_id'] . '.pdf', 'I');
exit;

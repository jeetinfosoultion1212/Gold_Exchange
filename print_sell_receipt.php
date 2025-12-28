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

$transaction_id = $_GET['id'] ?? null;
if (!$transaction_id) {
    die("No transaction ID provided");
}

// Fetch transaction details
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

// Parse created_at with IST timezone
$created_dt = new DateTime($transaction['date_of_transaction'], new DateTimeZone('Asia/Kolkata'));
$date = $created_dt->format('d/m/Y');
$time = $created_dt->format('h:i A');

// Calculate page height based on content
$baseHeightMm = 100;
$remarksExtraMm = !empty($transaction['narration']) ? 10 : 0;
$pageHeightMm = $baseHeightMm + $remarksExtraMm;

$tcpdfPath = __DIR__ . '/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    die('TCPDF not found. Please install TCPDF library in the root directory.');
}
require_once $tcpdfPath;

// 79mm width thermal paper
$pdf = new TCPDF('P', 'mm', array(79, $pageHeightMm), true, 'UTF-8', false);
$pdf->SetCreator('Gold Selling System');
$pdf->SetTitle('Sale Receipt ' . $transaction['receipt_id']);
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
    
    // Header
    $html .= '<table style="margin-bottom: 0.2mm;">
                <tr><td style="text-align:center; font-size:13px; font-weight:bold; padding:0; margin:0; line-height:1.2;">' . $s($company_name) . '</td></tr>
                <tr><td style="text-align:center; font-size:12px; font-weight:bold; padding:0; margin:0; line-height:1.1;">GOLD SALE RECEIPT</td></tr>
                <tr><td style="border-bottom:1px solid #666; padding:0; margin:0.3mm 0 0 0;"></td></tr>
              </table>';
    
    // Receipt Grid
    $html .= '<table border="1" style="margin: 0.4mm 0; border: 1px solid #666;">
                <tr>
                    <td width="50%" style="text-align:center; font-size:7.5px; color:#666; font-weight:bold; padding:0.2mm; border-right:1px solid #ccc;">SALE ID</td>
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
    
    // Customer Info
    $html .= '<table style="margin: 0.5mm 0;">
                <tr><td style="font-size:7.5px; color:#666; font-weight:bold; padding:0.2mm 0;">CUSTOMER</td></tr>
                <tr><td style="font-size:10px; font-weight:bold; padding:0.2mm 0;">' . strtoupper($s($transaction['party_name'])) . '</td></tr>
                <tr><td style="border-bottom:1px dashed #999; padding:0.3mm 0;"></td></tr>
              </table>';
    
    // Sale Details Table
    $html .= '<table border="1" style="margin: 0.5mm 0; border: 1px solid #666;">
                <tr>
                    <td colspan="2" style="text-align:center; font-size:8px; font-weight:bold; padding:0.3mm; background-color:#f0f0f0; border-bottom:1px solid #666;">SALE DETAILS</td>
                </tr>
                <tr>
                    <td width="45%" style="font-size:9px; font-weight:bold; padding:0.4mm; border-right:1px solid #ccc;">Weight:</td>
                    <td width="55%" style="font-size:10px; font-weight:bold; text-align:right; padding:0.4mm;">' . number_format($transaction['gold_weight'], 3) . ' g</td>
                </tr>
                <tr>
                    <td style="font-size:9px; font-weight:bold; padding:0.4mm; border-right:1px solid #ccc; border-top:1px solid #ccc;">Purity:</td>
                    <td style="font-size:10px; font-weight:bold; text-align:right; padding:0.4mm; border-top:1px solid #ccc;">' . number_format($transaction['purity'], 2) . '%</td>
                </tr>
                <tr>
                    <td style="font-size:9px; font-weight:bold; padding:0.4mm; border-right:1px solid #ccc; border-top:1px solid #ccc;">Rate:</td>
                    <td style="font-size:10px; font-weight:bold; text-align:right; padding:0.4mm; border-top:1px solid #ccc;">Rs. ' . number_format($transaction['rate'], 2) . '/g</td>
                </tr>
              </table>';
    
    // Total Amount
    $html .= '<table border="1" style="margin: 0.5mm 0; border: 2px solid #000;">
                <tr>
                    <td width="50%" style="font-size:10px; font-weight:bold; padding:0.5mm; border-right:2px solid #000;">TOTAL AMOUNT:</td>
                    <td width="50%" style="font-size:11px; font-weight:bold; text-align:right; padding:0.5mm;">Rs. ' . number_format($transaction['gold_amount'], 2) . '</td>
                </tr>
              </table>';
    
    // Payment Info
    $payment_amount = $transaction['payment_amount'] ?? 0;
    $payment_method = $transaction['payment_method'] ?? 'Cash';
    
    if ($payment_amount > 0) {
        $html .= '<table style="margin: 0.5mm 0;">
                    <tr><td style="border-bottom:1px dashed #999; padding:0.3mm 0;"></td></tr>
                    <tr><td style="font-size:7.5px; color:#666; font-weight:bold; padding:0.3mm 0;">PAYMENT</td></tr>
                  </table>';
        
        $html .= '<table border="1" style="margin: 0.3mm 0; border: 1px solid #666;">
                    <tr>
                        <td width="60%" style="font-size:9px; font-weight:bold; padding:0.4mm; border-right:1px solid #ccc;">Paid (' . $s($payment_method) . '):</td>
                        <td width="40%" style="font-size:10px; font-weight:bold; text-align:right; padding:0.4mm;">Rs. ' . number_format($payment_amount, 2) . '</td>
                    </tr>
                    <tr>
                        <td style="font-size:9px; font-weight:bold; padding:0.4mm; border-right:1px solid #ccc; border-top:1px solid #ccc;">Balance Due:</td>
                        <td style="font-size:10px; font-weight:bold; text-align:right; padding:0.4mm; border-top:1px solid #ccc;">Rs. ' . number_format($transaction['gold_amount'] - $payment_amount, 2) . '</td>
                    </tr>
                  </table>';
    }
    
    // Remarks
    if (!empty($transaction['narration'])) {
        $html .= '<table style="margin: 0.5mm 0;">
                    <tr><td style="border-bottom:1px dashed #999; padding:0.3mm 0;"></td></tr>
                    <tr><td style="font-size:7.5px; color:#666; font-weight:bold; padding:0.3mm 0;">REMARKS</td></tr>
                    <tr><td style="font-size:8.5px; padding:0.3mm 0;">' . $s($transaction['narration']) . '</td></tr>
                  </table>';
    }
    
    // Footer
    $html .= '<table style="margin: 0.5mm 0;">
                <tr><td style="border-bottom:1px dashed #999; padding:0.3mm 0;"></td></tr>
                <tr><td style="text-align:center; font-size:8px; padding:0.5mm 0;">Thank you for your business!</td></tr>
              </table>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
}

renderThermalReceipt($pdf, $company_name, $transaction, $date, $time);

$pdf->Output('Sale_Receipt_' . $transaction['receipt_id'] . '.pdf', 'I');
?>

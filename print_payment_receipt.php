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
$sql = "SELECT t.*, p.party_name, p.contact_no, p.address 
        FROM transactions t 
        LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.id = ? AND t.company_id = ? AND (t.transaction_type = 'Payment' OR t.transaction_type = 'Received')";
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
$baseHeightMm = 90; // Shorter for payment receipt
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
    
    // Header
    $html .= '<table style="margin-bottom: 0.2mm;">
                <tr><td style="text-align:center; font-size:13px; font-weight:bold; padding:0; margin:0; line-height:1.2;">' . $s($company_name) . '</td></tr>
                <tr><td style="text-align:center; font-size:12px; font-weight:bold; padding:0; margin:0; line-height:1.1;">PAYMENT RECEIPT</td></tr>
                <tr><td style="border-bottom:1px solid #666; padding:0; margin:0.3mm 0 0 0;"></td></tr>
              </table>';
    
    // Receipt Grid
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
    
    // Party info
    $html .= '<div style="font-size:7.5px; color:#666; font-weight:bold; margin:0.8mm 0 0.1mm 0;">RECEIVED FROM</div>';
    $html .= '<div style="font-size:11px; font-weight:bold; margin:0 0 0.6mm 0;">' . $s($transaction['party_name']) . '</div>';
    // if contact exists
    if (!empty($transaction['contact_no'])) {
        $html .= '<div style="font-size:8px; margin:0 0 0.4mm 0;">Ph: ' . $s($transaction['contact_no']) . '</div>';
    }
    
    $html .= '<div style="border-bottom:1px dashed #999; margin:0.2mm 0;"></div>';
    
    // Amount Section
    $amountColor = $transaction['payment_type'] === 'Payment_In' ? '#28a745' : '#dc3545';
    
    $html .= '<table style="font-size:11px; margin:1mm 0;">
                <tr style="background-color:#f0f0f0;">
                    <td width="50%" style="padding:1mm 0.5mm; font-weight:bold;">AMOUNT:</td>
                    <td width="50%" style="text-align:right; padding:1mm 0.5mm; font-weight:bold; font-size:14px; color:' . $amountColor . ';">Rs. ' . number_format((float)$transaction['payment_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td style="padding:0.4mm 0; color:#666; font-size:9px;">Mode:</td>
                    <td style="text-align:right; padding:0.4mm 0; font-weight:bold; font-size:10px;">' . $s($transaction['payment_method']) . '</td>
                </tr>
              </table>';
    
    $html .= '<div style="border-bottom:1px dashed #999; margin:0.4mm 0;"></div>';
     
    // Remarks
    if (!empty($transaction['narration'])) {
        $html .= '<table style="margin:0.4mm 0;">
                    <tr>
                        <td style="padding:0.2mm;">
                            <div style="font-size:7px; color:#666; font-weight:bold; margin:0; line-height:1;">REMARKS</div>
                            <div style="font-size:9px; font-weight:bold; margin:0; line-height:1.1;">' . $s($transaction['narration']) . '</div>
                        </td>
                    </tr>
                  </table>';
        $html .= '<div style="border-bottom:1px dashed #999; margin:0.4mm 0;"></div>';
    }
    
    // Footer
    $html .= '<div style="text-align:center; padding-top:1mm; margin-top:0.8mm;">
                <div style="font-size:8px; font-weight:bold; line-height:1.2;">Thank you!</div>
              </div>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
}

// Generate receipt
renderThermalReceipt($pdf, $company_name, $transaction, $date, $time);

// Auto-trigger print
$pdf->SetViewerPreferences(array('PrintScaling' => 'None'));
$pdf->IncludeJS('print(true);');

while (ob_get_level()) { 
    ob_end_clean(); 
}
$pdf->Output('payment_receipt_' . $transaction['receipt_id'] . '.pdf', 'I');
exit;

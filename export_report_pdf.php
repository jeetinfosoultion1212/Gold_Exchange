<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

// Load database configuration
require_once __DIR__ . '/config/database.php';

// Include TCPDF library
require_once(__DIR__ . '/tcpdf/tcpdf.php');

$company_id = $_SESSION['company_id'];
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Fetch Party-wise Summary
$sql = "SELECT 
            p.party_name,
            p.contact_no,
            SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END) as booking_weight,
            SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END) as sale_weight,
            SUM(CASE WHEN t.transaction_type = 'Purchase' THEN t.gold_weight ELSE 0 END) as purchase_weight,
            SUM(CASE WHEN t.transaction_type = 'Received' THEN t.gold_weight ELSE 0 END) as gold_received_weight,
            
            -- Payment In (Received from Party)
            SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND (t.payment_method = 'Cash' OR t.payment_method IS NULL OR t.payment_method = '') THEN t.payment_amount ELSE 0 END) as cash_in,
            SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_method IN ('Bank', 'UPI', 'Cheque') THEN t.payment_amount ELSE 0 END) as bank_in,
            
            -- Payment Out (Paid to Party)
            SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Purchase') AND t.payment_type = 'Payment_Out' AND (t.payment_method = 'Cash' OR t.payment_method IS NULL OR t.payment_method = '') THEN t.payment_amount ELSE 0 END) as cash_out,
            SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Purchase') AND t.payment_type = 'Payment_Out' AND t.payment_method IN ('Bank', 'UPI', 'Cheque') THEN t.payment_amount ELSE 0 END) as bank_out
            
        FROM parties p
        LEFT JOIN transactions t ON p.id = t.party_id 
            AND t.company_id = $company_id 
            AND DATE(t.date_of_transaction) BETWEEN '$start_date' AND '$end_date'
        WHERE p.company_id = $company_id
        GROUP BY p.id
        HAVING booking_weight > 0 OR sale_weight > 0 OR purchase_weight > 0 OR gold_received_weight > 0 OR cash_in > 0 OR bank_in > 0 OR cash_out > 0 OR bank_out > 0
        ORDER BY p.party_name";

$result = $conn->query($sql);
$reports = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
}

// Helper function to format Indian currency
function formatIndianCurrency($amount) {
    $amount = round($amount, 2);
    $exploded = explode('.', $amount);
    $integer = $exploded[0];
    $decimal = isset($exploded[1]) ? $exploded[1] : '00';
    
    $last_three = substr($integer, -3);
    $other_numbers = substr($integer, 0, -3);
    
    if ($other_numbers != '') {
        $last_three = ',' . $last_three;
    }
    
    $formatted = preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $other_numbers) . $last_three;
    
    return $formatted . '.' . str_pad($decimal, 2, '0', STR_PAD_RIGHT);
}

// Create new PDF document
$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false); // Landscape orientation

// Set document information
$pdf->setCreator('Mormukut System');
$pdf->setAuthor($_SESSION['company_name']);
$pdf->setTitle('Daily Transaction Report');
$pdf->setSubject('Daily Report');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->setMargins(10, 10, 10);
$pdf->setAutoPageBreak(TRUE, 15);

// Set font
$pdf->setFont('helvetica', '', 9);

// Add a page
$pdf->AddPage();

// Header
$pdf->setFont('helvetica', 'B', 18);
$pdf->Cell(0, 10, 'DAILY TRANSACTION REPORT', 0, 1, 'C');
$pdf->setFont('helvetica', '', 10);
$pdf->Cell(0, 6, 'Period: ' . date('d/m/Y', strtotime($start_date)) . ' to ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');
$pdf->Ln(5);

// Table Header
$pdf->setFont('helvetica', 'B', 9);
$pdf->SetFillColor(220, 220, 220);
$pdf->setLineWidth(0.3);

// Column widths
$w_party = 60;
$w_weight = 25;
$w_money = 30;

// Header Row 1
$pdf->Cell($w_party, 12, 'Party Name', 1, 0, 'C', true);
$pdf->Cell($w_weight, 12, 'Booking (g)', 1, 0, 'C', true);
$pdf->Cell($w_weight, 12, 'Sale (g)', 1, 0, 'C', true);
$pdf->Cell($w_weight, 12, 'Purchase (g)', 1, 0, 'C', true);
$pdf->Cell($w_weight, 12, 'Gold Rcv (g)', 1, 0, 'C', true);
$pdf->Cell($w_money * 2, 6, 'Payment Received (In)', 1, 0, 'C', true);
$pdf->Cell($w_money * 2, 6, 'Payment Paid (Out)', 1, 1, 'C', true);

// Header Row 2 (Sub-headers for Payment)
$pdf->SetXY(10 + $w_party + ($w_weight * 4), $pdf->GetY() - 6); // Move cursor back
$pdf->Cell($w_money * 2, 6, '', 0, 0, 'C', false); // Skip previous cells
$pdf->SetXY(10 + $w_party + ($w_weight * 4), $pdf->GetY() + 6); // Move to second row position

$pdf->Cell($w_money, 6, 'Cash', 1, 0, 'C', true);
$pdf->Cell($w_money, 6, 'Bank', 1, 0, 'C', true);
$pdf->Cell($w_money, 6, 'Cash', 1, 0, 'C', true);
$pdf->Cell($w_money, 6, 'Bank', 1, 1, 'C', true);

// Data Rows
$pdf->setFont('helvetica', '', 8);
$fill = false;

$total_booking = 0;
$total_sale = 0;
$total_purchase = 0;
$total_gold_rcv = 0;
$total_cash_in = 0;
$total_bank_in = 0;
$total_cash_out = 0;
$total_bank_out = 0;

foreach ($reports as $row) {
    $total_booking += $row['booking_weight'];
    $total_sale += $row['sale_weight'];
    $total_purchase += $row['purchase_weight'];
    $total_gold_rcv += $row['gold_received_weight'];
    $total_cash_in += $row['cash_in'];
    $total_bank_in += $row['bank_in'];
    $total_cash_out += $row['cash_out'];
    $total_bank_out += $row['bank_out'];

    $pdf->Cell($w_party, 8, substr($row['party_name'], 0, 35), 1, 0, 'L', $fill);
    $pdf->Cell($w_weight, 8, $row['booking_weight'] > 0 ? number_format($row['booking_weight'], 2) : '-', 1, 0, 'R', $fill);
    $pdf->Cell($w_weight, 8, $row['sale_weight'] > 0 ? number_format($row['sale_weight'], 2) : '-', 1, 0, 'R', $fill);
    $pdf->Cell($w_weight, 8, $row['purchase_weight'] > 0 ? number_format($row['purchase_weight'], 2) : '-', 1, 0, 'R', $fill);
    $pdf->Cell($w_weight, 8, $row['gold_received_weight'] > 0 ? number_format($row['gold_received_weight'], 2) : '-', 1, 0, 'R', $fill);
    
    $pdf->Cell($w_money, 8, $row['cash_in'] > 0 ? formatIndianCurrency($row['cash_in']) : '-', 1, 0, 'R', $fill);
    $pdf->Cell($w_money, 8, $row['bank_in'] > 0 ? formatIndianCurrency($row['bank_in']) : '-', 1, 0, 'R', $fill);
    $pdf->Cell($w_money, 8, $row['cash_out'] > 0 ? formatIndianCurrency($row['cash_out']) : '-', 1, 0, 'R', $fill);
    $pdf->Cell($w_money, 8, $row['bank_out'] > 0 ? formatIndianCurrency($row['bank_out']) : '-', 1, 1, 'R', $fill);
    
    $fill = !$fill;
}

// Total Row
$pdf->setFont('helvetica', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell($w_party, 8, 'TOTAL', 1, 0, 'C', true);
$pdf->Cell($w_weight, 8, number_format($total_booking, 2), 1, 0, 'R', true);
$pdf->Cell($w_weight, 8, number_format($total_sale, 2), 1, 0, 'R', true);
$pdf->Cell($w_weight, 8, number_format($total_purchase, 2), 1, 0, 'R', true);
$pdf->Cell($w_weight, 8, number_format($total_gold_rcv, 2), 1, 0, 'R', true);
$pdf->Cell($w_money, 8, formatIndianCurrency($total_cash_in), 1, 0, 'R', true);
$pdf->Cell($w_money, 8, formatIndianCurrency($total_bank_in), 1, 0, 'R', true);
$pdf->Cell($w_money, 8, formatIndianCurrency($total_cash_out), 1, 0, 'R', true);
$pdf->Cell($w_money, 8, formatIndianCurrency($total_bank_out), 1, 1, 'R', true);

// Footer
$pdf->SetY(-15);
$pdf->setFont('helvetica', 'I', 8);
$pdf->Cell(0, 10, 'Generated on ' . date('d/m/Y H:i:s'), 0, 0, 'C');

// Output PDF
$pdf->Output('Daily_Report_' . date('Y-m-d') . '.pdf', 'D');
exit;
?>

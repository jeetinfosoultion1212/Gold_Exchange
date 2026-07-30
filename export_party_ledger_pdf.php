<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

// Load database configuration
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/party_ledger_helper.php';

// Include TCPDF library
require_once(__DIR__ . '/tcpdf/tcpdf.php');

// Get party_id from request
$party_id = isset($_GET['party_id']) ? intval($_GET['party_id']) : 0;
$company_id = (int) $_SESSION['company_id'];

if ($party_id <= 0) {
    die('Invalid party ID');
}

try {
    $ledger = party_ledger_fetch_full($conn, $company_id, $party_id);
} catch (RuntimeException $e) {
    die($e->getMessage());
}

$party_data = $ledger['party'];
$summary = $ledger['summary'];

// PDF renders Tally-style, oldest first — API/screen order is newest first.
$display_transactions = array_reverse($ledger['transactions']);

$booked_weight = $summary['booked_weight'];
$booked_weight_cash = $summary['booked_weight_cash'];
$booked_weight_bank = $summary['booked_weight_bank'];
$sold_weight = $summary['sold_weight'];
$sold_weight_cash = $summary['sold_weight_cash'];
$sold_weight_bank = $summary['sold_weight_bank'];
$purchase_weight = $summary['purchased_weight'];
$purchase_weight_cash = $summary['purchased_weight_cash'];
$purchase_weight_bank = $summary['purchased_weight_bank'];
$cash_received = $summary['cash_received'];
$bank_received = $summary['bank_received'];
$total_received = $summary['total_received'];
$cash_balance = $summary['cash_balance'];
$bank_balance = $summary['bank_balance'];
$current_balance = $summary['current_balance'];

/** Thin alias so the rest of this file reads naturally; single source is party_ledger_format_currency(). */
function formatIndianCurrency($amount)
{
    return party_ledger_format_currency((float) $amount);
}

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->setCreator('Mormukut System');
$pdf->setAuthor($company_id);
$pdf->setTitle('Party Ledger Report - ' . $party_data['party_name']);
$pdf->setSubject('Party Ledger Report');

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

// Colors
$header_color = array(30, 64, 175); // Darker Blue
$header_gradient = array(59, 130, 246); // Lighter Blue
$light_gray = array(245, 247, 250);
$border_color = array(200, 200, 200);
$dark_text = array(0, 0, 0);
$light_text = array(100, 100, 100);
$summary_bg = array(240, 253, 244); // Light green for summary
$outstanding_bg = array(254, 252, 232); // Light yellow for outstanding

// Header Section with gradient effect
$pdf->setFillColor($header_color[0], $header_color[1], $header_color[2]);
$pdf->Rect(0, 0, 210, 28, 'F');

// Add a subtle line for depth
$pdf->setDrawColor($header_gradient[0], $header_gradient[1], $header_gradient[2]);
$pdf->setLineWidth(0.5);
$pdf->Line(0, 28, 210, 28);

$pdf->setTextColor(255, 255, 255);
$pdf->setFont('helvetica', 'B', 20);
$pdf->SetY(9);
$pdf->Cell(0, 10, 'PARTY LEDGER REPORT', 0, 1, 'C', false, '', 0, false, 'T', 'M');

$pdf->setFont('helvetica', '', 9);
$pdf->SetY(20);
$pdf->Cell(0, 5, 'Generated: ' . date('d/m/Y h:i A'), 0, 1, 'R', false, '', 0, false, 'T', 'M');

$pdf->SetY(33);

// Party Information Box - Compact and impressive design
$pdf->setFillColor($light_gray[0], $light_gray[1], $light_gray[2]);
$pdf->setDrawColor($border_color[0], $border_color[1], $border_color[2]);
$pdf->setLineWidth(0.5);
$pdf->Rect(10, 33, 190, 20, 'DF');

$pdf->setTextColor($dark_text[0], $dark_text[1], $dark_text[2]);
$pdf->setFont('helvetica', 'B', 14);
$pdf->SetXY(13, 36);
$pdf->Cell(100, 6, strtoupper($party_data['party_name']), 0, 0, 'L', false, '', 0, false, 'T', 'M');

// Party ID badge
$pdf->setFillColor($header_color[0], $header_color[1], $header_color[2]);
$pdf->setTextColor(255, 255, 255);
$pdf->setFont('helvetica', 'B', 9);
$pdf->SetXY(115, 35);
$pdf->Cell(25, 5, 'ID: ' . $party_data['id'], 1, 0, 'C', true);

// Contact and Address in compact format
$pdf->setTextColor($dark_text[0], $dark_text[1], $dark_text[2]);
$pdf->setFont('helvetica', '', 8);
$pdf->SetXY(13, 43);
$contact_info = ($party_data['contact_no'] ? 'Tel: ' . $party_data['contact_no'] : 'Tel: N/A');
$address_info = ($party_data['address'] ? 'Addr: ' . substr($party_data['address'], 0, 60) : 'Addr: N/A');
$pdf->Cell(95, 4, $contact_info, 0, 0, 'L');
$pdf->Cell(0, 4, $address_info, 0, 1, 'L');

$pdf->SetY(58);

// Summary Section with better styling
$pdf->setFont('helvetica', 'B', 12);
$pdf->Cell(0, 7, 'SUMMARY', 0, 1, 'L', false, '', 0, false, 'T', 'M');
$pdf->Ln(2);

// Summary Table Header (with 2 rows for better layout)
$pdf->setFillColor(220, 220, 220);
$pdf->setDrawColor($border_color[0], $border_color[1], $border_color[2]);
$pdf->setFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->setLineWidth(0.4);

// First row - main headers (spanning 2 columns each) - Increased widths for better spacing
$pdf->Cell(35, 6, 'Booked', 1, 0, 'C', true);
$pdf->Cell(35, 6, 'Sold', 1, 0, 'C', true);
$pdf->Cell(35, 6, 'Purchase', 1, 0, 'C', true);
$pdf->Cell(40, 6, 'Received', 1, 0, 'C', true);
$pdf->Cell(45, 6, 'Balance', 1, 1, 'C', true);

// Second row - Cash/Bank sub-headers
$pdf->setFont('helvetica', 'B', 7);
$pdf->Cell(17.5, 5, 'Cash', 1, 0, 'C', true);
$pdf->Cell(17.5, 5, 'Bank', 1, 0, 'C', true);
$pdf->Cell(17.5, 5, 'Cash', 1, 0, 'C', true);
$pdf->Cell(17.5, 5, 'Bank', 1, 0, 'C', true);
$pdf->Cell(17.5, 5, 'Cash', 1, 0, 'C', true);
$pdf->Cell(17.5, 5, 'Bank', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Cash', 1, 0, 'C', true);
$pdf->Cell(20, 5, 'Bank', 1, 0, 'C', true);
$pdf->Cell(22.5, 5, 'Cash', 1, 0, 'C', true);
$pdf->Cell(22.5, 5, 'Bank', 1, 1, 'C', true);

// Summary Table Data - First row (weights in grams and amounts)
$pdf->setFont('helvetica', '', 7.5);
$pdf->SetFillColor($summary_bg[0], $summary_bg[1], $summary_bg[2]);
$start_y = $pdf->GetY();
$pdf->Cell(17.5, 6, number_format($booked_weight_cash, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(17.5, 6, number_format($booked_weight_bank, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(17.5, 6, number_format($sold_weight_cash, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(17.5, 6, number_format($sold_weight_bank, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(17.5, 6, number_format($purchase_weight_cash, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(17.5, 6, number_format($purchase_weight_bank, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(20, 6, 'Rs ' . formatIndianCurrency($cash_received), 1, 0, 'C', true);
$pdf->Cell(20, 6, 'Rs ' . formatIndianCurrency($bank_received), 1, 0, 'C', true);
$pdf->Cell(22.5, 6, 'Rs ' . formatIndianCurrency($cash_balance), 1, 0, 'C', true);
$pdf->Cell(22.5, 6, 'Rs ' . formatIndianCurrency($bank_balance), 1, 1, 'C', true);

// Add total row below
$pdf->setFont('helvetica', 'B', 8);
$pdf->SetFillColor(230, 240, 250);
$pdf->Cell(35, 6, 'Total: ' . number_format($booked_weight, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(35, 6, 'Total: ' . number_format($sold_weight, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(35, 6, 'Total: ' . number_format($purchase_weight, 2) . 'g', 1, 0, 'C', true);
$pdf->Cell(40, 6, 'Total: Rs ' . formatIndianCurrency($total_received), 1, 0, 'C', true);
$pdf->Cell(45, 6, 'Total: Rs ' . formatIndianCurrency($current_balance), 1, 1, 'C', true);

$pdf->Ln(6);

// Transaction History Section
$pdf->setFont('helvetica', 'B', 12);
$pdf->Cell(0, 7, 'TRANSACTION HISTORY', 0, 1, 'L', false, '', 0, false, 'T', 'M');
$pdf->Ln(2);

// Transaction Table Header
$pdf->setFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->setLineWidth(0.4);
$pdf->Cell(22, 7, 'Date', 1, 0, 'C', true);
$pdf->Cell(18, 7, 'Type', 1, 0, 'C', true);
$pdf->Cell(22, 7, 'Receipt', 1, 0, 'C', true);
$pdf->Cell(22, 7, 'Weight', 1, 0, 'C', true);
$pdf->Cell(22, 7, 'Rate', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'Amount', 1, 0, 'C', true);
$pdf->Cell(28, 7, 'Payment', 1, 0, 'C', true);
$pdf->Cell(26, 7, 'Method', 1, 1, 'C', true);

// Transaction Table Data - Calculate running balance (Tally-style)
$pdf->setFont('helvetica', '', 7.5);
$fill = false;
$row_count = 0;
$running_balance = 0;

foreach ($display_transactions as $trans) {
    // Normalize transaction type for comparison
    $trans_type = strtoupper(trim($trans['transaction_type']));
    // Check if we need a new page
    if ($pdf->GetY() > 265) {
        $pdf->AddPage();
        // Redraw header
        $pdf->setFont('helvetica', 'B', 8);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->SetY(10);
        $pdf->setLineWidth(0.4);
        $pdf->Cell(22, 7, 'Date', 1, 0, 'C', true);
        $pdf->Cell(18, 7, 'Type', 1, 0, 'C', true);
        $pdf->Cell(22, 7, 'Receipt', 1, 0, 'C', true);
        $pdf->Cell(22, 7, 'Weight', 1, 0, 'C', true);
        $pdf->Cell(22, 7, 'Rate', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Amount', 1, 0, 'C', true);
        $pdf->Cell(28, 7, 'Payment', 1, 0, 'C', true);
        $pdf->Cell(26, 7, 'Method', 1, 1, 'C', true);
        $pdf->setFont('helvetica', '', 7.5);
        $fill = false;
    }
    
    // Alternate row colors
    if ($fill) {
        $pdf->SetFillColor(250, 250, 250);
    } else {
        $pdf->SetFillColor(255, 255, 255);
    }
    
    $date = date('d/m/Y', strtotime($trans['date_of_transaction']));
    // Display transaction type properly
    $type_display = $trans['transaction_type'];
    // Show full "Purchase" but truncate others if needed
    if ($trans_type == 'PURCHASE') {
        $type = 'Purchase';
    } elseif ($trans_type == 'EXCHANGE') {
        $type = 'Exchange';
    } else {
        $type = strlen($type_display) > 7 ? substr($type_display, 0, 7) : $type_display;
    }
    $receipt = $trans['receipt_id'] ? substr($trans['receipt_id'], 0, 10) : '-';
    if ($trans_type === 'EXCHANGE') {
        $recv = floatval($trans['received_weight'] ?? 0);
        $del = floatval($trans['delivered_weight'] ?? 0);
        $parts = [];
        if ($recv > 0) {
            $parts[] = 'R' . number_format($recv, 2) . 'g';
        }
        if ($del > 0) {
            $parts[] = 'I' . number_format($del, 2) . 'g';
        }
        $weight = !empty($parts) ? implode(' ', $parts) : '-';
    } else {
        $weight = $trans['gold_weight'] ? number_format($trans['gold_weight'], 2) . 'g' : '-';
    }
    
    // Show rate for Booking, Purchase, Sale, and Exchange transactions
    $rate = '-';
    if (in_array($trans_type, ['BOOKING', 'PURCHASE', 'SALE', 'EXCHANGE']) && isset($trans['rate']) && floatval($trans['rate']) > 0) {
        $rate = 'Rs ' . formatIndianCurrency($trans['rate']);
    }
    
    // For Booking transactions, don't show amount
    // For Purchase transactions, show gold_amount in amount column and payment_amount in payment column
    if ($trans_type == 'BOOKING') {
        $amount = '-';
        $payment = '-';
    } elseif ($trans_type == 'PURCHASE') {
        // Purchase: Show gold_amount in Amount column, payment_amount in Payment column
        $amount = $trans['gold_amount'] ? 'Rs ' . formatIndianCurrency($trans['gold_amount']) : '-';
        $payment_amount_val = floatval($trans['payment_amount'] ?? 0);
        $payment = ($payment_amount_val > 0) ? 'Rs ' . formatIndianCurrency($payment_amount_val) : '-';
    } elseif ($trans_type == 'EXCHANGE') {
        $ex_amt = party_ledger_exchange_amount($trans);
        $amount = $ex_amt > 0 ? 'Rs ' . formatIndianCurrency($ex_amt) : '-';
        $payment_amount_val = floatval($trans['payment_amount'] ?? 0);
        $payment = ($payment_amount_val > 0) ? 'Rs ' . formatIndianCurrency($payment_amount_val) : '-';
    } else {
        $amount = $trans['gold_amount'] ? 'Rs ' . formatIndianCurrency($trans['gold_amount']) : '-';
        $payment = ($trans['payment_amount'] && $trans['payment_amount'] > 0) ? 'Rs ' . formatIndianCurrency($trans['payment_amount']) : '-';
    }
    
    $method = $trans['payment_method'] ? substr($trans['payment_method'], 0, 8) : '-';
    
    // Running balance (Exchange uses due_amount; linked PAY rows excluded from list)
    $running_balance += party_ledger_transaction_balance_delta($trans);
    
    $pdf->Cell(22, 5.5, $date, 1, 0, 'L', $fill);
    $pdf->Cell(18, 5.5, $type, 1, 0, 'C', $fill);
    $pdf->Cell(22, 5.5, $receipt, 1, 0, 'L', $fill);
    $pdf->Cell(22, 5.5, $weight, 1, 0, 'R', $fill);
    $pdf->Cell(22, 5.5, $rate, 1, 0, 'R', $fill);
    $pdf->Cell(30, 5.5, $amount, 1, 0, 'R', $fill);
    $pdf->Cell(28, 5.5, $payment, 1, 0, 'R', $fill);
    $pdf->Cell(26, 5.5, $method, 1, 1, 'C', $fill);
    
    $fill = !$fill;
    $row_count++;
}

// Final Outstanding Balance Summary (Tally-style)
$pdf->Ln(3);
$current_y = $pdf->GetY();
$pdf->setLineWidth(0.6);
$pdf->Line(10, $current_y, 200, $current_y);
$pdf->Ln(3);

// Outstanding Balance Box with Cash/Bank breakdown
$box_y = $pdf->GetY();
$pdf->setFillColor($outstanding_bg[0], $outstanding_bg[1], $outstanding_bg[2]);
$pdf->setDrawColor(200, 180, 100);
$pdf->setLineWidth(0.5);
$pdf->Rect(10, $box_y, 190, 18, 'DF');

$pdf->setFont('helvetica', 'B', 11);
$pdf->SetXY(13, $box_y + 2);
$pdf->Cell(60, 6, 'OUTSTANDING BALANCE:', 0, 0, 'L', false, '', 0, false, 'T', 'M');

// Use live party balance (cash + bank); running balance is a cross-check only
$final_balance = $current_balance;
if (abs($final_balance) < 0.01 && abs($running_balance) >= 0.01) {
    $final_balance = $running_balance;
}

// Cash and Bank breakdown
$pdf->setFont('helvetica', '', 9);
$pdf->SetXY(13, $box_y + 8);
$pdf->setTextColor($dark_text[0], $dark_text[1], $dark_text[2]);
$pdf->Cell(40, 5, 'Cash: Rs ' . formatIndianCurrency($cash_balance), 0, 0, 'L');
$pdf->Cell(40, 5, 'Bank: Rs ' . formatIndianCurrency($bank_balance), 0, 0, 'L');

// Total balance on the right
$pdf->setFont('helvetica', 'B', 12);
$balance_text = 'Rs ' . formatIndianCurrency(abs($final_balance));
if ($final_balance > 0) {
    $pdf->setTextColor(200, 0, 0); // Red for due
    $balance_text .= ' (Due)';
} elseif ($final_balance < 0) {
    $pdf->setTextColor(0, 150, 0); // Green for credit
    $balance_text = 'Rs ' . formatIndianCurrency(abs($final_balance)) . ' (Credit)';
} else {
    $pdf->setTextColor(0, 0, 0);
    $balance_text .= ' (Clear)';
}
$pdf->SetXY(150, $box_y + 2);
$pdf->Cell(0, 6, $balance_text, 0, 1, 'R', false, '', 0, false, 'T', 'M');

// Reset text color
$pdf->setTextColor($dark_text[0], $dark_text[1], $dark_text[2]);

$pdf->SetY($box_y + 20);

// Footer on all pages
$total_pages = $pdf->getNumPages();
for ($i = 1; $i <= $total_pages; $i++) {
    $pdf->setPage($i);
    $pdf->SetY(-12);
    $pdf->setFont('helvetica', 'I', 7);
    $pdf->setTextColor($light_text[0], $light_text[1], $light_text[2]);
    $pdf->Cell(0, 5, 'Page ' . $i . ' of ' . $total_pages, 0, 0, 'C');
}

// Output PDF
$filename = 'Ledger_' . preg_replace('/[^A-Za-z0-9_]/', '_', $party_data['party_name']) . '_' . $party_id . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');

exit;
?>


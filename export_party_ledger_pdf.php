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

// Get party_id from request
$party_id = isset($_GET['party_id']) ? intval($_GET['party_id']) : 0;
$company_id = $_SESSION['company_id'];

if ($party_id <= 0) {
    die('Invalid party ID');
}

// Get party basic info
$party_sql = "SELECT * FROM parties WHERE id = $party_id AND company_id = $company_id";
$party_result = $conn->query($party_sql);
$party_data = $party_result->fetch_assoc();

if (!$party_data) {
    die('Party not found');
}

// Get all transactions for this party - order by date ascending for Tally-style ledger
$transactions_sql = "SELECT * FROM transactions 
                   WHERE party_id = $party_id AND company_id = $company_id 
                   ORDER BY date_of_transaction ASC, id ASC";
$transactions_result = $conn->query($transactions_sql);
$transactions = [];

while ($row = $transactions_result->fetch_assoc()) {
    $transactions[] = $row;
}

// Calculate summary with Cash/Bank breakdown
$booked_weight = 0;
$sold_weight = 0;
$purchase_weight = 0;
$booked_weight_cash = 0;
$sold_weight_cash = 0;
$purchase_weight_cash = 0;
$booked_weight_bank = 0;
$sold_weight_bank = 0;
$purchase_weight_bank = 0;
$booked_amount = 0;
$cash_received = 0;
$bank_received = 0;
$purchase_cash_paid = 0;
$purchase_bank_paid = 0;
$total_paid_out = 0;
$total_purchase_paid = 0; // Total amount paid for purchases

foreach ($transactions as $trans) {
    switch ($trans['transaction_type']) {
        case 'Booking':
            $booked_weight += $trans['gold_weight'];
            $booked_amount += $trans['gold_amount'];
            // Booking type breakdown
            if (isset($trans['booking_type']) && $trans['booking_type'] == 'Cash') {
                $booked_weight_cash += $trans['gold_weight'];
            } elseif (isset($trans['booking_type']) && $trans['booking_type'] == 'Bank') {
                $booked_weight_bank += $trans['gold_weight'];
            }
            break;
        case 'Sale':
            $sold_weight += $trans['gold_weight'];
            // Sale type breakdown
            if (isset($trans['booking_type']) && $trans['booking_type'] == 'Cash') {
                $sold_weight_cash += $trans['gold_weight'];
            } elseif (isset($trans['booking_type']) && $trans['booking_type'] == 'Bank') {
                $sold_weight_bank += $trans['gold_weight'];
            }
            // Don't count payment from Sale transactions - payments are recorded separately in Payment/Received transactions
            break;
        case 'Purchase':
            // Purchase: Company buys gold from party
            $purchase_weight += $trans['gold_weight'];
            // Purchase type breakdown (based on payment method)
            if (isset($trans['payment_method']) && strtolower($trans['payment_method']) == 'cash') {
                $purchase_weight_cash += $trans['gold_weight'];
                $purchase_cash_paid += $trans['payment_amount'];
            } else {
                $purchase_weight_bank += $trans['gold_weight'];
                $purchase_bank_paid += $trans['payment_amount'];
            }
            $total_purchase_paid += $trans['payment_amount'];
            break;
        case 'Payment':
            if ($trans['payment_type'] == 'Payment_In') {
                if ($trans['payment_amount'] > 0) {
                    // Count all Payment_In transactions regardless of method
                    if (strtolower($trans['payment_method']) == 'cash' || empty($trans['payment_method']) || $trans['payment_method'] == null) {
                        $cash_received += $trans['payment_amount'];
                    } else {
                        $bank_received += $trans['payment_amount'];
                    }
                }
            } else {
                // Payment_Out: Company pays party
                $total_paid_out += $trans['payment_amount'];
            }
            break;
        case 'Received':
            // Received transactions are payments received from party
            if ($trans['payment_amount'] > 0) {
                if (strtolower($trans['payment_method']) == 'cash' || empty($trans['payment_method']) || $trans['payment_method'] == null) {
                    $cash_received += $trans['payment_amount'];
                } else {
                    $bank_received += $trans['payment_amount'];
                }
            }
            break;
    }
}

$total_received = $cash_received + $bank_received;
$total_purchase_paid_amount = $purchase_cash_paid + $purchase_bank_paid;
$current_balance = floatval($party_data['current_balance'] ?? 0);
$cash_balance = floatval($party_data['cash_balance'] ?? 0);
$bank_balance = floatval($party_data['bank_balance'] ?? 0);

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

// Debug: Check if Purchase transactions exist
$has_purchases = false;
foreach ($transactions as $t) {
    if (strtoupper($t['transaction_type']) == 'PURCHASE') {
        $has_purchases = true;
        break;
    }
}

foreach ($transactions as $trans) {
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
    } else {
        $type = strlen($type_display) > 7 ? substr($type_display, 0, 7) : $type_display;
    }
    $receipt = $trans['receipt_id'] ? substr($trans['receipt_id'], 0, 10) : '-';
    $weight = $trans['gold_weight'] ? number_format($trans['gold_weight'], 2) . 'g' : '-';
    
    // Show rate for Booking, Purchase, and Sale transactions
    $rate = '-';
    if (in_array($trans_type, ['BOOKING', 'PURCHASE', 'SALE']) && isset($trans['rate']) && floatval($trans['rate']) > 0) {
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
    } else {
        $amount = $trans['gold_amount'] ? 'Rs ' . formatIndianCurrency($trans['gold_amount']) : '-';
        $payment = ($trans['payment_amount'] && $trans['payment_amount'] > 0) ? 'Rs ' . formatIndianCurrency($trans['payment_amount']) : '-';
    }
    
    $method = $trans['payment_method'] ? substr($trans['payment_method'], 0, 8) : '-';
    
    // Calculate running balance (Tally-style)
    // Outstanding Balance = What party owes company (positive = Due) OR What company owes party (negative = Credit)
    // Positive balance means party owes company money (Due)
    // Negative balance means company owes party money (Credit)
    if ($trans_type == 'BOOKING') {
        // Booking: Party owes company money (increases positive balance)
        $running_balance += floatval($trans['gold_amount'] ?? 0);
    } elseif ($trans_type == 'SALE') {
        // Sale: Company owes party money (reduces positive balance or increases negative)
        // First subtract the sale amount (company owes party)
        $running_balance -= floatval($trans['gold_amount'] ?? 0);
        // Then add any payment received from sale (party pays company, reduces what company owes)
        if (isset($trans['payment_amount']) && floatval($trans['payment_amount']) > 0) {
            $running_balance += floatval($trans['payment_amount']);
        }
    } elseif ($trans_type == 'PURCHASE') {
        // Purchase: Company buys gold from party
        // First, the gold amount increases what company owes party (decreases balance)
        $running_balance -= floatval($trans['gold_amount'] ?? 0);
        // Then, payment made reduces what company owes (increases balance)
        $purchase_payment = floatval($trans['payment_amount'] ?? 0);
        $running_balance += $purchase_payment;
    } elseif ($trans_type == 'PAYMENT') {
        if (isset($trans['payment_type']) && $trans['payment_type'] == 'Payment_In' && floatval($trans['payment_amount'] ?? 0) > 0) {
            // Payment received from party: Party pays company, reduces what party owes (decreases positive balance)
            $running_balance -= floatval($trans['payment_amount']);
        } else {
            // Payment paid out (Payment_Out): Company pays party, reduces what company owes party (increases balance/decreases negative)
            $running_balance += floatval($trans['payment_amount'] ?? 0);
        }
    } elseif ($trans_type == 'RECEIVED') {
        // Received: Party pays company (same as Payment_In)
        if (floatval($trans['payment_amount'] ?? 0) > 0) {
            $running_balance -= floatval($trans['payment_amount']);
        }
    }
    
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

// Use the current_balance from parties table as it's the most accurate
// But also verify with running balance calculation
$final_balance = $current_balance; // Use the stored balance from parties table
// If current_balance is 0 or not set, use running balance
if (abs($final_balance) < 0.01) {
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


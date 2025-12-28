<?php
require('fpdf/fpdf.php'); // Make sure to include FPDF library

class TransactionPDF extends FPDF {
    function Header() {
        // Logo
        $this->Image('logo.png', 10, 6, 30);
        // Arial bold 15
        $this->SetFont('Arial', 'B', 15);
        // Move to the right
        $this->Cell(80);
        // Title
        $this->Cell(30, 10, 'Transaction Report', 0, 0, 'C');
        // Line break
        $this->Ln(20);
    }

    function Footer() {
        // Position at 1.5 cm from bottom
        $this->SetY(-15);
        // Arial italic 8
        $this->SetFont('Arial', 'I', 8);
        // Page number
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function TransactionTable($header, $data) {
        // Colors, line width and bold font
        $this->SetFillColor(240, 240, 240);
        $this->SetTextColor(0);
        $this->SetDrawColor(190, 190, 190);
        $this->SetLineWidth(.3);
        $this->SetFont('', 'B');

        // Header
        $w = array(10, 30, 20, 25, 25, 25, 20, 30, 30, 25);
        for($i=0; $i<count($header); $i++)
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
        $this->Ln();

        // Color and font restoration
        $this->SetFillColor(250, 250, 250);
        $this->SetTextColor(0);
        $this->SetFont('');

        // Data
        $fill = false;
        foreach($data as $row) {
            $this->Cell($w[0], 6, $row[0], 'LR', 0, 'L', $fill);
            $this->Cell($w[1], 6, $row[1], 'LR', 0, 'L', $fill);
            $this->Cell($w[2], 6, $row[2], 'LR', 0, 'C', $fill);
            $this->Cell($w[3], 6, $row[3], 'LR', 0, 'R', $fill);
            $this->Cell($w[4], 6, $row[4], 'LR', 0, 'R', $fill);
            $this->Cell($w[5], 6, $row[5], 'LR', 0, 'R', $fill);
            $this->Cell($w[6], 6, $row[6], 'LR', 0, 'R', $fill);
            $this->Cell($w[7], 6, $row[7], 'LR', 0, 'R', $fill);
            $this->Cell($w[8], 6, $row[8], 'LR', 0, 'R', $fill);
            $this->Cell($w[9], 6, $row[9], 'LR', 0, 'C', $fill);
            $this->Ln();
            $fill = !$fill;
        }
        $this->Cell(array_sum($w), 0, '', 'T');
    }
}

// Create PDF when requested
if(isset($_GET['export'])) {
    // Database connection
    $conn = mysqli_connect('localhost', 'u176143338_jewellery_baza', 'Suniprosen2511@#', 'u176143338_jewellery_baza');
    
    $party_id = isset($_GET['party_id']) ? intval($_GET['party_id']) : 0;
    
    // Get party details
    $party_stmt = $conn->prepare("SELECT * FROM party_balances WHERE id = ?");
    $party_stmt->bind_param("i", $party_id);
    $party_stmt->execute();
    $party = $party_stmt->get_result()->fetch_assoc();

    // Get transactions
    $transactions_stmt = $conn->prepare("
        SELECT *,
            DATE(created_at) as transaction_date
        FROM Refine_transactions 
        WHERE party_id = ?
        ORDER BY created_at DESC
    ");
    $transactions_stmt->bind_param("i", $party_id);
    $transactions_stmt->execute();
    $transactions = $transactions_stmt->get_result();

    // Create PDF
    $pdf = new TransactionPDF();
    $pdf->AliasNbPages();
    $pdf->AddPage('L'); // Landscape orientation
    
    // Add party details
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Party: ' . $party['party_name'], 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Address: ' . $party['address'], 0, 1);
    $pdf->Cell(0, 6, 'Contact: ' . $party['contact_no'], 0, 1);
    $pdf->Ln(10);

    // Add transactions table
    $header = array('#', 'Date', 'Type', 'Received', 'Fine', 'Issue', 'Purity', 'Amount', 'Paid', 'Status');
    
    $data = array();
    $i = 1;
    while($row = mysqli_fetch_assoc($transactions)) {
        $data[] = array(
            $i++,
            date('d M Y', strtotime($row['transaction_date'])),
            $row['type'],
            number_format($row['received_weight'], 3) . 'g',
            number_format($row['fine_weight'], 3) . 'g',
            number_format($row['issue_weight'], 3) . 'g',
            number_format($row['purity'], 2) . '%',
            '₹' . number_format($row['amount']),
            '₹' . number_format($row['payment_amount']),
            $row['payment_status']
        );
    }

    $pdf->TransactionTable($header, $data);
    
    // Output PDF
    $pdf->Output('D', 'Transactions_' . $party['party_name'] . '.pdf');
    exit;
}
?>
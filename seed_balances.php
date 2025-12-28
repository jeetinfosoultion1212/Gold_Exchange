<?php
// seed_balances.php
require_once __DIR__ . '/config/database.php';

echo "Seeding account balances from transaction history...<br>";

// Get all companies
$companies = $conn->query("SELECT id FROM companies");

while($row = $companies->fetch_assoc()) {
    $company_id = $row['id'];
    echo "Processing Company ID: $company_id... ";
    
    // Calculate Cash Balance based on historical transactions
    // Logic from gold_exchange.php
    $cash_sql = "SELECT 
        COALESCE(SUM(CASE 
            WHEN transaction_type IN ('Payment', 'Received') 
            AND payment_type = 'Payment_In' 
            AND payment_method = 'Cash' 
            THEN payment_amount 
            ELSE 0 
        END), 0) - 
        COALESCE(SUM(CASE 
            WHEN transaction_type IN ('Payment', 'Purchase') 
            AND payment_type = 'Payment_Out' 
            AND payment_method = 'Cash' 
            THEN payment_amount 
            ELSE 0 
        END), 0) as cash_balance
    FROM transactions
    WHERE company_id = $company_id";
    
    $result = $conn->query($cash_sql);
    $calculated_cash = 0;
    if ($result && $r = $result->fetch_assoc()) {
        $calculated_cash = $r['cash_balance'];
    }
    
    echo "Calculated Cash: $calculated_cash. ";
    
    // Update account_balances
    // We treat this as the current balance. Opening balance assumes 0 + transactions.
    $update_sql = "UPDATE account_balances 
                   SET current_balance = ? 
                   WHERE company_id = ? AND account_type = 'Cash'";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("di", $calculated_cash, $company_id);
    
    if ($stmt->execute()) {
        echo "Updated successfully.<br>";
    } else {
        echo "Update failed: " . $stmt->error . "<br>";
    }
}

echo "Seeding complete.";
?>

<?php
// handlers/account_balance_helper.php

/**
 * Update account balance (Cash/Bank)
 * 
 * @param mysqli $conn Database connection
 * @param int $company_id Company ID
 * @param string $account_type 'Cash' or 'Bank'
 * @param float $amount Amount to add (positive) or subtract (negative)
 * @return bool Success status
 */
function updateAccountBalance($conn, $company_id, $account_type, $amount) {
    // Ensure the record exists first
    $check_sql = "SELECT id FROM account_balances WHERE company_id = ? AND account_type = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("is", $company_id, $account_type);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Create record if it doesn't exist
        $init_sql = "INSERT INTO account_balances (company_id, account_type, opening_balance, current_balance) VALUES (?, ?, 0, 0)";
        $init_stmt = $conn->prepare($init_sql);
        $init_stmt->bind_param("is", $company_id, $account_type);
        $init_stmt->execute();
    }
    
    // Update the balance
    $sql = "UPDATE account_balances 
            SET current_balance = current_balance + ? 
            WHERE company_id = ? AND account_type = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("dis", $amount, $company_id, $account_type);
    
    if (!$stmt->execute()) {
        error_log("Failed to update account balance: " . $stmt->error);
        return false;
    }
    return true;
}

/**
 * Get account balance
 * 
 * @param mysqli $conn Database connection
 * @param int $company_id Company ID
 * @param string $account_type 'Cash' or 'Bank'
 * @return float Current balance
 */
function getAccountBalance($conn, $company_id, $account_type) {
    $sql = "SELECT current_balance FROM account_balances 
            WHERE company_id = ? AND account_type = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $company_id, $account_type);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return (float)$row['current_balance'];
    }
    return 0.00;
}

/**
 * Initialize or Updating Opening Balances
 * Recalculates current balance based on new opening balance + existing transactions (if we tracked them differently)
 * For now, this simply sets the opening balance and adjusts current balance by the difference.
 * 
 * @param mysqli $conn Database connection
 * @param int $company_id Company ID
 * @param float $cash_opening Opening Cash Balance
 * @param float $bank_opening Opening Bank Balance
 * @return bool Success status
 */
function setOpeningBalances($conn, $company_id, $cash_opening, $bank_opening) {
    // We need to be careful. Changing opening balance should chang current balance by the diff.
    // Logic: New Current = Old Current + (New Opening - Old Opening)
    
    $conn->begin_transaction();
    try {
        // 1. Handle Cash
        $old_cash = 0;
        $check_cash = $conn->query("SELECT opening_balance, current_balance FROM account_balances WHERE company_id = $company_id AND account_type = 'Cash' FOR UPDATE");
        if ($check_cash->num_rows > 0) {
            $row = $check_cash->fetch_assoc();
            $old_cash = $row['opening_balance'];
            $diff = $cash_opening - $old_cash;
            
            $conn->query("UPDATE account_balances SET opening_balance = $cash_opening, current_balance = current_balance + $diff WHERE company_id = $company_id AND account_type = 'Cash'");
        } else {
            // New record
            $conn->query("INSERT INTO account_balances (company_id, account_type, opening_balance, current_balance) VALUES ($company_id, 'Cash', $cash_opening, $cash_opening)");
        }
        
        // 2. Handle Bank
        $old_bank = 0;
        $check_bank = $conn->query("SELECT opening_balance, current_balance FROM account_balances WHERE company_id = $company_id AND account_type = 'Bank' FOR UPDATE");
        if ($check_bank->num_rows > 0) {
            $row = $check_bank->fetch_assoc();
            $old_bank = $row['opening_balance'];
            $diff = $bank_opening - $old_bank;
            
            $conn->query("UPDATE account_balances SET opening_balance = $bank_opening, current_balance = current_balance + $diff WHERE company_id = $company_id AND account_type = 'Bank'");
        } else {
            // New record
            $conn->query("INSERT INTO account_balances (company_id, account_type, opening_balance, current_balance) VALUES ($company_id, 'Bank', $bank_opening, $bank_opening)");
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error setting opening balances: " . $e->getMessage());
        return false;
    }
}
?>

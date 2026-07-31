<?php
/**
 * Personal expense / loan-style movements: take or give cash or gold.
 * Take cash → company cash/bank up; party leg down.
 * Give cash → company down; party leg up.
 * Take gold → stock up; party gold_balance down (metal taken from party).
 * Give gold → stock down; party gold_balance up.
 * Take/give cash → company account_balances + party cash_balance or bank_balance.
 */
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/handlers/account_balance_helper.php';

if ($conn->connect_error) {
    die('Database connection failed.');
}

$company_id = (int) $_SESSION['company_id'];
$user_id = (int) $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];
$user_name = $_SESSION['full_name'];

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

function personal_expense_ensure_transaction_type(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $r = $conn->query("SHOW COLUMNS FROM `transactions` WHERE Field = 'transaction_type'");
    if (!$r || !($row = $r->fetch_assoc())) {
        return;
    }
    if (stripos($row['Type'], 'Personal_Expense') !== false) {
        return;
    }
    $sql = "ALTER TABLE `transactions` MODIFY `transaction_type` ENUM('Booking','Sale','Purchase','Received','Payment','Gold_Received','Exchange','Personal_Expense') NOT NULL";
    if (!$conn->query($sql)) {
        error_log('personal_expense: ALTER transaction_type failed: ' . $conn->error);
    }
}

function pe_is_cash_method(string $m): bool
{
    return strcasecmp(trim($m), 'Cash') === 0;
}

function pe_company_account_type(string $payment_method): string
{
    return pe_is_cash_method($payment_method) ? 'Cash' : 'Bank';
}

/** Undo ledger + stock + company effects of one saved Personal_Expense row (does not delete the transaction row). */
function pe_reverse_personal_expense_effects(mysqli $conn, int $company_id, array $t): void
{
    $party_id = (int) ($t['party_id'] ?? 0);
    $bk = (string) ($t['booking_type'] ?? '');
    $amt = floatval($t['payment_amount'] ?? 0);
    $meth = (string) ($t['payment_method'] ?? 'Cash');
    $gw = floatval($t['gold_weight'] ?? 0);
    $pu = floatval($t['purity'] ?? 0);
    $stock_name_del = '';
    if (!empty($t['narration']) && preg_match('/· stock:([^·]+)$/u', (string) $t['narration'], $m)) {
        $stock_name_del = trim($m[1]);
    }
    if ($stock_name_del === '' && $pu > 0) {
        $sn_q = $conn->query("SELECT stock_name FROM gold_stock WHERE company_id = $company_id AND purity = $pu ORDER BY id ASC LIMIT 1");
        if ($sn_q && $sn_r = $sn_q->fetch_assoc()) {
            $stock_name_del = (string) ($sn_r['stock_name'] ?? '');
        }
    }

    if ($bk === 'PE_Take_Cash') {
        $acct = pe_company_account_type($meth);
        if (!updateAccountBalance($conn, $company_id, $acct, -$amt)) {
            throw new Exception('Reverse company balance failed.');
        }
        if (pe_is_cash_method($meth)) {
            $conn->query("UPDATE parties SET cash_balance = cash_balance + $amt WHERE id = $party_id AND company_id = $company_id");
        } else {
            $conn->query("UPDATE parties SET bank_balance = bank_balance + $amt WHERE id = $party_id AND company_id = $company_id");
        }
    } elseif ($bk === 'PE_Give_Cash') {
        $acct = pe_company_account_type($meth);
        if (!updateAccountBalance($conn, $company_id, $acct, $amt)) {
            throw new Exception('Reverse company balance failed.');
        }
        if (pe_is_cash_method($meth)) {
            $conn->query("UPDATE parties SET cash_balance = cash_balance - $amt WHERE id = $party_id AND company_id = $company_id");
        } else {
            $conn->query("UPDATE parties SET bank_balance = bank_balance - $amt WHERE id = $party_id AND company_id = $company_id");
        }
    } elseif ($bk === 'PE_Take_Gold' && $gw > 0) {
        pe_adjust_stock($conn, $company_id, $stock_name_del, $pu, -$gw);
        $conn->query("UPDATE parties SET gold_balance = gold_balance + $gw WHERE id = $party_id AND company_id = $company_id");
    } elseif ($bk === 'PE_Give_Gold' && $gw > 0) {
        pe_adjust_stock($conn, $company_id, $stock_name_del, $pu, $gw);
        $conn->query("UPDATE parties SET gold_balance = gold_balance - $gw WHERE id = $party_id AND company_id = $company_id");
    }
}

function pe_adjust_stock(mysqli $conn, int $company_id, string $stock_name, float $purity, float $weight_delta): void
{
    $sn = $conn->real_escape_string($stock_name);
    $p = floatval($purity);
    $w = floatval($weight_delta);
    if (abs($w) < 0.000001) {
        return;
    }
    $where = $sn !== '' ? "stock_name = '$sn' AND purity = $p" : "purity = $p";
    $stock_check = $conn->query("SELECT id, current_stock FROM gold_stock WHERE $where AND company_id = $company_id LIMIT 1");
    if ($stock_check && $row = $stock_check->fetch_assoc()) {
        $sid = (int) $row['id'];
        $new = floatval($row['current_stock']) + $w;
        if ($new < -0.0001) {
            throw new Exception('Insufficient stock for this purity/stock row.');
        }
        if (!$conn->query("UPDATE gold_stock SET current_stock = current_stock + $w, last_updated = NOW() WHERE id = $sid")) {
            throw new Exception('Stock update failed: ' . $conn->error);
        }
        return;
    }
    if ($w < 0) {
        throw new Exception('No stock row found to deduct.');
    }
    if (!$conn->query("INSERT INTO gold_stock (company_id, category, mode, stock_name, purity, current_stock, last_updated) VALUES ($company_id, 'Gold', 'Cash', '$sn', $p, $w, NOW())")) {
        throw new Exception('Stock insert failed: ' . $conn->error);
    }
}

personal_expense_ensure_transaction_type($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_POST['action']) {
        case 'save_personal_expense':
            personal_expense_ensure_transaction_type($conn);
            $conn->begin_transaction();
            try {
                $edit_tid = (int) ($_POST['transaction_id'] ?? 0);
                if ($edit_tid > 0) {
                    $oldq = $conn->query("SELECT * FROM transactions WHERE id = $edit_tid AND company_id = $company_id AND transaction_type = 'Personal_Expense'");
                    if (!$oldq || !$oldq->num_rows) {
                        throw new Exception('Record not found.');
                    }
                    $old_row = $oldq->fetch_assoc();
                    pe_reverse_personal_expense_effects($conn, $company_id, $old_row);
                }

                $kind = trim((string) ($_POST['pe_kind'] ?? ''));
                $allowed_kind = ['take_cash', 'give_cash', 'take_gold', 'give_gold'];
                if (!in_array($kind, $allowed_kind, true)) {
                    throw new Exception('Invalid entry type.');
                }

                $party_id = (int) ($_POST['party_id'] ?? 0);
                if ($party_id <= 0) {
                    throw new Exception('Select a party.');
                }

                $party_chk = $conn->query("SELECT id, gold_balance FROM parties WHERE id = $party_id AND company_id = $company_id");
                if (!$party_chk || $party_chk->num_rows === 0) {
                    throw new Exception('Party not found.');
                }
                $party_row = $party_chk->fetch_assoc();
                $g_before = floatval($party_row['gold_balance'] ?? 0);

                $receipt_id = $conn->real_escape_string(trim((string) ($_POST['receipt_id'] ?? '')));
                if ($receipt_id === '') {
                    throw new Exception('Receipt ID required.');
                }

                $date_of_transaction = $conn->real_escape_string(trim((string) ($_POST['date_of_transaction'] ?? '')));
                if ($date_of_transaction === '') {
                    throw new Exception('Date required.');
                }

                $narration_in = trim((string) ($_POST['narration'] ?? ''));

                $booking_map = [
                    'take_cash' => 'PE_Take_Cash',
                    'give_cash' => 'PE_Give_Cash',
                    'take_gold' => 'PE_Take_Gold',
                    'give_gold' => 'PE_Give_Gold',
                ];
                $booking_esc = $conn->real_escape_string($booking_map[$kind]);

                $payment_method = $conn->real_escape_string(trim((string) ($_POST['payment_method'] ?? 'Cash')));
                $gold_weight = 0.0;
                $purity = 0.0;
                $rate = 0.0;
                $gold_amount = 0.0;
                $payment_amount = 0.0;
                $payment_type_sql = 'Payment_In';
                $stock_name_raw = trim((string) ($_POST['stock_name'] ?? ''));

                if ($kind === 'take_cash' || $kind === 'give_cash') {
                    $payment_amount = floatval($_POST['cash_amount'] ?? 0);
                    if ($payment_amount <= 0) {
                        throw new Exception('Enter a valid cash amount.');
                    }
                    if ($kind === 'give_cash') {
                        $payment_type_sql = 'Payment_Out';
                    }

                    $acct = pe_company_account_type((string) ($_POST['payment_method'] ?? 'Cash'));
                    if ($kind === 'take_cash') {
                        if (!updateAccountBalance($conn, $company_id, $acct, $payment_amount)) {
                            throw new Exception('Could not update company cash/bank.');
                        }
                        if (pe_is_cash_method((string) ($_POST['payment_method'] ?? ''))) {
                            if (!$conn->query("UPDATE parties SET cash_balance = cash_balance - $payment_amount WHERE id = $party_id AND company_id = $company_id")) {
                                throw new Exception('Party update failed.');
                            }
                        } else {
                            if (!$conn->query("UPDATE parties SET bank_balance = bank_balance - $payment_amount WHERE id = $party_id AND company_id = $company_id")) {
                                throw new Exception('Party update failed.');
                            }
                        }
                    } else {
                        if (!updateAccountBalance($conn, $company_id, $acct, -$payment_amount)) {
                            throw new Exception('Could not update company cash/bank.');
                        }
                        if (pe_is_cash_method((string) ($_POST['payment_method'] ?? ''))) {
                            if (!$conn->query("UPDATE parties SET cash_balance = cash_balance + $payment_amount WHERE id = $party_id AND company_id = $company_id")) {
                                throw new Exception('Party update failed.');
                            }
                        } else {
                            if (!$conn->query("UPDATE parties SET bank_balance = bank_balance + $payment_amount WHERE id = $party_id AND company_id = $company_id")) {
                                throw new Exception('Party update failed.');
                            }
                        }
                    }
                } else {
                    $gold_weight = floatval($_POST['gold_weight'] ?? 0);
                    $purity = floatval($_POST['purity'] ?? 0);
                    $rate = floatval($_POST['gold_rate'] ?? 0);
                    if ($gold_weight <= 0 || $purity <= 0) {
                        throw new Exception('Enter gold weight and purity.');
                    }
                    if ($rate < 0) {
                        $rate = 0;
                    }
                    $gold_amount = round($gold_weight * $rate, 2);

                    if ($kind === 'take_gold') {
                        pe_adjust_stock($conn, $company_id, $stock_name_raw, $purity, $gold_weight);
                        if (!$conn->query("UPDATE parties SET gold_balance = gold_balance - $gold_weight WHERE id = $party_id AND company_id = $company_id")) {
                            throw new Exception('Party gold update failed.');
                        }
                    } else {
                        pe_adjust_stock($conn, $company_id, $stock_name_raw, $purity, -$gold_weight);
                        if (!$conn->query("UPDATE parties SET gold_balance = gold_balance + $gold_weight WHERE id = $party_id AND company_id = $company_id")) {
                            throw new Exception('Party gold update failed.');
                        }
                    }
                }

                $g_after = $g_before;
                if ($kind === 'give_gold') {
                    $g_after = $g_before + $gold_weight;
                } elseif ($kind === 'take_gold') {
                    $g_after = $g_before - $gold_weight;
                }

                $nar = 'Personal expense · ' . $booking_map[$kind];
                if ($narration_in !== '') {
                    $nar .= ' — ' . $narration_in;
                }
                if ($stock_name_raw !== '' && ($kind === 'take_gold' || $kind === 'give_gold')) {
                    $nar .= ' · stock:' . $stock_name_raw;
                }
                $nar_esc = $conn->real_escape_string($nar);

                $pm_part = ($kind === 'take_cash' || $kind === 'give_cash') ? "'$payment_method'" : "'Cash'";
                $ptype_part = "'$payment_type_sql'";
                $gw = (float) $gold_weight;
                $pu = (float) $purity;
                $rt = (float) $rate;
                $ga = (float) $gold_amount;
                $pa_sql2 = (float) $payment_amount;

                if ($edit_tid > 0) {
                    $upd = "UPDATE transactions SET
                        user_id = $user_id, party_id = $party_id, receipt_id = '$receipt_id', date_of_transaction = '$date_of_transaction',
                        gold_weight = $gw, purity = $pu, rate = $rt, gold_amount = $ga, payment_amount = $pa_sql2,
                        payment_method = $pm_part, payment_type = $ptype_part,
                        party_gold_balance_before = $g_before, party_gold_balance_after = $g_after,
                        booking_type = '$booking_esc', narration = '$nar_esc', updated_at = NOW()
                        WHERE id = $edit_tid AND company_id = $company_id";
                    if (!$conn->query($upd)) {
                        throw new Exception('Update failed: ' . $conn->error);
                    }
                } else {
                    $ins = "INSERT INTO transactions (
                        company_id, user_id, party_id, receipt_id, transaction_type, date_of_transaction,
                        gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type,
                        party_gold_balance_before, party_gold_balance_after, booking_type, narration
                    ) VALUES (
                        $company_id, $user_id, $party_id, '$receipt_id', 'Personal_Expense', '$date_of_transaction',
                        $gw, $pu, $rt, $ga, $pa_sql2, $pm_part, $ptype_part,
                        $g_before, $g_after, '$booking_esc', '$nar_esc'
                    )";
                    if (!$conn->query($ins)) {
                        throw new Exception('Save failed: ' . $conn->error);
                    }
                }

                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => $edit_tid > 0 ? 'Updated successfully' : 'Saved successfully']);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        case 'delete_personal_expense':
            personal_expense_ensure_transaction_type($conn);
            $tid = (int) ($_POST['transaction_id'] ?? 0);
            if ($tid <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid id']);
                exit;
            }
            $conn->begin_transaction();
            try {
                $q = $conn->query("SELECT * FROM transactions WHERE id = $tid AND company_id = $company_id AND transaction_type = 'Personal_Expense'");
                if (!$q || $q->num_rows === 0) {
                    throw new Exception('Record not found.');
                }
                $t = $q->fetch_assoc();
                pe_reverse_personal_expense_effects($conn, $company_id, $t);

                if (!$conn->query("DELETE FROM transactions WHERE id = $tid AND company_id = $company_id")) {
                    throw new Exception('Delete failed.');
                }
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Deleted']);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        case 'get_party_info':
            $party_id = (int) ($_POST['party_id'] ?? 0);
            if ($party_id <= 0) {
                echo json_encode(['status' => 'ok', 'cash_balance' => 0, 'bank_balance' => 0, 'gold_balance' => 0, 'silver_balance' => 0, 'party_name' => '']);
                exit;
            }
            $st = $conn->prepare('SELECT party_name, cash_balance, bank_balance, gold_balance, silver_balance FROM parties WHERE id = ? AND company_id = ?');
            $st->bind_param('ii', $party_id, $company_id);
            $st->execute();
            $r = $st->get_result()->fetch_assoc();
            if (!$r) {
                echo json_encode(['status' => 'error', 'message' => 'Party not found']);
                exit;
            }
            echo json_encode([
                'status' => 'ok',
                'party_name' => $r['party_name'],
                'cash_balance' => (float) ($r['cash_balance'] ?? 0),
                'bank_balance' => (float) ($r['bank_balance'] ?? 0),
                'gold_balance' => (float) ($r['gold_balance'] ?? 0),
                'silver_balance' => (float) ($r['silver_balance'] ?? 0),
            ]);
            exit;

        case 'save_party':
            $party_name = trim((string) ($_POST['party_name'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $contact_no = trim((string) ($_POST['contact_no'] ?? ''));
            if ($party_name === '') {
                echo json_encode(['status' => 'error', 'message' => 'Name required']);
                exit;
            }
            $sql = 'INSERT INTO parties (company_id, party_name, address, contact_no) VALUES (?, ?, ?, ?)';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isss', $company_id, $party_name, $address, $contact_no);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'party_id' => $stmt->insert_id, 'message' => 'Party added']);
            } else {
                echo json_encode(['status' => 'error', 'message' => $stmt->error]);
            }
            exit;

        case 'get_pe_list':
            $list_sql = "SELECT t.id, t.receipt_id, t.date_of_transaction, t.booking_type, t.payment_amount, t.payment_method, t.gold_weight, t.purity,
                p.party_name FROM transactions t
                LEFT JOIN parties p ON t.party_id = p.id AND p.company_id = t.company_id
                WHERE t.company_id = $company_id AND t.transaction_type = 'Personal_Expense'
                ORDER BY t.date_of_transaction DESC, t.id DESC LIMIT 25";
            $lr = $conn->query($list_sql);
            $rows = [];
            if ($lr) {
                while ($row = $lr->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            echo json_encode(['status' => 'success', 'data' => $rows]);
            exit;

        case 'get_pe_details':
            $tid = (int) ($_POST['transaction_id'] ?? 0);
            if ($tid <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid id']);
                exit;
            }
            $dq = $conn->query("SELECT t.*, p.party_name FROM transactions t
                LEFT JOIN parties p ON t.party_id = p.id AND p.company_id = t.company_id
                WHERE t.id = $tid AND t.company_id = $company_id AND t.transaction_type = 'Personal_Expense'");
            if (!$dq || !$dq->num_rows) {
                echo json_encode(['status' => 'error', 'message' => 'Not found']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => $dq->fetch_assoc()]);
            exit;

        case 'search_parties':
            /** Same shape as payment_receipt.php (balances + ledger line in dropdown). */
            $term = $conn->real_escape_string(trim((string) ($_POST['term'] ?? '')));
            $sql = "SELECT DISTINCT p.id, p.party_name, p.address, p.contact_no,
                    p.cash_balance, p.bank_balance, p.gold_balance, p.silver_balance,
                    COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                    COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                    COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                    COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' AND t.booking_type = 'Cash' THEN t.gold_amount ELSE 0 END), 0) as cash_booked_amount,
                    COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_method = 'Cash' THEN t.payment_amount ELSE 0 END), 0) as cash_received,
                    COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' AND t.booking_type = 'Bank' THEN t.gold_amount ELSE 0 END), 0) as bank_booked_amount,
                    COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_method = 'Bank' THEN t.payment_amount ELSE 0 END), 0) as bank_received
                    FROM parties p
                    LEFT JOIN transactions t ON p.id = t.party_id AND t.company_id = $company_id
                    WHERE p.company_id = $company_id AND p.party_name LIKE '%$term%'
                    GROUP BY p.id, p.party_name, p.address, p.contact_no, p.cash_balance, p.bank_balance, p.gold_balance, p.silver_balance
                    ORDER BY p.party_name
                    LIMIT 15";
            $res = $conn->query($sql);
            $out = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $booked_weight = $row['booked_weight'];
                    $available_weight = max(0, $booked_weight - $row['sold_weight']);
                    $cash_due = max(0, $row['cash_booked_amount'] - $row['cash_received']);
                    $bank_due = max(0, $row['bank_booked_amount'] - $row['bank_received']);
                    $total_due = $cash_due + $bank_due;
                    $out[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'contact_no' => $row['contact_no'],
                        'booked_weight' => number_format($booked_weight, 2),
                        'sold_weight' => number_format($row['sold_weight'], 2),
                        'available_weight' => number_format($available_weight, 2),
                        'booked_amount' => number_format($row['booked_amount'], 2),
                        'total_due' => number_format($total_due, 2),
                        'cash_due' => number_format($cash_due, 2),
                        'bank_due' => number_format($bank_due, 2),
                        'cash_received' => number_format($row['cash_received'], 2),
                        'bank_received' => number_format($row['bank_received'], 2),
                        'total_received' => number_format($row['cash_received'] + $row['bank_received'], 2),
                        'current_balance' => floatval($row['cash_balance']) + floatval($row['bank_balance']),
                        'cash_balance' => floatval($row['cash_balance']),
                        'bank_balance' => floatval($row['bank_balance']),
                        'gold_balance' => floatval($row['gold_balance'] ?? 0),
                        'silver_balance' => floatval($row['silver_balance'] ?? 0),
                        'total_due_amount' => floatval($row['cash_balance']) + floatval($row['bank_balance']),
                        'total_due_gold' => floatval($row['gold_balance'] ?? 0),
                        'total_due_silver' => floatval($row['silver_balance'] ?? 0),
                    ];
                }
            }
            echo json_encode($out);
            exit;

        case 'generate_pe_receipt_id':
            $prefix = "PE{$company_id}";
            $last = $conn->query("SELECT receipt_id FROM transactions WHERE company_id = $company_id AND receipt_id LIKE '{$prefix}%' ORDER BY receipt_id DESC LIMIT 1");
            $serial = 1;
            if ($last && $lr = $last->fetch_assoc()) {
                $serial = (int) substr($lr['receipt_id'], strlen($prefix)) + 1;
            }
            $rid = $prefix . str_pad((string) $serial, 3, '0', STR_PAD_LEFT);
            echo json_encode(['status' => 'success', 'receipt_id' => $rid]);
            exit;
    }
}

/** Read-only fine vault row for stats (matches exchange “Fine Gold” / “Fine Silver” preference). */
function personal_expense_fine_stock_row(mysqli $conn, int $company_id, string $material): ?array
{
    $fineP = '(purity >= 99.50 OR purity = 100.00 OR purity = 100.0 OR purity = 100)';
    if (strcasecmp(trim($material), 'Silver') === 0) {
        $sql = "SELECT stock_name, purity, current_stock FROM gold_stock
            WHERE company_id = ? AND mode = 'Cash'
            AND {$fineP}
            AND (LOWER(stock_name) LIKE '%silver%')
            ORDER BY
                CASE WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1 ELSE 2 END,
                purity DESC, id ASC
            LIMIT 1";
    } else {
        $sql = "SELECT stock_name, purity, current_stock FROM gold_stock
            WHERE company_id = ? AND mode = 'Cash'
            AND {$fineP}
            AND NOT (LOWER(stock_name) LIKE '%silver%')
            ORDER BY
                CASE WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1 ELSE 2 END,
                purity DESC, id ASC
            LIMIT 1";
    }
    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $company_id);
    $st->execute();
    $res = $st->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        return $row;
    }
    return null;
}

function personal_expense_fine_stock_qty(mysqli $conn, int $company_id, string $material): float
{
    $row = personal_expense_fine_stock_row($conn, $company_id, $material);
    return $row ? (float) ($row['current_stock'] ?? 0) : 0.0;
}

$pe_stats = [
    'cash_in_hand' => 0.0,
    'bank_balance' => 0.0,
    'fine_gold_g' => 0.0,
    'fine_silver_g' => 0.0,
];
$bal_sql = "SELECT 
    COALESCE(SUM(CASE WHEN account_type = 'Cash' THEN current_balance ELSE 0 END), 0) AS cash_in_hand,
    COALESCE(SUM(CASE WHEN account_type = 'Bank' THEN current_balance ELSE 0 END), 0) AS bank_balance
FROM account_balances
WHERE company_id = $company_id";
$bal_res = $conn->query($bal_sql);
if ($bal_res && ($br = $bal_res->fetch_assoc())) {
    $pe_stats['cash_in_hand'] = (float) ($br['cash_in_hand'] ?? 0);
    $pe_stats['bank_balance'] = (float) ($br['bank_balance'] ?? 0);
}
$pe_stats['fine_gold_g'] = personal_expense_fine_stock_qty($conn, $company_id, 'Gold');
$pe_stats['fine_silver_g'] = personal_expense_fine_stock_qty($conn, $company_id, 'Silver');
$pe_default_fine_gold = personal_expense_fine_stock_row($conn, $company_id, 'Gold');

function pe_format_inr($amount, int $decimals = 0): string
{
    $amount = (float) $amount;
    $negative = $amount < 0;
    $amount = abs(round($amount, $decimals));
    $parts = explode('.', number_format($amount, $decimals, '.', ''));
    $integer = $parts[0];
    $decimalPart = $decimals > 0 ? ('.' . $parts[1]) : '';
    $lastThree = substr($integer, -3);
    $rest = substr($integer, 0, -3);
    if ($rest !== '') {
        $lastThree = ',' . $lastThree;
        $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
    }
    return ($negative ? '-' : '') . $rest . $lastThree . $decimalPart;
}

function pe_kind_short_label(string $bk): string
{
    $map = [
        'PE_Take_Cash' => 'Take cash',
        'PE_Give_Cash' => 'Give cash',
        'PE_Take_Gold' => 'Take gold',
        'PE_Give_Gold' => 'Give gold',
    ];
    return $map[$bk] ?? $bk;
}

function pe_kind_badge_class(string $bk): string
{
    if (strpos($bk, 'Take') !== false) {
        return 'bg-emerald-100 text-emerald-700';
    }
    if (strpos($bk, 'Give') !== false) {
        return 'bg-orange-100 text-orange-700';
    }
    return 'bg-slate-100 text-slate-700';
}

function pe_entry_detail_line(array $r): string
{
    $lbl = (string) ($r['booking_type'] ?? '');
    if (strpos($lbl, 'Cash') !== false) {
        return '₹' . pe_format_inr($r['payment_amount'] ?? 0, 2) . ' · ' . ($r['payment_method'] ?? '');
    }
    return number_format((float) ($r['gold_weight'] ?? 0), 3) . 'g @ '
        . number_format((float) ($r['purity'] ?? 0), 2) . '%';
}

$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$pe_list_limit = 100;

$recent_sql = "SELECT t.*, p.party_name FROM transactions t 
    LEFT JOIN parties p ON t.party_id = p.id AND p.company_id = t.company_id
    WHERE t.company_id = $company_id AND t.transaction_type = 'Personal_Expense'
    AND DATE(t.date_of_transaction) BETWEEN '$start_date' AND '$end_date'
    ORDER BY t.date_of_transaction DESC, t.id DESC LIMIT $pe_list_limit";
$recent_res = $conn->query($recent_sql);
$recent_list = ($recent_res && $recent_res->num_rows > 0) ? $recent_res->fetch_all(MYSQLI_ASSOC) : [];

$pe_count_sql = "SELECT COUNT(*) AS cnt FROM transactions t
    WHERE t.company_id = $company_id AND t.transaction_type = 'Personal_Expense'
    AND DATE(t.date_of_transaction) BETWEEN '$start_date' AND '$end_date'";
$pe_count_res = $conn->query($pe_count_sql);
$pe_total_entries = ($pe_count_res) ? (int) $pe_count_res->fetch_assoc()['cnt'] : 0;
$pe_list_has_more = $pe_total_entries > count($recent_list);

$stocks_sql = "SELECT DISTINCT stock_name, purity, current_stock FROM gold_stock WHERE company_id = $company_id ORDER BY purity DESC";
$stocks_res = $conn->query($stocks_sql);
$stock_rows = [];
if ($stocks_res) {
    while ($sr = $stocks_res->fetch_assoc()) {
        $stock_rows[] = $sr;
    }
}

$page_title = 'Personal expense';
?>

<style>
        .stats-card-label {
            font-size: 10px;
            font-weight: 500;
            letter-spacing: 0.02em;
            color: rgb(100 116 139);
        }
        .stats-card-value {
            font-size: 1rem;
            font-weight: 600;
            color: rgb(51 65 85);
            font-variant-numeric: tabular-nums;
        }
        .stats-icon-wrap {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .compact-input { padding-top: 0.375rem !important; padding-bottom: 0.375rem !important; font-size: 0.75rem !important; }
        .compact-label { font-size: 0.65rem !important; margin-bottom: 0.1rem !important; }
        #pePartyList, #peReceiptList { z-index: 50; }
        #pePartyList {
            position: absolute !important;
            top: 100% !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
        }
        .ge-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.4rem;
            height: 1.4rem;
            border-radius: 0.25rem;
            flex-shrink: 0;
            border: none;
            background: transparent;
            cursor: pointer;
            padding: 0;
        }
        .ge-action-btn i { font-size: 10px; line-height: 1; pointer-events: none; }
        .ge-action-btn:hover { background: rgba(148, 163, 184, 0.18); }
        .ge-txn-table .ge-action-col {
            width: 3.75rem;
            min-width: 3.75rem;
            padding-left: 0.2rem !important;
            padding-right: 0.3rem !important;
        }
        .ge-txn-scroll {
            max-height: calc(100vh - 300px);
            min-height: 220px;
            overflow-y: auto;
            overflow-x: auto;
        }
        .ge-txn-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: #f8fafc;
            box-shadow: 0 1px 0 #e2e8f0;
        }
        .ge-txn-table thead th { white-space: nowrap; }
        .ge-txn-table .ge-serial-col {
            width: 1.75rem;
            min-width: 1.75rem;
            padding-left: 0.35rem !important;
            padding-right: 0.25rem !important;
            text-align: center;
        }
        .ge-txn-table .ge-party-col {
            width: 5.75rem;
            max-width: 5.75rem;
            padding-left: 0.3rem !important;
            padding-right: 0.3rem !important;
        }
        .ge-txn-table td,
        .ge-txn-table th {
            padding-left: 0.35rem;
            padding-right: 0.35rem;
        }
        .ge-txn-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .ge-txn-scroll::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.5); border-radius: 3px; }
        .ge-txn-scroll::-webkit-scrollbar-track { background: transparent; }
        .pr-form-section { padding: 0.375rem 0.5rem; }
        .pr-form-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 0.375rem;
            align-items: end;
        }
        .pr-form-footer {
            padding: 0.375rem 0.5rem;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.25rem;
        }
        /* Inline validation — overlay below field, no layout shift */
        .validation-error {
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            z-index: 40;
            font-size: 9px;
            line-height: 1.15;
            color: #dc2626;
            margin-top: 1px;
            pointer-events: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .validation-error.hidden { display: none; }
        input.border-red-500,
        select.border-red-500,
        textarea.border-red-500 {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 1px #ef4444;
        }
    </style>

<div class="w-full min-w-0 px-1 pb-4">
    <div class="overflow-x-auto pb-1 -mx-0.5 px-0.5">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-3 min-w-0 w-full">
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Cash in hand</p>
                        <p class="stats-card-value leading-tight">₹<?= pe_format_inr($pe_stats['cash_in_hand']) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-emerald-100 shrink-0">
                        <i class="fas fa-wallet text-emerald-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Bank balance</p>
                        <p class="stats-card-value leading-tight">₹<?= pe_format_inr($pe_stats['bank_balance']) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-blue-100 shrink-0">
                        <i class="fas fa-university text-blue-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Fine stock gold</p>
                        <p class="stats-card-value leading-tight"><?= number_format($pe_stats['fine_gold_g'], 3) ?> g</p>
                    </div>
                    <div class="stats-icon-wrap bg-amber-100 shrink-0">
                        <i class="fas fa-coins text-amber-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Fine stock silver</p>
                        <p class="stats-card-value leading-tight"><?= number_format($pe_stats['fine_silver_g'], 3) ?> g</p>
                    </div>
                    <div class="stats-icon-wrap bg-slate-100 shrink-0">
                        <i class="fas fa-gem text-slate-600 text-xs"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-col lg:flex-row gap-4 min-w-0 w-full lg:items-start">
        <div id="peFormCard" class="bg-white rounded-lg shadow-md border border-gray-200 min-w-0 lg:flex-[1_1_55%] overflow-hidden self-start w-full">
            <form id="peForm" class="overflow-hidden" onsubmit="return false;">
                <input type="hidden" name="transaction_id" id="peEditTransactionId" value="">

                <div class="bg-blue-50 px-3 py-1 border-b border-blue-100">
                    <h3 class="text-xs font-bold text-blue-800 flex items-center">
                        <i class="fas fa-file-invoice mr-1.5 text-xs"></i> Transaction Details
                        <span id="peEditModeIndicator" class="ml-2 text-orange-600 hidden text-[10px]">(Edit)</span>
                    </h3>
                </div>
                <div class="pr-form-section pr-form-grid">
                    <div class="relative col-span-12 sm:col-span-4 lg:col-span-3">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Receipt</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500"><i class="fas fa-hashtag text-xs"></i></span>
                            <input type="text" name="receipt_id" id="peReceiptId" readonly tabindex="0"
                                class="block w-full pl-7 pr-8 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input cursor-pointer">
                            <button type="button" id="showPeListBtn" class="absolute right-1 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600 p-0.5" title="Recent entries"><i class="fas fa-history text-xs"></i></button>
                        </div>
                        <div id="peReceiptList" class="hidden absolute z-[60] mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-72 overflow-y-auto w-[min(100%,20rem)] left-0 text-[9px] leading-tight"></div>
                    </div>
                    <div class="relative col-span-12 sm:col-span-4 lg:col-span-3">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Date</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500"><i class="fas fa-calendar-alt text-xs"></i></span>
                            <input type="datetime-local" name="date_of_transaction" id="peDate" required
                                class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input">
                        </div>
                    </div>
                    <div class="relative col-span-12 sm:col-span-4 lg:col-span-6">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 flex items-center justify-between uppercase tracking-tighter compact-label">
                            <span>Party</span>
                            <button type="button" id="peAddPartyBtn" class="text-blue-600 hover:text-blue-800 font-bold transition-all text-[9px] flex items-center uppercase tracking-tighter">
                                <i class="fas fa-plus-circle mr-1 text-[10px]"></i> Add New
                            </button>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-blue-500"><i class="fas fa-user text-xs"></i></span>
                            <input type="text" id="pePartyName" autocomplete="off" spellcheck="false" placeholder="Search party" required
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input">
                        </div>
                        <input type="hidden" name="party_id" id="pePartyId">
                        <div id="pePartyList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
                    </div>
                </div>

                <div id="pePartyInfoSection" class="hidden px-2 pb-1">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 text-xs" id="pePartyInfoAlert"></div>
                </div>

                <div class="bg-emerald-50 px-3 py-1 border-t border-b border-emerald-100">
                    <h3 class="text-xs font-bold text-emerald-800 flex items-center">
                        <i class="fas fa-hand-holding-dollar mr-1.5 text-xs"></i> Entry Details
                    </h3>
                </div>
                <div class="pr-form-section pr-form-grid">
                    <div class="relative col-span-12 sm:col-span-4">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Type</label>
                        <select name="pe_kind" id="peKind"
                            class="block w-full px-2 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 focus:border-gray-400 compact-input">
                            <option value="take_cash">Take cash (into shop)</option>
                            <option value="give_cash">Give cash (from shop)</option>
                            <option value="take_gold">Take gold (into stock, party gold −)</option>
                            <option value="give_gold">Give gold (stock −, party gold +)</option>
                        </select>
                    </div>
                    <div id="peCashBlock" class="col-span-12 sm:col-span-8 grid grid-cols-12 gap-1.5 items-end">
                        <div class="relative col-span-12 sm:col-span-6">
                            <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Amount (₹)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-indigo-500"><i class="fas fa-wallet text-xs"></i></span>
                                <input type="number" step="0.01" name="cash_amount" id="peCashAmt" placeholder="0.00"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-indigo-400 focus:border-indigo-400 compact-input">
                            </div>
                        </div>
                        <div class="relative col-span-12 sm:col-span-6">
                            <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Method</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-600"><i class="fas fa-credit-card text-xs"></i></span>
                                <select name="payment_method" id="pePayMeth"
                                    class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 focus:border-gray-400 compact-input">
                                    <option value="Cash">Cash</option>
                                    <option value="Bank">Bank</option>
                                    <option value="UPI">UPI</option>
                                    <option value="Cheque">Cheque</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="peGoldBlock" class="col-span-12 sm:col-span-8 hidden grid grid-cols-12 gap-1.5 items-end">
                        <div class="relative col-span-6 sm:col-span-3">
                            <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Stock</label>
                            <select name="stock_name" id="peStockName" title="Fine gold for give gold; match by purity if empty"
                                class="block w-full px-1 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 focus:border-gray-400 compact-input truncate">
                                <option value="">— Purity —</option>
                                <?php foreach ($stock_rows as $sr):
                                    $sn = (string) ($sr['stock_name'] ?? '');
                                    $isDefaultFine = $pe_default_fine_gold && $sn === (string) ($pe_default_fine_gold['stock_name'] ?? '');
                                ?>
                                <option value="<?= htmlspecialchars($sn) ?>" data-purity="<?= htmlspecialchars((string)$sr['purity']) ?>"<?= $isDefaultFine ? ' data-fine-gold="1"' : '' ?>>
                                    <?= htmlspecialchars($sn ?: 'Stock') ?> · <?= htmlspecialchars((string)$sr['purity']) ?>%
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="relative col-span-6 sm:col-span-3">
                            <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Wt (g)</label>
                            <input type="number" step="0.001" name="gold_weight" id="peGoldW" title="Weight (g)"
                                class="block w-full px-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-amber-400 focus:border-amber-400 compact-input">
                        </div>
                        <div class="relative col-span-6 sm:col-span-3">
                            <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Purity %</label>
                            <input type="number" step="0.01" name="purity" id="pePurity"
                                class="block w-full px-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-amber-400 focus:border-amber-400 compact-input">
                        </div>
                        <div class="relative col-span-6 sm:col-span-3">
                            <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Rate</label>
                            <input type="number" step="0.01" name="gold_rate" id="peGoldRate" value="0" title="₹/g"
                                class="block w-full px-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-amber-400 focus:border-amber-400 compact-input">
                        </div>
                    </div>
                    <div class="relative col-span-12">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Notes</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500"><i class="fas fa-comment-alt text-xs"></i></span>
                            <input type="text" name="narration" placeholder="Optional"
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input">
                        </div>
                    </div>
                </div>

                <div class="pr-form-footer">
                    <button type="submit" id="peSubmit"
                        class="min-w-[7rem] bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-4 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter">
                        <i class="fas fa-save mr-1"></i><span>Save</span>
                    </button>
                    <button type="button" id="peDeleteEditedBtn"
                        class="hidden px-2.5 py-1.5 bg-gradient-to-r from-red-600 to-red-700 text-white text-[10px] font-bold rounded hover:from-red-700 hover:to-red-800 shadow-sm"
                        title="Delete"><i class="fas fa-trash-alt"></i></button>
                    <button type="button" id="peReset"
                        class="px-2.5 py-1.5 bg-white border border-gray-300 text-gray-700 text-[10px] font-bold rounded hover:bg-gray-50 shadow-sm"
                        title="Reset"><i class="fas fa-undo"></i></button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-md border border-gray-200 min-w-0 lg:flex-[1_1_45%] self-start w-full">
            <div class="bg-blue-50 px-3 py-1.5 border-b border-blue-100 rounded-t-lg">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-xs font-bold text-blue-800 flex items-center">
                        <i class="fas fa-list mr-1.5 text-xs"></i> Recent Entries
                    </h2>
                    <form method="GET" action="" id="peDateRangeForm" class="flex items-center gap-1.5">
                        <input type="date" name="start_date" id="peStartDate"
                            value="<?= htmlspecialchars($start_date) ?>"
                            class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 focus:border-blue-400 bg-white font-medium"
                            max="<?= date('Y-m-d') ?>" title="From Date">
                        <span class="text-gray-400 text-[10px] font-bold">to</span>
                        <input type="date" name="end_date" id="peEndDate" value="<?= htmlspecialchars($end_date) ?>"
                            class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 focus:border-blue-400 bg-white font-medium"
                            max="<?= date('Y-m-d') ?>" title="To Date">
                        <button type="submit"
                            class="px-1.5 py-0.5 bg-blue-600 text-white text-[10px] font-bold rounded hover:bg-blue-700 transition shadow-sm"
                            title="Apply Date Filter">
                            <i class="fas fa-filter text-[10px]"></i>
                        </button>
                    </form>
                </div>
            </div>
            <div class="p-2">
                <div class="ge-txn-scroll" id="peTxnScroll">
                    <table class="w-full text-sm text-left text-gray-500 ge-txn-table">
                        <thead class="bg-slate-50 border-b border-slate-100">
                            <tr>
                                <th class="py-2 px-1 text-center text-[9px] font-bold text-slate-500 ge-serial-col">#</th>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 w-16">Id</th>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 ge-party-col">Party</th>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500">Type</th>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500">Detail</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500 ge-action-col">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (count($recent_list) > 0):
                            foreach ($recent_list as $index => $r):
                                $serial = $index + 1;
                                $lbl = (string) ($r['booking_type'] ?? '');
                                $detail = pe_entry_detail_line($r);
                                $is_cash = strpos($lbl, 'Cash') !== false;
                            ?>
                            <tr class="ge-txn-row hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0">
                                <td class="py-1.5 px-1 align-top text-center ge-serial-col">
                                    <span class="text-[9px] font-bold text-slate-400 tabular-nums"><?= $serial ?></span>
                                </td>
                                <td class="py-1.5 px-2 align-top group">
                                    <div class="text-[10px] font-bold text-blue-600 group-hover:underline truncate flex items-center gap-0.5">
                                        <span class="truncate">#<?= htmlspecialchars($r['receipt_id']) ?></span>
                                        <?php if ($is_cash): ?>
                                            <?php if (strcasecmp(trim((string)($r['payment_method'] ?? 'Cash')), 'Cash') !== 0): ?>
                                                <i class="fas fa-university text-indigo-600 text-[9px] shrink-0" title="Bank"></i>
                                            <?php else: ?>
                                                <i class="fas fa-wallet text-emerald-600 text-[9px] shrink-0" title="Cash"></i>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <i class="fas fa-coins text-amber-600 text-[9px] shrink-0" title="Gold"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[8px] font-semibold text-slate-400 leading-tight tabular-nums whitespace-nowrap">
                                        <?= date('d M', strtotime($r['date_of_transaction'])) ?> · <?= date('h:i A', strtotime($r['date_of_transaction'])) ?>
                                    </div>
                                </td>
                                <td class="py-1.5 px-2 align-top ge-party-col">
                                    <div class="text-[10px] font-semibold text-slate-800 truncate uppercase" title="<?= htmlspecialchars($r['party_name'] ?? '') ?>">
                                        <?= htmlspecialchars($r['party_name'] ?? '—') ?>
                                    </div>
                                </td>
                                <td class="py-1.5 px-2 align-top">
                                    <span class="text-[7.5px] px-1 py-0.5 rounded font-bold uppercase tracking-tighter <?= pe_kind_badge_class($lbl) ?>">
                                        <?= htmlspecialchars(pe_kind_short_label($lbl)) ?>
                                    </span>
                                </td>
                                <td class="py-1.5 px-2 align-top">
                                    <div class="text-[10px] font-semibold text-slate-700 leading-tight truncate" title="<?= htmlspecialchars($detail) ?>">
                                        <?= htmlspecialchars($detail) ?>
                                    </div>
                                </td>
                                <td class="py-1.5 px-2 align-top ge-action-col whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-0.5">
                                        <button type="button" class="ge-action-btn pe-edit text-blue-500 hover:text-blue-700" title="Edit" data-id="<?= (int)$r['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="ge-action-btn pe-del text-red-500 hover:text-red-700" title="Delete" data-id="<?= (int)$r['id'] ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach;
                            else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-8 text-gray-500">
                                    <i class="fas fa-inbox text-2xl mb-2"></i><br>
                                    No entries found
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pe_list_has_more): ?>
                <p class="text-[9px] text-slate-400 text-center mt-1">Showing first <?= count($recent_list) ?> of <?= $pe_total_entries ?> — narrow the date range to see more</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="js/keyboard-navigation-generic.js"></script>
<script>
(function () {
    var BT_TO_KIND = { PE_Take_Cash: 'take_cash', PE_Give_Cash: 'give_cash', PE_Take_Gold: 'take_gold', PE_Give_Gold: 'give_gold' };
    var selectedPePartyName = '';
    var peSaveBtnClass = 'min-w-[7rem] bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-4 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter';
    var peDefaultFineGold = <?= json_encode($pe_default_fine_gold ? [
        'stock_name' => (string) ($pe_default_fine_gold['stock_name'] ?? ''),
        'purity' => (float) ($pe_default_fine_gold['purity'] ?? 0),
    ] : null, JSON_UNESCAPED_UNICODE) ?>;
    var pePartyListVisible = false;
    var pePartyCurrentIndex = -1;

    function inr(n) {
        var x = parseFloat(n);
        if (isNaN(x)) x = 0;
        return '₹' + x.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    /** Match payment_send.php — Cash/Bank payable wells + hint; add gold/silver row for this screen. */
    function renderPePartyInfo(r) {
        if (!r || r.status !== 'ok') { $('#pePartyInfoSection').addClass('hidden'); return; }
        var cash = parseFloat(r.cash_balance) || 0;
        var bank = parseFloat(r.bank_balance) || 0;
        var cashPay = cash < 0 ? Math.abs(cash) : 0;
        var bankPay = bank < 0 ? Math.abs(bank) : 0;
        var g = parseFloat(r.gold_balance) || 0;
        var silv = parseFloat(r.silver_balance) || 0;
        var nm = (r.party_name || '').trim();
        var nameLine = nm ? '<div class="text-[9px] font-bold text-slate-800 mb-1.5 leading-tight">' + $('<span/>').text(nm).html() + '</div>' : '';

        var gStr = g.toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
        var silvStr = silv.toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
        var fourCol = '<div class="overflow-x-auto -mx-0.5 px-0.5">' +
            '<div class="grid grid-cols-4 gap-1.5 min-w-[28rem]">' +
            '<div class="min-w-0">' +
            '<label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Cash payable</label>' +
            '<div class="font-mono text-[11px] font-bold text-slate-900 bg-white border border-slate-200 rounded px-1.5 py-1 leading-none truncate" title="Owed on cash leg; Method Cash affects this">' + inr(cashPay) + '</div></div>' +
            '<div class="min-w-0">' +
            '<label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Bank payable</label>' +
            '<div class="font-mono text-[11px] font-bold text-slate-900 bg-white border border-slate-200 rounded px-1.5 py-1 leading-none truncate" title="Owed on bank leg; Bank/UPI/Cheque affect this">' + inr(bankPay) + '</div></div>' +
            '<div class="min-w-0">' +
            '<label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Gold balance</label>' +
            '<div class="font-mono text-[11px] font-bold text-slate-900 bg-white border border-slate-200 rounded px-1.5 py-1 leading-none truncate" title="Party gold weight">' +
            '<i class="fas fa-coins text-amber-600 mr-0.5 text-[9px]"></i>' + gStr + ' g</div></div>' +
            '<div class="min-w-0">' +
            '<label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Silver balance</label>' +
            '<div class="font-mono text-[11px] font-bold text-slate-900 bg-white border border-slate-200 rounded px-1.5 py-1 leading-none truncate" title="Party silver weight">' +
            '<i class="fas fa-compact-disc text-slate-500 mr-0.5 text-[9px]"></i>' + silvStr + ' g Ag</div></div>' +
            '</div></div>' +
            '<p class="text-[8px] text-slate-500 mt-1 leading-snug">Choose <b>Method</b> below — cash lowers cash payable; bank lowers bank payable. <b>Take/give gold</b> updates party gold and stock (silver for reference).</p>';

        $('#pePartyInfoAlert').html(nameLine + fourCol);
        $('#pePartyInfoSection').removeClass('hidden');
    }
    function escPePartyHtml(t) {
        return String(t ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }
    function appendPeCreatePartyRow(partyListEl, searchTerm) {
        var term = (searchTerm || '').trim();
        if (!term) return;
        var row = document.createElement('div');
        row.className = 'px-3 py-2 hover:bg-emerald-50 cursor-pointer transition-colors border-t-2 border-emerald-200 bg-emerald-50/80 party-item';
        row.setAttribute('data-create-new', '1');
        row.innerHTML = '<div class="flex items-center gap-2"><i class="fas fa-plus-circle text-emerald-600"></i>' +
            '<div class="font-semibold text-[11px] text-emerald-800">Create new party &quot;' + escPePartyHtml(term) + '&quot;</div></div>';
        row.addEventListener('mousedown', function (e) { e.preventDefault(); });
        row.addEventListener('click', function (e) {
            e.stopPropagation();
            $(partyListEl).addClass('hidden');
            pePartyListVisible = false;
            pePartyCurrentIndex = -1;
            showAddPartyModal(term);
        });
        partyListEl.appendChild(row);
    }
    function selectPeParty(pid, pnm) {
        pid = parseInt(pid, 10) || 0;
        pnm = pnm || '';
        $('#pePartyName').val(pnm);
        $('#pePartyId').val(pid > 0 ? String(pid) : '');
        selectedPePartyName = pnm;
        $('#pePartyList').addClass('hidden');
        pePartyListVisible = false;
        pePartyCurrentIndex = -1;
        if (window.KeyboardNavigation && typeof window.KeyboardNavigation.clearValidationError === 'function') {
            window.KeyboardNavigation.clearValidationError('pePartyName');
        }
        if (pid > 0) {
            $.post('', { action: 'get_party_info', party_id: pid }, function (info) { renderPePartyInfo(info); }, 'json');
        } else {
            $('#pePartyInfoSection').addClass('hidden');
        }
    }
    function updatePePartyHighlight() {
        var partyItems = document.querySelectorAll('#pePartyList .party-item');
        partyItems.forEach(function (item, index) {
            if (index === pePartyCurrentIndex && pePartyCurrentIndex >= 0) {
                item.classList.add('bg-amber-100', 'border-l-4', 'border-amber-400');
                item.classList.remove('hover:bg-amber-50/90', 'hover:bg-emerald-50', 'bg-emerald-50/80');
            } else {
                item.classList.remove('bg-amber-100', 'border-l-4', 'border-amber-400');
                if (item.getAttribute('data-create-new')) {
                    item.classList.add('hover:bg-emerald-50', 'bg-emerald-50/80');
                } else {
                    item.classList.add('hover:bg-amber-50/90');
                }
            }
        });
        if (pePartyCurrentIndex >= 0 && pePartyCurrentIndex < partyItems.length) {
            partyItems[pePartyCurrentIndex].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
    function peParseNotes(narr) {
        var notes = '';
        var stock = '';
        var s = narr == null ? '' : String(narr).trim();
        if (s) {
            var idx = s.lastIndexOf(' · stock:');
            if (idx >= 0) {
                stock = s.slice(idx + ' · stock:'.length).trim();
                s = s.slice(0, idx).trim();
            }
            notes = s.replace(/^Personal expense · PE_[A-Za-z_]+\s*(—\s*)?/u, '').trim();
        }
        return { notes: notes, stock: stock };
    }
    function applyPeStockPurityFromSelect() {
        var p = $('#peStockName').find(':selected').data('purity');
        if (p) { $('#pePurity').val(String(p)); }
    }
    function applyPeDefaultFineGoldStock(force) {
        if ($('#peEditTransactionId').val() && !force) { return; }
        var $st = $('#peStockName');
        if (!force && $st.val()) { return; }
        var $opt = $st.find('option[data-fine-gold="1"]').first();
        if (!$opt.length && peDefaultFineGold && peDefaultFineGold.stock_name) {
            $opt = $st.find('option').filter(function () {
                return $(this).val() === peDefaultFineGold.stock_name;
            }).first();
        }
        if (!$opt.length) {
            $opt = $st.find('option').filter(function () {
                var v = String($(this).val() || '').toLowerCase();
                return v && v.indexOf('fine') >= 0 && v.indexOf('silver') < 0;
            }).first();
        }
        if ($opt.length) {
            $st.val($opt.val());
            applyPeStockPurityFromSelect();
        } else if (peDefaultFineGold && peDefaultFineGold.purity) {
            $('#pePurity').val(String(peDefaultFineGold.purity));
        }
    }
    function refreshKindUi() {
        var k = $('#peKind').val();
        var cash = (k === 'take_cash' || k === 'give_cash');
        $('#peCashBlock').toggleClass('hidden', !cash);
        $('#peGoldBlock').toggleClass('hidden', cash);
        if (cash) {
            $('#peGoldW, #pePurity').prop('required', false);
            $('#peCashAmt').prop('required', true);
        } else {
            $('#peCashAmt').prop('required', false);
            $('#peGoldW, #pePurity').prop('required', true);
            if (k === 'give_gold' && !$('#peEditTransactionId').val()) {
                applyPeDefaultFineGoldStock(true);
            }
        }
    }
    function genId() {
        $.post('', { action: 'generate_pe_receipt_id' }, function (res) {
            if (res.status === 'success') { $('#peReceiptId').val(res.receipt_id); }
            else { $('#peReceiptId').val('PE<?= (int)$company_id ?>' + String(Date.now()).slice(-4)); }
        }, 'json').fail(function () {
            $('#peReceiptId').val('PE<?= (int)$company_id ?>' + String(Date.now()).slice(-4));
        });
    }
    function setNow() {
        var n = new Date();
        n.setMinutes(n.getMinutes() - n.getTimezoneOffset());
        $('#peDate').val(n.toISOString().slice(0, 16));
    }
    function clearEditMode() {
        $('#peEditTransactionId').val('');
        $('#peEditModeIndicator').addClass('hidden');
        $('#peDeleteEditedBtn').addClass('hidden');
        $('#peFormCard').css('border', '');
        $('#peSubmit').html('<i class="fas fa-save mr-1"></i><span>Save</span>').attr('class', peSaveBtnClass);
    }
    function enterEditMode() {
        $('#peEditModeIndicator').removeClass('hidden');
        $('#peDeleteEditedBtn').removeClass('hidden');
        $('#peFormCard').css('border', '2px solid #f97316');
        $('#peSubmit').html('<i class="fas fa-save mr-1"></i><span>Update</span>').attr('class', peSaveBtnClass);
    }
    function loadPeForEdit(tid) {
        $.post('', { action: 'get_pe_details', transaction_id: tid }, function (res) {
            if (res.status !== 'success' || !res.data) {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load' });
                return;
            }
            var d = res.data;
            $('#peEditTransactionId').val(String(d.id));
            enterEditMode();
            $('#peReceiptId').val(d.receipt_id || '');
            var dateValue = '';
            if (d.date_of_transaction) {
                var raw = String(d.date_of_transaction).replace(' ', 'T');
                var dt = new Date(raw);
                dateValue = !isNaN(dt.getTime()) ? dt.toISOString().slice(0, 16) : raw.substring(0, 16);
            }
            $('#peDate').val(dateValue);
            var pid = parseInt(d.party_id, 10) || 0;
            $('#pePartyId').val(pid > 0 ? String(pid) : '');
            $('#pePartyName').val((d.party_name || '').trim());
            selectedPePartyName = ($('#pePartyName').val() || '').trim();
            if (pid > 0) {
                $.post('', { action: 'get_party_info', party_id: pid }, function (info) { renderPePartyInfo(info); }, 'json').fail(function () { $('#pePartyInfoSection').addClass('hidden'); });
            } else { $('#pePartyInfoSection').addClass('hidden'); }
            var bk = String(d.booking_type || '').trim();
            $('#peKind').val(BT_TO_KIND[bk] || 'take_cash');
            refreshKindUi();
            $('#peCashAmt').val(d.payment_amount != null ? d.payment_amount : '');
            $('#pePayMeth').val(d.payment_method || 'Cash');
            $('#peGoldW').val(d.gold_weight != null ? d.gold_weight : '');
            $('#pePurity').val(d.purity != null ? d.purity : '');
            $('#peGoldRate').val(d.rate != null ? d.rate : '0');
            var parsed = peParseNotes(d.narration || '');
            $('#peForm').find('[name="narration"]').val(parsed.notes);
            var $st = $('#peStockName');
            $st.val('');
            if (parsed.stock) {
                var want = parsed.stock;
                $st.find('option').each(function () {
                    if ($(this).val() === want) { $st.val(want); return false; }
                });
            }
            if (window.KeyboardNavigation && typeof window.KeyboardNavigation.clearValidationError === 'function') {
                window.KeyboardNavigation.clearValidationError('pePartyName');
            }
            $('#peReceiptList').addClass('hidden');
        }, 'json').fail(function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load' });
        });
    }
    function showPeReceiptList() {
        var el = $('#peReceiptList');
        el.html('<div class="p-2 text-center text-gray-500 text-[10px]"><i class="fas fa-spinner fa-spin"></i> Loading…</div>');
        el.removeClass('hidden');
        $.post('', { action: 'get_pe_list' }, function (response) {
            if (response.status === 'success' && response.data && response.data.length > 0) {
                var rows = '';
                response.data.forEach(function (p) {
                    var rid = String(p.receipt_id || '').replace(/</g, '&lt;');
                    var dt = (p.date_of_transaction || '').split(' ')[0];
                    var pnm = String(p.party_name || '—').replace(/</g, '&lt;');
                    var bt = String(p.booking_type || '').replace(/</g, '&lt;');
                    var det = '';
                    if (bt.indexOf('Cash') >= 0) {
                        det = inr(p.payment_amount) + ' · ' + String(p.payment_method || '').replace(/</g, '&lt;');
                    } else {
                        det = (parseFloat(p.gold_weight) || 0).toLocaleString('en-IN', { minimumFractionDigits: 3 }) + 'g @ ' + (parseFloat(p.purity) || 0) + '%';
                    }
                    rows += '<tr class="pe-pick-row border-b border-gray-100 hover:bg-slate-100 cursor-pointer align-top" data-tid="' + p.id + '">' +
                        '<td class="py-0.5 px-1.5"><div class="font-mono font-bold text-[11px]">' + rid + '</div><div class="text-[9px] text-gray-500">' + dt + '</div></td>' +
                        '<td class="py-0.5 px-1.5 text-[10px]">' + pnm + '</td>' +
                        '<td class="py-0.5 px-1.5 text-[9px]"><span class="bg-amber-100 text-amber-900 px-0.5 rounded">' + bt + '</span></td>' +
                        '<td class="py-0.5 px-1.5 text-[10px] text-right">' + det + '</td></tr>';
                });
                el.html('<table class="w-full border-collapse text-[10px]"><thead><tr class="bg-gray-100 text-gray-600 border-b">' +
                    '<th class="text-left font-semibold px-1.5 py-0.5">Receipt</th><th class="text-left font-semibold px-1.5 py-0.5">Party</th>' +
                    '<th class="text-left font-semibold px-1.5 py-0.5">Type</th><th class="text-right font-semibold px-1.5 py-0.5">Detail</th></tr></thead><tbody>' + rows + '</tbody></table>');
                el.find('tr.pe-pick-row').on('click', function () { loadPeForEdit($(this).data('tid')); });
            } else {
                el.html('<div class="p-2 text-center text-gray-500 text-[10px]">No entries yet</div>');
            }
        }, 'json').fail(function () {
            el.html('<div class="p-2 text-center text-red-500 text-[10px]">Error loading list</div>');
        });
    }
    function showAddPartyModal(prefill) {
        Swal.fire({
            title: 'Add new party',
            html: '<input id="swalPePartyName" class="swal2-input" placeholder="Name" value="' + String(prefill || '').replace(/"/g, '&quot;') + '">' +
                '<input id="swalPePartyAddr" class="swal2-input" placeholder="Address (optional)">' +
                '<input id="swalPePartyContact" class="swal2-input" placeholder="Contact (optional)">',
            showCancelButton: true,
            confirmButtonText: 'Create',
            confirmButtonColor: '#334155',
            preConfirm: function () {
                var n = document.getElementById('swalPePartyName').value.trim();
                if (!n) { Swal.showValidationMessage('Name required'); return false; }
                return {
                    name: n,
                    address: document.getElementById('swalPePartyAddr').value.trim(),
                    contact: document.getElementById('swalPePartyContact').value.trim()
                };
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            var v = r.value;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=save_party&party_name=' + encodeURIComponent(v.name) + '&address=' + encodeURIComponent(v.address) + '&contact_no=' + encodeURIComponent(v.contact)
            }).then(function (x) { return x.json(); }).then(function (result) {
                if (result.status === 'success') {
                    $('#pePartyId').val(result.party_id);
                    $('#pePartyName').val(v.name);
                    selectedPePartyName = v.name;
                    if (window.KeyboardNavigation && typeof window.KeyboardNavigation.clearValidationError === 'function') {
                        window.KeyboardNavigation.clearValidationError('pePartyName');
                    }
                    $.post('', { action: 'get_party_info', party_id: result.party_id }, function (info) { renderPePartyInfo(info); }, 'json');
                    Swal.fire({ icon: 'success', title: 'Created', timer: 1200, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'Failed' });
                }
            });
        });
    }
    $(function () {
        genId(); setNow(); refreshKindUi();
        if (typeof KeyboardNavigationGeneric !== 'undefined') {
            KeyboardNavigationGeneric.init({
                formId: 'peForm',
                fieldOrder: ['peReceiptId', 'date_of_transaction', 'pePartyName', 'pe_kind', 'stock_name', 'gold_weight', 'purity', 'gold_rate', 'cash_amount', 'payment_method', 'narration'],
                skipFields: [],
                submitButtonId: 'peSubmit',
                formName: 'personal_expense'
            });
            window.KeyboardNavigation = KeyboardNavigationGeneric;
        }
        $('#peKind').on('change', refreshKindUi);
        $('#peStockName').on('change', applyPeStockPurityFromSelect);
        $('#showPeListBtn, #peReceiptId').on('click', function (e) {
            e.preventDefault();
            showPeReceiptList();
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#peReceiptList, #showPeListBtn, #peReceiptId').length) { $('#peReceiptList').addClass('hidden'); }
            if (!$(e.target).closest('#pePartyName, #pePartyList, #peAddPartyBtn').length && pePartyListVisible) {
                $('#pePartyList').addClass('hidden');
                pePartyListVisible = false;
                pePartyCurrentIndex = -1;
            }
        });
        $('#peAddPartyBtn').on('click', function (e) {
            e.preventDefault();
            showAddPartyModal(($('#pePartyName').val() || '').trim());
        });
        var peTimer;
        $('#pePartyName').on('keydown', function (e) {
            var partyItems = document.querySelectorAll('#pePartyList .party-item');
            if (pePartyListVisible && partyItems.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    e.stopPropagation();
                    pePartyCurrentIndex = pePartyCurrentIndex < 0 ? 0 : Math.min(pePartyCurrentIndex + 1, partyItems.length - 1);
                    updatePePartyHighlight();
                    return;
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    e.stopPropagation();
                    pePartyCurrentIndex = pePartyCurrentIndex <= 0 ? -1 : Math.max(pePartyCurrentIndex - 1, 0);
                    updatePePartyHighlight();
                    return;
                }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    var idx = pePartyCurrentIndex >= 0 ? pePartyCurrentIndex : 0;
                    var selectedItem = partyItems[idx];
                    if (selectedItem) {
                        if (selectedItem.getAttribute('data-create-new')) {
                            selectedItem.click();
                            return;
                        }
                        selectPeParty(selectedItem.getAttribute('data-id'), selectedItem.getAttribute('data-name'));
                        setTimeout(function () {
                            var next = document.getElementById('peKind');
                            if (next) { next.focus(); }
                        }, 50);
                    }
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    $('#pePartyList').addClass('hidden');
                    pePartyListVisible = false;
                    pePartyCurrentIndex = -1;
                    return;
                }
            }
        });
        $('#pePartyName').on('input', function () {
            clearTimeout(peTimer);
            pePartyCurrentIndex = -1;
            var term = $(this).val();
            if (term !== selectedPePartyName) {
                selectedPePartyName = '';
                $('#pePartyId').val('');
            }
            var v = term.trim();
            if (v.length < 1) {
                $('#pePartyList').empty().addClass('hidden');
                pePartyListVisible = false;
                $('#pePartyInfoSection').addClass('hidden');
                return;
            }
            peTimer = setTimeout(function () {
                $.post('', { action: 'search_parties', term: v }, function (rows) {
                    var listEl = document.getElementById('pePartyList');
                    var $l = $(listEl).empty();
                    pePartyCurrentIndex = -1;
                    if (!rows || !rows.length) {
                        appendPeCreatePartyRow(listEl, v);
                        $l.removeClass('hidden');
                        pePartyListVisible = true;
                        return;
                    }
                    rows.forEach(function (party) {
                        var cb = parseFloat(party.cash_balance) || 0;
                        var bb = parseFloat(party.bank_balance) || 0;
                        var totalRaw = parseFloat(party.total_due_amount);
                        var ledger = !isNaN(totalRaw) ? totalRaw : (cb + bb);
                        var gb = parseFloat(party.gold_balance != null ? party.gold_balance : party.total_due_gold) || 0;
                        var sb = parseFloat(party.silver_balance != null ? party.silver_balance : party.total_due_silver) || 0;
                        var pname = escPePartyHtml(party.party_name || '');
                        var addr = escPePartyHtml((party.address || '').trim() || 'No address');
                        var walletCls = ledger < -0.005 ? 'text-red-600' : 'text-rose-600';
                        var silverLine = Math.abs(sb) >= 0.0001
                            ? '<div class="text-[10px] text-slate-500 font-bold tracking-tight"><i class="fas fa-compact-disc mr-1 opacity-70"></i>' + sb.toFixed(3) + 'g Ag</div>'
                            : '';
                        var item = document.createElement('div');
                        item.className = 'px-3 py-2 hover:bg-amber-50/90 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors party-item';
                        item.setAttribute('data-id', party.id || '');
                        item.setAttribute('data-name', party.party_name || '');
                        item.innerHTML =
                            '<div class="flex justify-between items-start gap-2">' +
                            '<div class="font-bold text-[11px] text-slate-800 uppercase tracking-tight leading-tight">' + pname + '</div>' +
                            '<div class="text-[10px] text-slate-400 font-medium truncate max-w-[130px] text-right">' + addr + '</div></div>' +
                            '<div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-1">' +
                            '<div class="text-[10px] ' + walletCls + ' font-bold tracking-tight"><i class="fas fa-wallet mr-1 opacity-70"></i>₹' + ledger.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                            '<div class="text-[9px] text-slate-500 font-semibold">C ₹' + cb.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' · B ₹' + bb.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                            '<div class="text-[10px] text-amber-600 font-bold tracking-tight"><i class="fas fa-coins mr-1 opacity-70"></i>' + gb.toFixed(3) + 'g</div>' +
                            silverLine + '</div>';
                        item.addEventListener('mousedown', function (e) { e.preventDefault(); });
                        item.addEventListener('click', function (e) {
                            e.stopPropagation();
                            selectPeParty(item.getAttribute('data-id'), item.getAttribute('data-name'));
                        });
                        listEl.appendChild(item);
                    });
                    appendPeCreatePartyRow(listEl, v);
                    $l.removeClass('hidden');
                    pePartyListVisible = true;
                }, 'json');
            }, 200);
        });
        $('#peForm').on('submit', function (e) {
            e.preventDefault();
            if (!$('#pePartyId').val()) { Swal.fire('Party', 'Select a party from search', 'warning'); return; }
            var fd = $(this).serializeArray();
            fd.push({ name: 'action', value: 'save_personal_expense' });
            $.post('', fd, function (res) {
                var okTitle = ($('#peEditTransactionId').val() ? 'Updated' : 'Saved');
                if (res.status === 'success') {
                    Swal.fire({ icon: 'success', title: okTitle, timer: 1100, showConfirmButton: false }).then(function () { location.reload(); });
                } else { Swal.fire('Error', res.message || 'Failed', 'error'); }
            }, 'json');
        });
        $('#peReset').on('click', function () {
            $('#peForm')[0].reset();
            $('#pePartyId').val('');
            selectedPePartyName = '';
            $('#pePartyInfoSection').addClass('hidden');
            clearEditMode();
            genId(); setNow(); refreshKindUi();
        });
        $('#peDeleteEditedBtn').on('click', function () {
            var id = $('#peEditTransactionId').val();
            if (!id) return;
            Swal.fire({ title: 'Delete this entry?', icon: 'warning', showCancelButton: true }).then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'delete_personal_expense', transaction_id: id }, function (res) {
                    if (res.status === 'success') { location.reload(); }
                    else { Swal.fire('Error', res.message, 'error'); }
                }, 'json');
            });
        });
        $(document).on('click', '.pe-edit', function () {
            loadPeForEdit($(this).data('id'));
            $('html, body').animate({ scrollTop: $('#peFormCard').offset().top - 40 }, 200);
        });
        $(document).on('click', '.pe-del', function () {
            var id = $(this).data('id');
            Swal.fire({ title: 'Delete?', icon: 'warning', showCancelButton: true }).then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'delete_personal_expense', transaction_id: id }, function (res) {
                    if (res.status === 'success') { location.reload(); }
                    else { Swal.fire('Error', res.message, 'error'); }
                }, 'json');
            });
        });
        $('#peStartDate, #peEndDate').on('change', function () {
            var startDate = new Date($('#peStartDate').val());
            var endDate = new Date($('#peEndDate').val());
            if (startDate > endDate) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Date Range',
                    text: 'End date must be greater than or equal to start date',
                    confirmButtonColor: '#3085d6',
                    timer: 2000,
                    showConfirmButton: false
                });
                if ($(this).attr('id') === 'peStartDate') {
                    $('#peEndDate').val($('#peStartDate').val());
                } else {
                    $('#peStartDate').val($('#peEndDate').val());
                }
            }
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/components/layout.php';

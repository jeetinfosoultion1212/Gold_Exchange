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
function personal_expense_fine_stock_qty(mysqli $conn, int $company_id, string $material): float
{
    $fineP = '(purity >= 99.50 OR purity = 100.00 OR purity = 100.0 OR purity = 100)';
    if (strcasecmp(trim($material), 'Silver') === 0) {
        $sql = "SELECT current_stock FROM gold_stock
            WHERE company_id = ? AND mode = 'Cash'
            AND {$fineP}
            AND (LOWER(stock_name) LIKE '%silver%')
            ORDER BY
                CASE WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1 ELSE 2 END,
                purity DESC, id ASC
            LIMIT 1";
    } else {
        $sql = "SELECT current_stock FROM gold_stock
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
        return 0.0;
    }
    $st->bind_param('i', $company_id);
    $st->execute();
    $res = $st->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        return (float) ($row['current_stock'] ?? 0);
    }
    return 0.0;
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

$recent_sql = "SELECT t.*, p.party_name FROM transactions t 
    LEFT JOIN parties p ON t.party_id = p.id AND p.company_id = t.company_id
    WHERE t.company_id = $company_id AND t.transaction_type = 'Personal_Expense' 
    ORDER BY t.date_of_transaction DESC, t.id DESC LIMIT 15";
$recent_res = $conn->query($recent_sql);

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
.soft-gradient-slate { background: linear-gradient(135deg, rgba(71, 85, 105, 0.12), rgba(71, 85, 105, 0.04)); }
.soft-gradient-amber { background: linear-gradient(135deg, rgba(245, 158, 11, 0.12), rgba(245, 158, 11, 0.04)); }
.soft-gradient-green { background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.04)); }
.soft-gradient-blue { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.04)); }
.soft-gradient-yellow { background: linear-gradient(135deg, rgba(234, 179, 8, 0.12), rgba(234, 179, 8, 0.05)); }
.soft-gradient-gray { background: linear-gradient(135deg, rgba(148, 163, 184, 0.14), rgba(148, 163, 184, 0.05)); }
.compact-input { padding-top: 0.375rem !important; padding-bottom: 0.375rem !important; font-size: 0.75rem !important; }
#pePartyList, #peReceiptList { z-index: 50; }
</style>

<div class="w-full px-1 pb-4">
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-4 gap-2 mb-2">
        <div class="soft-gradient-green rounded-xl p-2 shadow-sm border border-slate-200/50">
            <div class="flex items-center justify-between gap-1">
                <div class="min-w-0">
                    <p class="text-[9px] font-bold text-emerald-800 uppercase tracking-tighter leading-none mb-0.5">Cash in hand</p>
                    <p class="text-[13px] font-bold text-emerald-900 leading-none truncate">₹<?= number_format($pe_stats['cash_in_hand'], 0) ?></p>
                    <p class="text-[9px] text-emerald-700/80">Company</p>
                </div>
                <div class="w-6 h-6 bg-emerald-500 rounded shrink-0 flex items-center justify-center"><i class="fas fa-wallet text-white text-[9px]"></i></div>
            </div>
        </div>
        <div class="soft-gradient-blue rounded-xl p-2 shadow-sm border border-slate-200/50">
            <div class="flex items-center justify-between gap-1">
                <div class="min-w-0">
                    <p class="text-[9px] font-bold text-blue-800 uppercase tracking-tighter leading-none mb-0.5">Bank balance</p>
                    <p class="text-[13px] font-bold text-blue-900 leading-none truncate">₹<?= number_format($pe_stats['bank_balance'], 0) ?></p>
                    <p class="text-[9px] text-blue-700/80">Company</p>
                </div>
                <div class="w-6 h-6 bg-blue-500 rounded shrink-0 flex items-center justify-center"><i class="fas fa-university text-white text-[9px]"></i></div>
            </div>
        </div>
        <div class="soft-gradient-yellow rounded-xl p-2 shadow-sm border border-slate-200/50">
            <div class="flex items-center justify-between gap-1">
                <div class="min-w-0">
                    <p class="text-[9px] font-bold text-amber-900 uppercase tracking-tighter leading-none mb-0.5">Fine stock gold</p>
                    <p class="text-[13px] font-bold text-amber-950 leading-none truncate"><?= number_format($pe_stats['fine_gold_g'], 3) ?> g</p>
                    <p class="text-[9px] text-amber-800/90">Vault row</p>
                </div>
                <div class="w-6 h-6 bg-amber-500 rounded shrink-0 flex items-center justify-center"><i class="fas fa-coins text-white text-[9px]"></i></div>
            </div>
        </div>
        <div class="soft-gradient-gray rounded-xl p-2 shadow-sm border border-slate-200/50">
            <div class="flex items-center justify-between gap-1">
                <div class="min-w-0">
                    <p class="text-[9px] font-bold text-slate-700 uppercase tracking-tighter leading-none mb-0.5">Fine stock silver</p>
                    <p class="text-[13px] font-bold text-slate-900 leading-none truncate"><?= number_format($pe_stats['fine_silver_g'], 3) ?> g</p>
                    <p class="text-[9px] text-slate-600">Vault row</p>
                </div>
                <div class="w-6 h-6 bg-slate-500 rounded shrink-0 flex items-center justify-center"><i class="fas fa-gem text-white text-[9px]" title="Fine silver"></i></div>
            </div>
        </div>
    </div>
    <p class="text-[10px] text-slate-600 mb-3 leading-snug px-0.5">Updates party <b>cash</b> / <b>bank</b> on take or give cash; party <b>gold balance</b> on take gold (−) or give gold (+), plus stock.</p>

    <div class="flex flex-col lg:flex-row gap-3">
        <div id="peFormCard" class="bg-white rounded-lg shadow border border-gray-200 lg:flex-[1.1]">
            <div class="bg-gradient-to-r from-slate-600 to-slate-700 px-3 py-1.5 rounded-t-lg">
                <h2 class="text-xs font-bold text-white flex items-center gap-1">
                    <i class="fas fa-hand-holding-dollar"></i> New entry
                </h2>
            </div>
            <form id="peForm" class="p-2 space-y-2" onsubmit="return false;">
                <input type="hidden" name="transaction_id" id="peEditTransactionId" value="">
                <div class="overflow-x-auto pb-0.5 -mx-0.5 px-0.5">
                <div class="grid grid-cols-12 gap-1.5 items-end min-w-[36rem]">
                    <div class="col-span-4 min-w-0 relative">
                        <label class="block text-[10px] font-bold text-gray-600 uppercase">Receipt <span id="peEditModeIndicator" class="text-orange-600 hidden">(Edit)</span></label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-slate-400"><i class="fas fa-hashtag text-[10px]"></i></span>
                            <input type="text" name="receipt_id" id="peReceiptId" readonly tabindex="0"
                                class="compact-input block w-full border rounded pl-6 pr-8 py-1 text-xs font-mono bg-gray-50 cursor-pointer">
                            <button type="button" id="showPeListBtn" class="absolute right-1 top-1/2 -translate-y-1/2 text-gray-400 hover:text-slate-700" title="Open history"><i class="fas fa-history text-xs"></i></button>
                        </div>
                        <div id="peReceiptList" class="hidden absolute left-0 right-0 mt-0.5 bg-white border rounded shadow-lg max-h-56 overflow-y-auto z-50"></div>
                    </div>
                    <div class="col-span-3 min-w-0">
                        <label class="block text-[10px] font-bold text-gray-600 uppercase">Date</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-slate-500"><i class="fas fa-calendar-alt text-[10px]"></i></span>
                            <input type="datetime-local" name="date_of_transaction" id="peDate" required class="compact-input block w-full border rounded pl-7 pr-1 py-1 text-[11px]">
                        </div>
                    </div>
                    <div class="col-span-5 min-w-0 relative">
                        <label class="block text-[10px] font-bold text-gray-600 uppercase flex items-center justify-between gap-1">
                            <span>Party</span>
                            <button type="button" id="peAddPartyBtn" class="text-slate-700 hover:text-slate-900 font-bold text-[9px] flex items-center uppercase tracking-tighter shrink-0">
                                <i class="fas fa-plus-circle mr-0.5 text-[10px]"></i>New
                            </button>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-slate-500"><i class="fas fa-user text-xs"></i></span>
                            <input type="text" id="pePartyName" autocomplete="off" placeholder="Search party" class="compact-input block w-full border rounded pl-7 pr-2 py-1 text-xs font-bold text-gray-900" required>
                        </div>
                        <input type="hidden" name="party_id" id="pePartyId">
                        <div id="pePartyList" class="hidden absolute z-50 left-0 right-0 top-full mt-0.5 bg-white border border-gray-200 rounded-lg shadow-lg max-h-72 overflow-y-auto"></div>
                    </div>
                    <div id="pePartyInfoSection" class="col-span-12 hidden px-2 pb-1">
                        <div class="bg-slate-50/90 border border-slate-200 rounded-md px-2 py-1.5 text-xs" id="pePartyInfoAlert"></div>
                    </div>
                    <div class="col-span-4 min-w-0">
                        <label class="block text-[10px] font-bold text-gray-600 uppercase">Type</label>
                        <select name="pe_kind" id="peKind" class="compact-input block w-full border rounded px-2 py-1 text-xs font-bold">
                            <option value="take_cash">Take cash (into shop)</option>
                            <option value="give_cash">Give cash (from shop)</option>
                            <option value="take_gold">Take gold (into stock, party gold −)</option>
                            <option value="give_gold">Give gold (stock −, party gold +)</option>
                        </select>
                    </div>
                    <div id="peCashBlock" class="col-span-8 min-w-0 grid grid-cols-2 gap-1.5">
                        <div class="min-w-0">
                            <label class="block text-[10px] font-bold text-gray-600 uppercase">Amount (₹)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-slate-500"><i class="fas fa-wallet text-[10px]"></i></span>
                                <input type="number" step="0.01" name="cash_amount" id="peCashAmt" class="compact-input block w-full border rounded pl-7 pr-2 py-1 text-xs">
                            </div>
                        </div>
                        <div class="min-w-0">
                            <label class="block text-[10px] font-bold text-gray-600 uppercase">Method</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-slate-400 z-[1]"><i class="fas fa-credit-card text-[10px]"></i></span>
                                <select name="payment_method" id="pePayMeth" class="compact-input block w-full border rounded pl-7 pr-2 py-1 text-xs">
                                    <option value="Cash">Cash</option>
                                    <option value="Bank">Bank</option>
                                    <option value="UPI">UPI</option>
                                    <option value="Cheque">Cheque</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="peGoldBlock" class="col-span-8 min-w-0 hidden grid grid-cols-4 gap-1.5">
                        <div class="min-w-0">
                            <label class="block text-[10px] font-bold text-gray-600 uppercase">Wt (g)</label>
                            <input type="number" step="0.001" name="gold_weight" id="peGoldW" class="compact-input block w-full border rounded px-1 py-1 text-xs" title="Weight (g)">
                        </div>
                        <div class="min-w-0">
                            <label class="block text-[10px] font-bold text-gray-600 uppercase">Purity %</label>
                            <input type="number" step="0.01" name="purity" id="pePurity" class="compact-input block w-full border rounded px-1 py-1 text-xs">
                        </div>
                        <div class="min-w-0">
                            <label class="block text-[10px] font-bold text-gray-600 uppercase">Rate</label>
                            <input type="number" step="0.01" name="gold_rate" id="peGoldRate" class="compact-input block w-full border rounded px-1 py-1 text-xs" value="0" title="₹/g">
                        </div>
                        <div class="min-w-0">
                            <label class="block text-[10px] font-bold text-gray-600 uppercase">Stock</label>
                            <select name="stock_name" id="peStockName" class="compact-input block w-full border rounded px-1 py-1 text-xs truncate max-w-full" title="Optional; match by purity if empty">
                                <option value="">— Purity —</option>
                                <?php foreach ($stock_rows as $sr): ?>
                                <option value="<?= htmlspecialchars($sr['stock_name']) ?>" data-purity="<?= htmlspecialchars((string)$sr['purity']) ?>">
                                    <?= htmlspecialchars($sr['stock_name'] ?: 'Stock') ?> · <?= htmlspecialchars((string)$sr['purity']) ?>%
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-span-12">
                        <label class="block text-[10px] font-bold text-gray-600 uppercase">Notes</label>
                        <textarea name="narration" rows="2" class="compact-input block w-full border rounded px-2 py-1 text-xs" placeholder="Optional"></textarea>
                    </div>
                </div>
                </div>
                <p class="text-[8px] text-slate-500 leading-tight pt-0.5">Keyboard: <b>Enter</b> next field · <b>Shift+Enter</b> previous · <b>Tab</b> as usual</p>
                <div class="flex justify-end gap-2 pt-1 flex-wrap">
                    <button type="button" id="peReset" class="px-3 py-1 text-xs border rounded text-gray-700">Reset</button>
                    <button type="button" id="peDeleteEditedBtn" class="hidden px-3 py-1 text-xs border border-red-300 text-red-700 rounded hover:bg-red-50"><i class="fas fa-trash mr-1"></i>Delete</button>
                    <button type="submit" id="peSubmit" class="px-4 py-1.5 text-xs font-bold bg-slate-700 text-white rounded hover:bg-slate-800">
                        <i class="fas fa-save mr-1"></i>Save
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow border border-gray-200 lg:flex-[0.9]">
            <div class="bg-slate-100 px-3 py-1.5 border-b border-slate-200 rounded-t-lg">
                <h2 class="text-xs font-bold text-slate-800"><i class="fas fa-list mr-1"></i> Recent</h2>
            </div>
            <div class="p-2 overflow-x-auto max-h-[28rem] overflow-y-auto">
                <table class="w-full text-[11px]">
                    <thead><tr class="border-b text-left text-slate-600">
                        <th class="py-1">ID / Date</th><th>Party</th><th>Type</th><th>Detail</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php if ($recent_res && $recent_res->num_rows > 0):
                            while ($r = $recent_res->fetch_assoc()):
                                $lbl = $r['booking_type'] ?? '';
                                $detail = '';
                                if (strpos($lbl, 'Cash') !== false) {
                                    $detail = '₹' . number_format((float)$r['payment_amount'], 2) . ' · ' . htmlspecialchars($r['payment_method'] ?? '');
                                } else {
                                    $detail = number_format((float)$r['gold_weight'], 3) . 'g @ ' . number_format((float)$r['purity'], 2) . '%';
                                }
                            ?>
                        <tr class="border-b border-gray-100 hover:bg-slate-50">
                            <td class="py-1 font-mono font-semibold"><?= htmlspecialchars($r['receipt_id']) ?><br><span class="text-gray-500 font-normal"><?= date('d M Y', strtotime($r['date_of_transaction'])) ?></span></td>
                            <td><?= htmlspecialchars($r['party_name'] ?? '') ?></td>
                            <td><span class="text-[10px] bg-amber-100 text-amber-900 px-1 rounded"><?= htmlspecialchars($lbl) ?></span></td>
                            <td><?= $detail ?></td>
                            <td class="whitespace-nowrap">
                                <button type="button" class="text-slate-700 pe-edit hover:underline mr-2" data-id="<?= (int)$r['id'] ?>">Edit</button>
                                <button type="button" class="text-red-600 pe-del hover:underline" data-id="<?= (int)$r['id'] ?>">Del</button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">No entries</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
        row.className = 'px-3 py-2 hover:bg-emerald-50 cursor-pointer transition-colors border-t-2 border-emerald-200 bg-emerald-50/80';
        row.innerHTML = '<div class="flex items-center gap-2"><i class="fas fa-plus-circle text-emerald-600"></i>' +
            '<div class="font-semibold text-[11px] text-emerald-800">Create new party &quot;' + escPePartyHtml(term) + '&quot;</div></div>';
        row.addEventListener('mousedown', function (e) { e.preventDefault(); });
        row.addEventListener('click', function (e) {
            e.stopPropagation();
            $(partyListEl).addClass('hidden');
            showAddPartyModal(term);
        });
        partyListEl.appendChild(row);
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
    function refreshKindUi() {
        var k = $('#peKind').val();
        var cash = (k === 'take_cash' || k === 'give_cash');
        $('#peCashBlock').toggleClass('hidden', !cash);
        $('#peGoldBlock').toggleClass('hidden', cash);
        if (cash) { $('#peGoldW, #pePurity').prop('required', false); $('#peCashAmt').prop('required', true); }
        else { $('#peCashAmt').prop('required', false); $('#peGoldW, #pePurity').prop('required', true); }
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
        $('#peSubmit').html('<i class="fas fa-save mr-1"></i>Save').removeClass('bg-orange-600 hover:bg-orange-700').addClass('bg-slate-700 hover:bg-slate-800');
    }
    function enterEditMode() {
        $('#peEditModeIndicator').removeClass('hidden');
        $('#peDeleteEditedBtn').removeClass('hidden');
        $('#peFormCard').css('border', '2px solid #f97316');
        $('#peSubmit').html('<i class="fas fa-save mr-1"></i>Update').removeClass('bg-slate-700 hover:bg-slate-800').addClass('bg-orange-600 hover:bg-orange-700');
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
                fieldOrder: ['peReceiptId', 'date_of_transaction', 'pePartyName', 'pe_kind', 'cash_amount', 'payment_method', 'gold_weight', 'purity', 'gold_rate', 'stock_name', 'narration'],
                skipFields: [],
                submitButtonId: 'peSubmit',
                formName: 'personal_expense'
            });
            window.KeyboardNavigation = KeyboardNavigationGeneric;
        }
        $('#peKind').on('change', refreshKindUi);
        $('#peStockName').on('change', function () {
            var p = $(this).find(':selected').data('purity');
            if (p) { $('#pePurity').val(String(p)); }
        });
        $('#showPeListBtn, #peReceiptId').on('click', function (e) {
            e.preventDefault();
            showPeReceiptList();
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#peReceiptList, #showPeListBtn, #peReceiptId').length) { $('#peReceiptList').addClass('hidden'); }
            if (!$(e.target).closest('#pePartyName, #pePartyList').length) { $('#pePartyList').addClass('hidden'); }
        });
        $('#peAddPartyBtn').on('click', function (e) {
            e.preventDefault();
            showAddPartyModal(($('#pePartyName').val() || '').trim());
        });
        var peTimer;
        $('#pePartyName').on('input', function () {
            clearTimeout(peTimer);
            var term = $(this).val();
            if (term !== selectedPePartyName) {
                selectedPePartyName = '';
                $('#pePartyId').val('');
            }
            var v = term.trim();
            if (v.length < 1) {
                $('#pePartyList').empty().addClass('hidden');
                $('#pePartyInfoSection').addClass('hidden');
                return;
            }
            peTimer = setTimeout(function () {
                $.post('', { action: 'search_parties', term: v }, function (rows) {
                    var listEl = document.getElementById('pePartyList');
                    var $l = $(listEl).empty();
                    if (!rows || !rows.length) {
                        appendPeCreatePartyRow(listEl, v);
                        $l.removeClass('hidden');
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
                        item.className = 'px-3 py-2 hover:bg-amber-50/90 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors pe-party-item';
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
                            var pid = parseInt(item.getAttribute('data-id'), 10) || 0;
                            var pnm = item.getAttribute('data-name') || '';
                            $('#pePartyName').val(pnm);
                            $('#pePartyId').val(pid > 0 ? String(pid) : '');
                            selectedPePartyName = pnm;
                            $l.addClass('hidden');
                            if (window.KeyboardNavigation && typeof window.KeyboardNavigation.clearValidationError === 'function') {
                                window.KeyboardNavigation.clearValidationError('pePartyName');
                            }
                            if (pid > 0) {
                                $.post('', { action: 'get_party_info', party_id: pid }, function (info) { renderPePartyInfo(info); }, 'json');
                            } else { $('#pePartyInfoSection').addClass('hidden'); }
                        });
                        listEl.appendChild(item);
                    });
                    appendPeCreatePartyRow(listEl, v);
                    $l.removeClass('hidden');
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
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/components/layout.php';

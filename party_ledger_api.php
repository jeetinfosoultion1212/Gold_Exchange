<?php

/**
 * Party Ledger — AJAX API.
 *
 * All backend actions for party_ledger.php live here. Every action that
 * needs balance/summary numbers delegates to helpers/party_ledger_helper.php
 * so the on-screen ledger, the Pay tab, and the PDF export can never drift
 * apart. See that file for the balance model.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/party_ledger_helper.php';

if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

$company_id = (int) $_SESSION['company_id'];
$user_id = (int) $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'User';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

switch ($_POST['action']) {
    case 'get_party_ledger':
        $party_id = intval($_POST['party_id'] ?? 0);
        if ($party_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid party']);
            exit;
        }

        try {
            echo json_encode(party_ledger_fetch_full($conn, $company_id, $party_id));
        } catch (RuntimeException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;

    case 'save_party':
        $party_name = trim((string) ($_POST['party_name'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $contact_no = trim((string) ($_POST['contact_no'] ?? ''));
        $cash_balance = floatval($_POST['cash_balance'] ?? 0);
        $bank_balance = floatval($_POST['bank_balance'] ?? 0);
        $gold_balance = floatval($_POST['current_gold_balance'] ?? $_POST['gold_balance'] ?? 0);

        if ($party_name === '') {
            echo json_encode(['status' => 'error', 'message' => 'Party name is required']);
            exit;
        }

        $stmt = $conn->prepare(
            'INSERT INTO parties (company_id, party_name, address, contact_no, cash_balance, bank_balance, gold_balance) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssddd', $company_id, $party_name, $address, $contact_no, $cash_balance, $bank_balance, $gold_balance);

        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Party added successfully',
                'party_id' => $stmt->insert_id,
                'party_name' => $party_name,
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error adding party: ' . $stmt->error]);
        }
        exit;

    case 'update_party':
        $party_id = intval($_POST['party_id'] ?? 0);
        $party_name = trim((string) ($_POST['party_name'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $contact_no = trim((string) ($_POST['contact_no'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $state = trim((string) ($_POST['state'] ?? ''));
        $gstin = trim((string) ($_POST['gstin'] ?? ''));
        $bank_name = trim((string) ($_POST['bank_name'] ?? ''));
        $account_no = trim((string) ($_POST['account_no'] ?? ''));
        $ifsc_code = trim((string) ($_POST['ifsc_code'] ?? ''));
        $cash_balance = floatval($_POST['cash_balance'] ?? 0);
        $bank_balance = floatval($_POST['bank_balance'] ?? 0);
        $gold_balance = floatval($_POST['current_gold_balance'] ?? $_POST['gold_balance'] ?? 0);

        if ($party_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid party']);
            exit;
        }
        if ($party_name === '') {
            echo json_encode(['status' => 'error', 'message' => 'Party name is required']);
            exit;
        }

        $stmt = $conn->prepare(
            'UPDATE parties SET party_name = ?, address = ?, contact_no = ?, city = ?, state = ?, gstin = ?, bank_name = ?, account_no = ?, ifsc_code = ?, cash_balance = ?, bank_balance = ?, gold_balance = ? WHERE id = ? AND company_id = ?'
        );
        $stmt->bind_param(
            'sssssssssdddii',
            $party_name,
            $address,
            $contact_no,
            $city,
            $state,
            $gstin,
            $bank_name,
            $account_no,
            $ifsc_code,
            $cash_balance,
            $bank_balance,
            $gold_balance,
            $party_id,
            $company_id
        );

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Party updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error updating party: ' . $stmt->error]);
        }
        exit;

    case 'cut_vow':
        $sale_transaction_id = intval($_POST['sale_transaction_id'] ?? 0);
        $rate = floatval($_POST['rate'] ?? 0);

        if ($sale_transaction_id <= 0 || $rate <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid transaction ID or rate']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $saleStmt = $conn->prepare(
                "SELECT * FROM transactions WHERE id = ? AND company_id = ? AND transaction_type = 'Sale'"
            );
            $saleStmt->bind_param('ii', $sale_transaction_id, $company_id);
            $saleStmt->execute();
            $sale_trans = $saleStmt->get_result()->fetch_assoc();

            if (!$sale_trans) {
                throw new RuntimeException('Sale transaction not found');
            }

            $party_id = (int) $sale_trans['party_id'];
            $gold_weight = floatval($sale_trans['gold_weight']);
            $sale_receipt_id = (string) $sale_trans['receipt_id'];
            $total_amount = $gold_weight * $rate;

            // Advance payment for this sale is recorded as a linked Received row whose
            // narration references the sale's own receipt_id (see sales.php: "Cash for
            // {receipt}" / "Bank for {receipt}") — match on that instead of date alone,
            // so two same-day sales for the same party can't be confused.
            $paymentStmt = $conn->prepare(
                "SELECT * FROM transactions
                 WHERE party_id = ? AND company_id = ?
                   AND transaction_type IN ('Received', 'Payment')
                   AND payment_type = 'Payment_In'
                   AND payment_amount > 0
                   AND narration LIKE CONCAT('%', ?, '%')
                 ORDER BY id DESC LIMIT 1"
            );
            $paymentStmt->bind_param('iis', $party_id, $company_id, $sale_receipt_id);
            $paymentStmt->execute();
            $payment_trans = $paymentStmt->get_result()->fetch_assoc();

            $payment_amount = 0.0;
            $payment_method = 'Cash';
            if ($payment_trans) {
                $payment_amount = floatval($payment_trans['payment_amount']);
                $payment_method = $payment_trans['payment_method'] ?? 'Cash';
            }

            $booking_type = $sale_trans['booking_type'] ?: $payment_method;
            if (empty($booking_type)) {
                $booking_type = 'Cash';
            }

            // The advance payment already reduced the balance when it was received;
            // cutting the vow only needs to ADD the now-known sale amount.
            $cash_adjustment = 0.0;
            $bank_adjustment = 0.0;
            if (strcasecmp((string) $booking_type, 'Cash') === 0) {
                $cash_adjustment = $total_amount;
            } else {
                $bank_adjustment = $total_amount;
            }

            $updateSaleStmt = $conn->prepare(
                'UPDATE transactions SET rate = ?, gold_amount = ?, booking_type = ?, updated_at = NOW() WHERE id = ?'
            );
            $updateSaleStmt->bind_param('ddsi', $rate, $total_amount, $booking_type, $sale_transaction_id);
            if (!$updateSaleStmt->execute()) {
                throw new RuntimeException('Error updating sale transaction: ' . $updateSaleStmt->error);
            }

            $updatePartyStmt = $conn->prepare(
                'UPDATE parties SET cash_balance = cash_balance + ?, bank_balance = bank_balance + ? WHERE id = ? AND company_id = ?'
            );
            $updatePartyStmt->bind_param('ddii', $cash_adjustment, $bank_adjustment, $party_id, $company_id);
            if (!$updatePartyStmt->execute()) {
                throw new RuntimeException('Error updating party balances: ' . $updatePartyStmt->error);
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Vow cut successfully. Rate applied and balances updated.',
                'total_amount' => $total_amount,
                'payment_reversed' => $payment_amount,
                'net_adjustment' => $cash_adjustment + $bank_adjustment,
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Error cutting vow: ' . $e->getMessage()]);
        }
        exit;

    case 'search_parties':
        $search = trim((string) ($_POST['term'] ?? ''));

        $sql = "SELECT p.id, p.party_name, p.address, p.contact_no,
                       p.cash_balance, p.bank_balance, p.gold_balance, p.silver_balance
                FROM parties p
                WHERE p.company_id = ?" . ($search !== '' ? ' AND p.party_name LIKE ?' : '') . "
                ORDER BY p.party_name
                LIMIT 50";
        $stmt = $conn->prepare($sql);
        if ($search !== '') {
            $likeTerm = '%' . $search . '%';
            $stmt->bind_param('is', $company_id, $likeTerm);
        } else {
            $stmt->bind_param('i', $company_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $parties = [];
        while ($row = $result->fetch_assoc()) {
            $cb = floatval($row['cash_balance'] ?? 0);
            $bb = floatval($row['bank_balance'] ?? 0);
            $parties[] = [
                'id' => $row['id'],
                'party_name' => $row['party_name'],
                'address' => $row['address'],
                'contact_no' => $row['contact_no'],
                'cash_balance' => $cb,
                'bank_balance' => $bb,
                'current_balance' => $cb + $bb,
                'gold_balance' => floatval($row['gold_balance'] ?? 0),
                'silver_balance' => floatval($row['silver_balance'] ?? 0),
            ];
        }
        echo json_encode($parties);
        exit;

    case 'clear_party_balance':
        $party_id = intval($_POST['party_id'] ?? 0);
        if ($party_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid party ID']);
            exit;
        }

        $verifyStmt = $conn->prepare('SELECT party_name FROM parties WHERE id = ? AND company_id = ?');
        $verifyStmt->bind_param('ii', $party_id, $company_id);
        $verifyStmt->execute();
        $party_data = $verifyStmt->get_result()->fetch_assoc();

        if (!$party_data) {
            echo json_encode(['status' => 'error', 'message' => 'Party not found']);
            exit;
        }
        $party_name = $party_data['party_name'];

        $conn->begin_transaction();
        try {
            $updateStmt = $conn->prepare(
                'UPDATE parties SET cash_balance = 0, bank_balance = 0, gold_balance = 0, silver_balance = 0 WHERE id = ? AND company_id = ?'
            );
            $updateStmt->bind_param('ii', $party_id, $company_id);
            if (!$updateStmt->execute()) {
                throw new RuntimeException('Failed to clear balances: ' . $updateStmt->error);
            }

            $receipt_id = 'CLEAR_' . $party_id . '_' . time();
            $narration = "All balances cleared by $user_name on " . date('Y-m-d H:i:s');
            $now = date('Y-m-d H:i:s');

            $adjustmentStmt = $conn->prepare(
                'INSERT INTO transactions (receipt_id, date_of_transaction, party_id, company_id, user_id, transaction_type, narration)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $balanceClearType = 'Balance_Clear';
            $adjustmentStmt->bind_param('ssiiiss', $receipt_id, $now, $party_id, $company_id, $user_id, $balanceClearType, $narration);
            if (!$adjustmentStmt->execute()) {
                throw new RuntimeException('Failed to create adjustment record: ' . $adjustmentStmt->error);
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "All balances cleared for party: $party_name",
                'party_name' => $party_name,
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
        exit;
}

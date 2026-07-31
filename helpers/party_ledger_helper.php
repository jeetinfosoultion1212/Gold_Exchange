<?php

/**
 * Party Ledger — single calculation engine.
 *
 * Every number shown on the Party Ledger page (party_ledger.php + party_ledger.js),
 * in the AJAX API (party_ledger_api.php), and in the PDF export
 * (export_party_ledger_pdf.php) is produced by the functions in this file.
 * There is exactly one place that decides what a transaction does to a
 * party's balance, and exactly one place that formats currency — nothing
 * else should re-implement this logic.
 *
 * Balance model
 * -------------
 * The authoritative "Outstanding Balance" for a party is always
 * `parties.cash_balance + parties.bank_balance` (live columns, updated
 * transactionally by book.php / sales.php / purchase.php / exchange.php /
 * payment_send.php / payment_receipt.php whenever a transaction is saved).
 *
 * The per-transaction "delta" below is only used to show a running balance
 * column / narrative context; it must mirror exactly what each module's
 * save handler does to `parties.cash_balance` / `bank_balance`:
 *
 *   Booking   : +gold_amount
 *   Sale      : -gold_amount + payment_amount
 *   Purchase  : -gold_amount + payment_amount
 *   Exchange  : ±due_amount (sign follows difference_weight; fallback: amount - payment_amount)
 *   Payment   : Payment_In -> -amount, Payment_Out -> +amount
 *   Received  : -amount
 *
 * Linked exchange "PAY-*" rows (created alongside an Exchange transaction to
 * keep Cash/Bank account totals correct) are excluded from delta/list logic
 * because their effect is already reflected in the Exchange row's due_amount.
 */

/** Linked cash/bank row created by exchange save — already reflected on the Exchange transaction. */
function is_linked_exchange_payment(array $trans): bool
{
    $narration = strtolower(trim((string) ($trans['narration'] ?? '')));
    if (str_contains($narration, 'payment for exchange')) {
        return true;
    }

    $receiptId = strtoupper(trim((string) ($trans['receipt_id'] ?? '')));
    return str_starts_with($receiptId, 'PAY-');
}

/** Party ledger total = cash + bank (parties table has no current_balance column). */
function party_ledger_total_balance(array $party): float
{
    return floatval($party['cash_balance'] ?? 0) + floatval($party['bank_balance'] ?? 0);
}

/** Net amount a transaction adds to party balance (matches exchange.php / sales.php / purchase.php / book.php save logic). */
function party_ledger_transaction_balance_delta(array $trans): float
{
    $type = strtoupper(trim((string) ($trans['transaction_type'] ?? '')));

    if (is_linked_exchange_payment($trans)) {
        return 0.0;
    }

    switch ($type) {
        case 'BOOKING':
            return floatval($trans['gold_amount'] ?? 0);

        case 'SALE':
            $delta = -floatval($trans['gold_amount'] ?? 0);
            if (floatval($trans['payment_amount'] ?? 0) > 0) {
                $delta += floatval($trans['payment_amount']);
            }
            return $delta;

        case 'PURCHASE':
            $delta = -floatval($trans['gold_amount'] ?? 0);
            $delta += floatval($trans['payment_amount'] ?? 0);
            return $delta;

        case 'EXCHANGE':
            // due_amount is always stored as a positive "still outstanding" figure; whether it
            // ADDS to or SUBTRACTS from the party's balance depends on who owes whom, tracked by
            // difference_weight's sign (mirrors ge_signed_due_delta() in exchange.php).
            $diffWeight = floatval($trans['difference_weight'] ?? 0);
            $due = (isset($trans['due_amount']) && $trans['due_amount'] !== '' && $trans['due_amount'] !== null)
                ? floatval($trans['due_amount'])
                : (floatval($trans['amount'] ?? 0) - floatval($trans['payment_amount'] ?? 0));
            return $diffWeight >= 0 ? $due : -$due;

        case 'PAYMENT':
            $amt = floatval($trans['payment_amount'] ?? 0);
            if ($amt <= 0) {
                return 0.0;
            }
            $paymentType = (string) ($trans['payment_type'] ?? '');
            if ($paymentType === 'Payment_In') {
                return -$amt;
            }
            return $amt;

        case 'RECEIVED':
            $amt = floatval($trans['payment_amount'] ?? 0);
            return $amt > 0 ? -$amt : 0.0;

        default:
            return 0.0;
    }
}

/** Exchange display amount (full bill before partial pay). */
function party_ledger_exchange_amount(array $trans): float
{
    $amt = floatval($trans['amount'] ?? 0);
    if ($amt > 0) {
        return $amt;
    }
    return floatval($trans['gold_amount'] ?? 0);
}

/** Transactions for ledger lists — hides duplicate exchange payment rows. */
function party_ledger_display_transactions(array $transactions): array
{
    return array_values(array_filter($transactions, static function (array $trans): bool {
        return !is_linked_exchange_payment($trans);
    }));
}

/**
 * All payment rows for the Pay tab (includes linked exchange PAY-* rows and exchange inline payments).
 *
 * @param array<int, array<string, mixed>> $transactions
 * @return array<int, array<string, mixed>>
 */
function party_ledger_payment_transactions(array $transactions): array
{
    $linkedExchangeReceipts = [];
    foreach ($transactions as $trans) {
        if (!is_linked_exchange_payment($trans)) {
            continue;
        }
        if (preg_match('/Payment for Exchange\s+(\S+)/i', (string) ($trans['narration'] ?? ''), $matches)) {
            $linkedExchangeReceipts[$matches[1]] = true;
        }
    }

    $payments = [];
    foreach ($transactions as $trans) {
        $type = (string) ($trans['transaction_type'] ?? '');
        $amount = floatval($trans['payment_amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }

        if ($type === 'Payment' || $type === 'Received') {
            $payments[] = $trans;
            continue;
        }

        if ($type === 'Exchange') {
            $receiptId = (string) ($trans['receipt_id'] ?? '');
            if ($receiptId !== '' && !empty($linkedExchangeReceipts[$receiptId])) {
                continue;
            }
            $row = $trans;
            $row['transaction_type'] = 'Payment';
            $narration = trim((string) ($trans['narration'] ?? ''));
            $row['narration'] = $narration !== '' ? $narration : ('Payment on Exchange ' . $receiptId);
            $payments[] = $row;
        }
    }

    usort($payments, static function (array $a, array $b): int {
        $dateCmp = strcmp((string) ($b['date_of_transaction'] ?? ''), (string) ($a['date_of_transaction'] ?? ''));
        if ($dateCmp !== 0) {
            return $dateCmp;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });

    return $payments;
}

/** Running balance after each row (ascending date order). */
function party_ledger_running_balances(array $transactions): array
{
    $balance = 0.0;
    $map = [];
    foreach ($transactions as $trans) {
        $balance += party_ledger_transaction_balance_delta($trans);
        $map[(int) ($trans['id'] ?? 0)] = $balance;
    }
    return $map;
}

/**
 * Single Indian-currency formatter used by every PHP consumer (API JSON already
 * sends raw numbers for JS to format; this is only for PHP-rendered output such
 * as the PDF export).
 */
function party_ledger_format_currency(float $amount): string
{
    $amount = round($amount, 2);
    $negative = $amount < 0;
    $amount = abs($amount);

    $parts = explode('.', number_format($amount, 2, '.', ''));
    $integer = $parts[0];
    $decimal = $parts[1];

    $lastThree = substr($integer, -3);
    $rest = substr($integer, 0, -3);
    if ($rest !== '') {
        $lastThree = ',' . $lastThree;
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
    }

    return ($negative ? '-' : '') . $rest . $lastThree . '.' . $decimal;
}

/**
 * Build the merged Exchange line-item list for a party: exchange_items rows
 * (multi-item exchanges) plus a synthetic received/issued row for legacy
 * single-row Exchange transactions that never got exchange_items rows.
 *
 * @param array<int, array<string, mixed>> $transactions
 * @return array<int, array<string, mixed>>
 */
function party_ledger_fetch_exchange_line_items(mysqli $conn, int $company_id, array $transactions): array
{
    $exchangeLineItems = [];
    $exIds = [];
    foreach ($transactions as $t) {
        if (($t['transaction_type'] ?? '') === 'Exchange') {
            $exIds[(int) $t['id']] = true;
        }
    }

    $idList = array_keys($exIds);
    if (!empty($idList)) {
        $idIn = implode(',', array_map('intval', $idList));
        $sql = "SELECT ei.id AS exchange_item_id, ei.transaction_id, ei.item_type, ei.weight, ei.purity, ei.fine_weight,
                       t.receipt_id, t.date_of_transaction, t.amount, t.gold_amount, t.payment_amount, t.payment_method, t.rate, t.difference_weight
                FROM exchange_items ei
                INNER JOIN transactions t ON t.id = ei.transaction_id AND t.company_id = ei.company_id
                WHERE ei.company_id = " . (int) $company_id . " AND ei.transaction_id IN ($idIn)
                ORDER BY t.date_of_transaction DESC, ei.transaction_id ASC, FIELD(ei.item_type,'received','issued'), ei.id ASC";
        if ($res = $conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $exchangeLineItems[] = $row;
            }
        }
    }

    $txnHasEi = [];
    foreach ($exchangeLineItems as $row) {
        $txnHasEi[(int) $row['transaction_id']] = true;
    }

    foreach ($transactions as $t) {
        if (($t['transaction_type'] ?? '') !== 'Exchange' || !empty($txnHasEi[(int) $t['id']])) {
            continue;
        }
        $tid = (int) $t['id'];
        $recvW = floatval($t['received_weight'] ?? 0);
        $fineW = floatval($t['fine_weight'] ?? 0);
        $amt = party_ledger_exchange_amount($t);

        if ($recvW > 0 || $fineW > 0) {
            $exchangeLineItems[] = [
                'exchange_item_id' => 0,
                'transaction_id' => $tid,
                'item_type' => 'received',
                'weight' => $recvW > 0 ? $recvW : null,
                'purity' => $t['purity'] ?? null,
                'fine_weight' => $fineW > 0 ? $fineW : null,
                'receipt_id' => $t['receipt_id'] ?? '',
                'date_of_transaction' => $t['date_of_transaction'] ?? '',
                'amount' => $amt,
                'gold_amount' => $t['gold_amount'] ?? 0,
                'payment_amount' => $t['payment_amount'] ?? 0,
                'payment_method' => $t['payment_method'] ?? null,
                'rate' => $t['rate'] ?? null,
                'difference_weight' => $t['difference_weight'] ?? null,
            ];
        }
    }

    $txnHasIssued = [];
    foreach ($exchangeLineItems as $row) {
        if (($row['item_type'] ?? '') === 'issued') {
            $txnHasIssued[(int) $row['transaction_id']] = true;
        }
    }

    foreach ($transactions as $t) {
        if (($t['transaction_type'] ?? '') !== 'Exchange') {
            continue;
        }
        $tid = (int) $t['id'];
        if (!empty($txnHasIssued[$tid])) {
            continue;
        }
        $delW = floatval($t['delivered_weight'] ?? 0);
        if ($delW <= 0) {
            continue;
        }
        $exchangeLineItems[] = [
            'exchange_item_id' => 0,
            'transaction_id' => $tid,
            'item_type' => 'issued',
            'weight' => $delW,
            'purity' => 100,
            'fine_weight' => $delW,
            'receipt_id' => $t['receipt_id'] ?? '',
            'date_of_transaction' => $t['date_of_transaction'] ?? '',
            'amount' => party_ledger_exchange_amount($t),
            'gold_amount' => $t['gold_amount'] ?? 0,
            'payment_amount' => $t['payment_amount'] ?? 0,
            'payment_method' => $t['payment_method'] ?? null,
            'rate' => $t['rate'] ?? null,
            'difference_weight' => $t['difference_weight'] ?? null,
        ];
    }

    usort($exchangeLineItems, static function ($a, $b) {
        return strcmp($b['date_of_transaction'] ?? '', $a['date_of_transaction'] ?? '');
    });

    return $exchangeLineItems;
}

/**
 * Build the merged Sale line-item list: gold_sale_items rows (multi-item
 * sales) plus a synthetic row for legacy single-row Sale transactions.
 *
 * @return array<int, array<string, mixed>>
 */
function party_ledger_fetch_sale_line_items(mysqli $conn, int $company_id, int $party_id, array $transactions): array
{
    $saleLineItems = [];
    $sql = "SELECT gsi.id AS sale_item_id, gsi.transaction_id, gsi.receipt_id, gsi.stock_name, gsi.gold_weight, gsi.purity, gsi.fine_weight, gsi.rate, gsi.amount,
                   t.date_of_transaction, t.receipt_method, t.mode, t.booking_type,
                   t.taxable_amount, t.total_gst, t.gold_amount AS txn_gold_amount
            FROM gold_sale_items gsi
            INNER JOIN transactions t ON t.id = gsi.transaction_id AND t.company_id = gsi.company_id
            WHERE gsi.company_id = " . (int) $company_id . " AND t.party_id = " . (int) $party_id . " AND t.transaction_type = 'Sale'
            ORDER BY t.date_of_transaction DESC, gsi.id ASC";
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $saleLineItems[] = $row;
        }
    }

    $txnWithItems = [];
    foreach ($saleLineItems as $row) {
        $txnWithItems[(int) $row['transaction_id']] = true;
    }

    foreach ($transactions as $t) {
        if (($t['transaction_type'] ?? '') !== 'Sale') {
            continue;
        }
        $tid = (int) $t['id'];
        if (!empty($txnWithItems[$tid])) {
            continue;
        }
        $saleLineItems[] = [
            'sale_item_id' => 0,
            'transaction_id' => $tid,
            'receipt_id' => $t['receipt_id'] ?? '',
            'stock_name' => '',
            'gold_weight' => $t['gold_weight'] ?? 0,
            'purity' => $t['purity'] ?? null,
            'fine_weight' => $t['fine_weight'] ?? null,
            'rate' => $t['rate'] ?? null,
            'amount' => floatval($t['gold_amount'] ?? 0) ?: floatval($t['amount'] ?? 0),
            'date_of_transaction' => $t['date_of_transaction'] ?? '',
            'receipt_method' => $t['receipt_method'] ?? null,
            'mode' => $t['mode'] ?? null,
            'booking_type' => $t['booking_type'] ?? null,
            'taxable_amount' => $t['taxable_amount'] ?? null,
            'total_gst' => $t['total_gst'] ?? null,
            'txn_gold_amount' => $t['gold_amount'] ?? null,
        ];
    }

    usort($saleLineItems, static function ($a, $b) {
        return strcmp($b['date_of_transaction'] ?? '', $a['date_of_transaction'] ?? '');
    });

    return $saleLineItems;
}

/**
 * Build the merged Purchase line-item list: gold_purchase_items rows
 * (multi-item purchases) plus a synthetic row for legacy single-row
 * Purchase transactions. Mirrors party_ledger_fetch_sale_line_items().
 *
 * @return array<int, array<string, mixed>>
 */
function party_ledger_fetch_purchase_line_items(mysqli $conn, int $company_id, int $party_id, array $transactions): array
{
    $purchaseLineItems = [];
    $tableCheck = $conn->query("SHOW TABLES LIKE 'gold_purchase_items'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $sql = "SELECT gpi.id AS purchase_item_id, gpi.transaction_id, gpi.receipt_id, gpi.stock_name, gpi.gold_weight, gpi.purity, gpi.fine_weight, gpi.rate, gpi.amount,
                       t.date_of_transaction, t.payment_method, t.mode,
                       t.taxable_amount, t.total_gst, t.gold_amount AS txn_gold_amount, t.payment_amount
                FROM gold_purchase_items gpi
                INNER JOIN transactions t ON t.id = gpi.transaction_id AND t.company_id = gpi.company_id
                WHERE gpi.company_id = " . (int) $company_id . " AND t.party_id = " . (int) $party_id . " AND t.transaction_type = 'Purchase'
                ORDER BY t.date_of_transaction DESC, gpi.id ASC";
        if ($res = $conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $purchaseLineItems[] = $row;
            }
        }
    }

    $txnWithItems = [];
    foreach ($purchaseLineItems as $row) {
        $txnWithItems[(int) $row['transaction_id']] = true;
    }

    foreach ($transactions as $t) {
        if (($t['transaction_type'] ?? '') !== 'Purchase') {
            continue;
        }
        $tid = (int) $t['id'];
        if (!empty($txnWithItems[$tid])) {
            continue;
        }
        $purchaseLineItems[] = [
            'purchase_item_id' => 0,
            'transaction_id' => $tid,
            'receipt_id' => $t['receipt_id'] ?? '',
            'stock_name' => '',
            'gold_weight' => $t['gold_weight'] ?? 0,
            'purity' => $t['purity'] ?? null,
            'fine_weight' => $t['fine_weight'] ?? null,
            'rate' => $t['rate'] ?? null,
            'amount' => floatval($t['gold_amount'] ?? 0) ?: floatval($t['amount'] ?? 0),
            'date_of_transaction' => $t['date_of_transaction'] ?? '',
            'payment_method' => $t['payment_method'] ?? null,
            'mode' => $t['mode'] ?? null,
            'taxable_amount' => $t['taxable_amount'] ?? null,
            'total_gst' => $t['total_gst'] ?? null,
            'txn_gold_amount' => $t['gold_amount'] ?? null,
            'payment_amount' => $t['payment_amount'] ?? 0,
        ];
    }

    usort($purchaseLineItems, static function ($a, $b) {
        return strcmp($b['date_of_transaction'] ?? '', $a['date_of_transaction'] ?? '');
    });

    return $purchaseLineItems;
}

/**
 * Build every summary figure shown on the ledger (cards + PDF) from one pass
 * over the party's raw transactions. This is the ONLY place that aggregates
 * Booked / Sold / Purchased / Received / Exchange totals.
 *
 * @param array<int, array<string, mixed>> $transactions
 * @return array<string, float>
 */
function party_ledger_build_summary(array $transactions, array $party_data): array
{
    $booked_weight = 0.0;
    $booked_weight_cash = 0.0;
    $booked_weight_bank = 0.0;
    $booked_amount = 0.0;

    $sold_weight = 0.0;
    $sold_weight_cash = 0.0;
    $sold_weight_bank = 0.0;
    $sold_amount = 0.0;

    $purchased_weight = 0.0;
    $purchased_weight_cash = 0.0;
    $purchased_weight_bank = 0.0;
    $purchased_amount = 0.0;
    $purchased_cash_paid = 0.0;
    $purchased_bank_paid = 0.0;

    $cash_received = 0.0;
    $bank_received = 0.0;
    $total_paid_out = 0.0;

    $gold_received_weight = 0.0;
    $gold_issued_weight = 0.0;

    foreach ($transactions as $trans) {
        $type = (string) ($trans['transaction_type'] ?? '');

        switch ($type) {
            case 'Booking':
                $w = floatval($trans['gold_weight'] ?? 0);
                $booked_weight += $w;
                $booked_amount += floatval($trans['gold_amount'] ?? 0);
                $bookingType = $trans['booking_type'] ?? '';
                if (strcasecmp((string) $bookingType, 'Bank') === 0) {
                    $booked_weight_bank += $w;
                } else {
                    $booked_weight_cash += $w;
                }
                break;

            case 'Sale':
                $w = floatval($trans['gold_weight'] ?? 0);
                $sold_weight += $w;
                $sold_amount += floatval($trans['gold_amount'] ?? 0);
                $saleChannel = $trans['booking_type'] ?? ($trans['receipt_method'] ?? ($trans['mode'] ?? 'Cash'));
                if (strcasecmp((string) $saleChannel, 'Bank') === 0) {
                    $sold_weight_bank += $w;
                } else {
                    $sold_weight_cash += $w;
                }
                break;

            case 'Purchase':
                $w = floatval($trans['gold_weight'] ?? 0);
                $amt = floatval($trans['gold_amount'] ?? 0);
                $paid = floatval($trans['payment_amount'] ?? 0);
                $purchased_weight += $w;
                $purchased_amount += $amt;
                if (strcasecmp((string) ($trans['payment_method'] ?? 'Cash'), 'Cash') === 0) {
                    $purchased_weight_cash += $w;
                    $purchased_cash_paid += $paid;
                } else {
                    $purchased_weight_bank += $w;
                    $purchased_bank_paid += $paid;
                }
                break;

            case 'Payment':
                $amt = floatval($trans['payment_amount'] ?? 0);
                if ($amt <= 0) {
                    break;
                }
                if (($trans['payment_type'] ?? '') === 'Payment_In') {
                    if (empty($trans['payment_method']) || strcasecmp((string) $trans['payment_method'], 'Cash') === 0) {
                        $cash_received += $amt;
                    } else {
                        $bank_received += $amt;
                    }
                } else {
                    $total_paid_out += $amt;
                }
                break;

            case 'Received':
                $amt = floatval($trans['payment_amount'] ?? 0);
                if ($amt > 0) {
                    if (empty($trans['payment_method']) || strcasecmp((string) $trans['payment_method'], 'Cash') === 0) {
                        $cash_received += $amt;
                    } else {
                        $bank_received += $amt;
                    }
                }
                break;

            case 'Exchange':
                $gold_received_weight += floatval($trans['received_weight'] ?? 0);
                $gold_issued_weight += floatval($trans['delivered_weight'] ?? 0);
                break;
        }
    }

    $total_received = $cash_received + $bank_received;
    $total_purchase_paid = $purchased_cash_paid + $purchased_bank_paid;

    return [
        'booked_weight' => $booked_weight,
        'booked_weight_cash' => $booked_weight_cash,
        'booked_weight_bank' => $booked_weight_bank,
        'booked_amount' => $booked_amount,

        'sold_weight' => $sold_weight,
        'sold_weight_cash' => $sold_weight_cash,
        'sold_weight_bank' => $sold_weight_bank,
        'sold_amount' => $sold_amount,
        'remaining_weight' => $booked_weight - $sold_weight,
        'remaining_weight_cash' => $booked_weight_cash - $sold_weight_cash,
        'remaining_weight_bank' => $booked_weight_bank - $sold_weight_bank,

        'purchased_weight' => $purchased_weight,
        'purchased_weight_cash' => $purchased_weight_cash,
        'purchased_weight_bank' => $purchased_weight_bank,
        'purchased_amount' => $purchased_amount,
        'purchased_cash_paid' => $purchased_cash_paid,
        'purchased_bank_paid' => $purchased_bank_paid,
        'total_purchase_paid' => $total_purchase_paid,

        'cash_received' => $cash_received,
        'bank_received' => $bank_received,
        'total_received' => $total_received,
        'total_paid_out' => $total_paid_out,

        'gold_received_weight' => $gold_received_weight,
        'gold_issued_weight' => $gold_issued_weight,

        // Legacy "booking due" figure (booked minus received) — distinct from the
        // authoritative party balance below; kept for the Bookings tab context.
        'due_amount' => $booked_amount - $total_received,

        // Authoritative outstanding balance — always the live parties columns.
        'cash_balance' => floatval($party_data['cash_balance'] ?? 0),
        'bank_balance' => floatval($party_data['bank_balance'] ?? 0),
        'current_balance' => party_ledger_total_balance($party_data),
        'gold_balance' => floatval($party_data['gold_balance'] ?? 0),
        'silver_balance' => floatval($party_data['silver_balance'] ?? 0),
        'current_gold_balance' => floatval($party_data['gold_balance'] ?? 0),
    ];
}

/**
 * One-call data loader for the Party Ledger page: party info, raw
 * transactions, all three line-item breakdowns, the computed summary, and
 * the two display-ready transaction lists (all-tab list + payments-tab list).
 *
 * @return array<string, mixed>
 * @throws RuntimeException if the party does not belong to this company.
 */
function party_ledger_fetch_full(mysqli $conn, int $company_id, int $party_id): array
{
    $partyStmt = $conn->prepare('SELECT * FROM parties WHERE id = ? AND company_id = ?');
    $partyStmt->bind_param('ii', $party_id, $company_id);
    $partyStmt->execute();
    $party = $partyStmt->get_result()->fetch_assoc();

    if (!$party) {
        throw new RuntimeException('Party not found');
    }

    $txnStmt = $conn->prepare(
        'SELECT * FROM transactions WHERE party_id = ? AND company_id = ? ORDER BY date_of_transaction DESC, id DESC'
    );
    $txnStmt->bind_param('ii', $party_id, $company_id);
    $txnStmt->execute();
    $result = $txnStmt->get_result();
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }

    $exchangeLineItems = party_ledger_fetch_exchange_line_items($conn, $company_id, $transactions);
    $saleLineItems = party_ledger_fetch_sale_line_items($conn, $company_id, $party_id, $transactions);
    $purchaseLineItems = party_ledger_fetch_purchase_line_items($conn, $company_id, $party_id, $transactions);
    $summary = party_ledger_build_summary($transactions, $party);

    return [
        'status' => 'success',
        'party' => $party,
        'transactions' => party_ledger_display_transactions($transactions),
        'payment_transactions' => party_ledger_payment_transactions($transactions),
        'exchange_line_items' => $exchangeLineItems,
        'sale_line_items' => $saleLineItems,
        'purchase_line_items' => $purchaseLineItems,
        'summary' => $summary,
    ];
}

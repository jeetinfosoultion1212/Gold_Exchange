<?php

/**
 * Report page — Cash/Bank Ledger + Stock Ledger calculation engine.
 *
 * These helpers power the "Cash Ledger" and "Stock Ledger" tabs on report.php.
 * Both use the same trick to stay trustworthy even though this codebase does
 * not keep a fully double-entry transaction log (some stock transfers update
 * `gold_stock.current_stock` directly without a matching logged row):
 *
 *   1. Walk every known movement row for the account/stock, oldest first.
 *   2. Anchor the *closing* balance to the live figure already shown on the
 *      page (account_balances / gold_stock.current_stock).
 *   3. Any gap between "sum of known deltas" and "live balance" is absorbed
 *      into an implicit opening balance instead of silently misreporting
 *      the running total.
 *
 * This guarantees the ledger's closing balance always matches the stat card
 * above it, exactly like a bank passbook reconciled against a known balance.
 */

/**
 * All company-wide cash/bank affecting transactions (all time, ascending),
 * split into 'Cash' and 'Bank' (Bank/UPI/Cheque) buckets.
 *
 * @return array{Cash: array<int, array<string, mixed>>, Bank: array<int, array<string, mixed>>}
 */
function report_ledger_fetch_cash_bank_rows(mysqli $conn, int $company_id): array
{
    $sql = "SELECT t.id, t.receipt_id, t.transaction_type, t.payment_type, t.payment_method, t.payment_amount,
                   t.date_of_transaction, t.narration, p.party_name
            FROM transactions t
            LEFT JOIN parties p ON t.party_id = p.id
            WHERE t.company_id = ?
              AND t.transaction_type IN ('Payment','Received','Purchase')
              AND t.payment_amount > 0
            ORDER BY t.date_of_transaction ASC, t.id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $buckets = ['Cash' => [], 'Bank' => []];
    while ($row = $res->fetch_assoc()) {
        $method = strtoupper(trim((string) ($row['payment_method'] ?? '')));
        $isBank = in_array($method, ['BANK', 'UPI', 'CHEQUE'], true);
        $amt = (float) $row['payment_amount'];
        $type = (string) $row['transaction_type'];

        if ($type === 'Payment') {
            $isIn = ((string) $row['payment_type']) === 'Payment_In';
            $delta = $isIn ? $amt : -$amt;
            $label = $isIn ? 'Payment In' : 'Payment Out';
        } elseif ($type === 'Received') {
            $delta = $amt;
            $label = 'Received';
        } else {
            $delta = -$amt;
            $label = 'Purchase Paid';
        }

        $entry = [
            'id' => (int) $row['id'],
            'receipt_id' => (string) $row['receipt_id'],
            'date' => (string) $row['date_of_transaction'],
            'party_name' => $row['party_name'],
            'label' => $label,
            'narration' => (string) ($row['narration'] ?? ''),
            'delta' => $delta,
        ];
        $buckets[$isBank ? 'Bank' : 'Cash'][] = $entry;
    }
    $stmt->close();

    return $buckets;
}

/**
 * Reduce an all-time ascending row list to a single period view with a
 * running balance anchored to $live_balance (see file docblock).
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array{opening: float, closing: float, rows: array<int, array<string, mixed>>, total_in: float, total_out: float}
 */
function report_ledger_running(array $rows, float $live_balance, string $start_date, string $end_date): array
{
    $total_net = 0.0;
    foreach ($rows as $r) {
        $total_net += $r['delta'];
    }
    $implicit_base = $live_balance - $total_net;

    $running = $implicit_base;
    $opening = $implicit_base;
    $period_rows = [];
    $total_in = 0.0;
    $total_out = 0.0;

    foreach ($rows as $r) {
        $running += $r['delta'];
        $d = substr($r['date'], 0, 10);

        if ($d < $start_date) {
            $opening = $running;
            continue;
        }
        if ($d > $end_date) {
            continue;
        }

        $row = $r;
        $row['balance'] = $running;
        if ($r['delta'] >= 0) {
            $total_in += $r['delta'];
        } else {
            $total_out += -$r['delta'];
        }
        $period_rows[] = $row;
    }

    return [
        'opening' => $opening,
        'closing' => $running,
        'rows' => $period_rows,
        'total_in' => $total_in,
        'total_out' => $total_out,
    ];
}

/**
 * All known weight movements (all time, ascending) for a single gold_stock
 * row: Stock Addition / Reset / Purchase / Sale (matched by purity, and by
 * mode for additions) plus — for MIX and Fine-purity stocks — the linked
 * Exchange received/issued weights (multi-item exchange_items rows first,
 * falling back to legacy single-row Exchange transactions).
 *
 * @param array<string, mixed> $stock gold_stock row (id, category, mode, purity, stock_name)
 * @return array<int, array<string, mixed>>
 */
function report_stock_ledger_fetch_rows(mysqli $conn, int $company_id, array $stock): array
{
    $purity = (float) $stock['purity'];
    $mode = (string) $stock['mode'];
    $category = (string) $stock['category'];
    $is_mix = stripos((string) $stock['stock_name'], 'mix') !== false;
    $is_fine = $purity >= 99.5;
    $rows = [];

    $purity_cond = $is_fine ? '(t.purity >= 99.5 OR t.purity = 100)' : ('t.purity = ' . $purity);
    $sql = "SELECT t.id, t.receipt_id, t.transaction_type, t.gold_weight, t.payment_method,
                   t.date_of_transaction, t.narration, p.party_name
            FROM transactions t
            LEFT JOIN parties p ON t.party_id = p.id
            WHERE t.company_id = ?
              AND t.transaction_type IN ('Stock_Addition','Stock_Reset','Purchase','Sale')
              AND $purity_cond
            ORDER BY t.date_of_transaction ASC, t.id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $type = (string) $row['transaction_type'];
        $w = (float) $row['gold_weight'];
        $reset = false;

        if ($type === 'Stock_Addition') {
            $rowMode = (string) ($row['payment_method'] ?? '');
            if (!$is_mix && $rowMode !== '' && strcasecmp($rowMode, $mode) !== 0) {
                continue;
            }
            $delta = $w;
            $label = 'Stock Added';
        } elseif ($type === 'Stock_Reset') {
            $delta = 0.0;
            $label = 'Stock Reset';
            $reset = true;
        } elseif ($type === 'Purchase') {
            $delta = $w;
            $label = 'Purchase';
        } else {
            $delta = -$w;
            $label = 'Sale';
        }

        $rows[] = [
            'id' => (int) $row['id'],
            'receipt_id' => (string) $row['receipt_id'],
            'date' => (string) $row['date_of_transaction'],
            'party_name' => $row['party_name'],
            'label' => $label,
            'narration' => (string) ($row['narration'] ?? ''),
            'delta' => $delta,
            'reset' => $reset,
        ];
    }
    $stmt->close();

    if ($is_fine || $is_mix) {
        $matField = strcasecmp($category, 'Silver') === 0 ? 'silver' : 'gold';

        if ($is_mix) {
            $sql2 = "SELECT ei.id, ei.transaction_id, ei.weight, t.receipt_id, t.date_of_transaction, t.narration, p.party_name
                     FROM exchange_items ei
                     INNER JOIN transactions t ON t.id = ei.transaction_id AND t.company_id = ei.company_id
                     LEFT JOIN parties p ON p.id = t.party_id
                     WHERE ei.company_id = ? AND t.transaction_type = 'Exchange' AND ei.item_type = 'received'
                       AND LOWER(TRIM(COALESCE(ei.material,'Gold'))) = ?
                     ORDER BY t.date_of_transaction ASC, ei.id ASC";
            $stmt2 = $conn->prepare($sql2);
            $stmt2->bind_param('is', $company_id, $matField);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row = $res2->fetch_assoc()) {
                $rows[] = [
                    'id' => 9000000 + (int) $row['id'],
                    'receipt_id' => (string) $row['receipt_id'],
                    'date' => (string) $row['date_of_transaction'],
                    'party_name' => $row['party_name'],
                    'label' => 'Exchange Received',
                    'narration' => (string) ($row['narration'] ?? ''),
                    'delta' => (float) $row['weight'],
                    'reset' => false,
                ];
            }
            $stmt2->close();

            $sql3 = "SELECT t.id, t.receipt_id, t.date_of_transaction, t.narration, t.received_weight, p.party_name
                     FROM transactions t
                     LEFT JOIN parties p ON p.id = t.party_id
                     WHERE t.company_id = ? AND t.transaction_type = 'Exchange'
                       AND LOWER(TRIM(COALESCE(t.exchange_material,'Gold'))) = ?
                       AND COALESCE(t.received_weight,0) > 0
                       AND NOT EXISTS (SELECT 1 FROM exchange_items ei WHERE ei.transaction_id = t.id AND ei.item_type = 'received')
                     ORDER BY t.date_of_transaction ASC, t.id ASC";
            $stmt3 = $conn->prepare($sql3);
            $stmt3->bind_param('is', $company_id, $matField);
            $stmt3->execute();
            $res3 = $stmt3->get_result();
            while ($row = $res3->fetch_assoc()) {
                $rows[] = [
                    'id' => 8000000 + (int) $row['id'],
                    'receipt_id' => (string) $row['receipt_id'],
                    'date' => (string) $row['date_of_transaction'],
                    'party_name' => $row['party_name'],
                    'label' => 'Exchange Received',
                    'narration' => (string) ($row['narration'] ?? ''),
                    'delta' => (float) $row['received_weight'],
                    'reset' => false,
                ];
            }
            $stmt3->close();
        }

        if ($is_fine) {
            $sql4 = "SELECT t.id, t.receipt_id, t.date_of_transaction, t.narration, t.delivered_weight, p.party_name
                     FROM transactions t
                     LEFT JOIN parties p ON p.id = t.party_id
                     WHERE t.company_id = ? AND t.transaction_type = 'Exchange'
                       AND LOWER(TRIM(COALESCE(t.exchange_material,'Gold'))) = ?
                       AND COALESCE(t.delivered_weight,0) > 0
                     ORDER BY t.date_of_transaction ASC, t.id ASC";
            $stmt4 = $conn->prepare($sql4);
            $stmt4->bind_param('is', $company_id, $matField);
            $stmt4->execute();
            $res4 = $stmt4->get_result();
            while ($row = $res4->fetch_assoc()) {
                $rows[] = [
                    'id' => 7000000 + (int) $row['id'],
                    'receipt_id' => (string) $row['receipt_id'],
                    'date' => (string) $row['date_of_transaction'],
                    'party_name' => $row['party_name'],
                    'label' => 'Exchange Issued',
                    'narration' => (string) ($row['narration'] ?? ''),
                    'delta' => -(float) $row['delivered_weight'],
                    'reset' => false,
                ];
            }
            $stmt4->close();
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $c = strcmp($a['date'], $b['date']);
        return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
    });

    return $rows;
}

/**
 * Reduce an all-time ascending stock movement list to a single period view
 * with a running balance anchored to $live_balance. 'Stock Reset' rows hard
 * set the running balance to 0 at that point in time.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array{opening: float, closing: float, rows: array<int, array<string, mixed>>, total_in: float, total_out: float}
 */
function report_stock_ledger_running(array $rows, float $live_balance, string $start_date, string $end_date): array
{
    $has_reset = false;
    foreach ($rows as $r) {
        if (!empty($r['reset'])) {
            $has_reset = true;
            break;
        }
    }

    $implicit_base = 0.0;
    if (!$has_reset) {
        $total_net = 0.0;
        foreach ($rows as $r) {
            $total_net += $r['delta'];
        }
        $implicit_base = $live_balance - $total_net;
    }

    $running = $implicit_base;
    $opening = $implicit_base;
    $period_rows = [];
    $total_in = 0.0;
    $total_out = 0.0;

    foreach ($rows as $r) {
        if (!empty($r['reset'])) {
            $running = 0.0;
        } else {
            $running += $r['delta'];
        }

        $d = substr($r['date'], 0, 10);
        if ($d < $start_date) {
            $opening = $running;
            continue;
        }
        if ($d > $end_date) {
            continue;
        }

        $row = $r;
        $row['balance'] = $running;
        if (empty($r['reset'])) {
            if ($r['delta'] >= 0) {
                $total_in += $r['delta'];
            } else {
                $total_out += -$r['delta'];
            }
        }
        $period_rows[] = $row;
    }

    return [
        'opening' => $opening,
        'closing' => $running,
        'rows' => $period_rows,
        'total_in' => $total_in,
        'total_out' => $total_out,
    ];
}

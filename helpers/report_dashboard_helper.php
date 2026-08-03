<?php
/**
 * Helper functions for the report.php dashboard redesign.
 * Keeps report_ledger_helper.php focused on ledgers only — this file provides
 * the receivable/payable KPI, top-parties chart, trend charts, and the unified
 * "Recent Transactions" feed data.
 */

/**
 * Gross receivable (parties who owe the shop) and payable (shop owes parties),
 * summed across all parties' current cash + bank balance.
 */
function report_dashboard_receivable_payable(mysqli $conn, int $company_id): array
{
    $sql = "SELECT
                COALESCE(SUM(GREATEST(cash_balance + bank_balance, 0)), 0) AS receivable,
                COALESCE(SUM(GREATEST(-(cash_balance + bank_balance), 0)), 0) AS payable
            FROM parties
            WHERE company_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'receivable' => (float) ($row['receivable'] ?? 0),
        'payable' => (float) ($row['payable'] ?? 0),
    ];
}

/**
 * Top parties by absolute outstanding balance (cash + bank), for the
 * horizontal "Top Parties by Outstanding Balance" bar chart.
 */
function report_dashboard_top_parties(mysqli $conn, int $company_id, int $limit = 10): array
{
    $sql = "SELECT party_name, (cash_balance + bank_balance) AS net_balance
            FROM parties
            WHERE company_id = ?
              AND ABS(cash_balance + bank_balance) > 0.0005
            ORDER BY ABS(cash_balance + bank_balance) DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $company_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'party_name' => (string) $row['party_name'],
            'balance' => (float) $row['net_balance'],
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Fills in zero-value rows for any day in [start_date, end_date] missing from
 * $rows, so trend line/bar charts don't show misleading gaps. No-ops (returns
 * $rows unchanged) for invalid or overly large ranges (> 366 days).
 */
function report_dashboard_fill_days(array $rows, string $start_date, string $end_date, string $date_key, array $value_keys): array
{
    $start = strtotime($start_date);
    $end = strtotime($end_date);
    if ($start === false || $end === false || $end < $start) {
        return $rows;
    }
    $days = (int) round(($end - $start) / 86400) + 1;
    if ($days > 366 || $days < 1) {
        return $rows;
    }

    $byDate = [];
    foreach ($rows as $r) {
        $byDate[(string) $r[$date_key]] = $r;
    }

    $filled = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime("+{$i} day", $start));
        if (isset($byDate[$d])) {
            $filled[] = $byDate[$d];
            continue;
        }
        $blank = [$date_key => $d];
        foreach ($value_keys as $k) {
            $blank[$k] = 0;
        }
        $filled[] = $blank;
    }
    return $filled;
}

/**
 * Per-day Sale vs Purchase amount totals, for the trend line chart.
 * Respects the report's date filter (unlike the ledgers).
 */
function report_dashboard_daily_trend(mysqli $conn, int $company_id, string $start_date, string $end_date): array
{
    $sql = "SELECT DATE(t.date_of_transaction) AS d,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_amount ELSE 0 END), 0) AS sale_amt,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'Purchase' THEN t.gold_amount ELSE 0 END), 0) AS purchase_amt
            FROM transactions t
            WHERE t.company_id = ?
              AND t.transaction_type IN ('Sale', 'Purchase')
              AND DATE(t.date_of_transaction) BETWEEN ? AND ?
            GROUP BY DATE(t.date_of_transaction)
            ORDER BY d ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $company_id, $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'd' => (string) $row['d'],
            'sale_amt' => (float) $row['sale_amt'],
            'purchase_amt' => (float) $row['purchase_amt'],
        ];
    }
    $stmt->close();
    return report_dashboard_fill_days($rows, $start_date, $end_date, 'd', ['sale_amt', 'purchase_amt']);
}

/**
 * Per-day Payment In vs Payment Out totals (Received + Purchase-paid rolled
 * in, same classification used by the Cash/Bank ledger), for the trend chart.
 */
function report_dashboard_payment_trend(mysqli $conn, int $company_id, string $start_date, string $end_date): array
{
    $sql = "SELECT DATE(t.date_of_transaction) AS d,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'Received' OR (t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In') THEN t.payment_amount ELSE 0 END), 0) AS payment_in,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'Purchase' OR (t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out') THEN t.payment_amount ELSE 0 END), 0) AS payment_out
            FROM transactions t
            WHERE t.company_id = ?
              AND t.transaction_type IN ('Received', 'Payment', 'Purchase')
              AND t.payment_amount > 0
              AND DATE(t.date_of_transaction) BETWEEN ? AND ?
            GROUP BY DATE(t.date_of_transaction)
            ORDER BY d ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $company_id, $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'd' => (string) $row['d'],
            'payment_in' => (float) $row['payment_in'],
            'payment_out' => (float) $row['payment_out'],
        ];
    }
    $stmt->close();
    return report_dashboard_fill_days($rows, $start_date, $end_date, 'd', ['payment_in', 'payment_out']);
}

/**
 * Unified, normalized "Recent Transactions" feed — merges what used to be six
 * separate tabs (Booking / Exchange / Sale / Purchase / Received / Payment)
 * into one chronological list. Each row keeps its original type-specific
 * fields (plus a 'type' tag) so the caller can render type-aware columns and
 * the Exchange fine-transfer inline actions.
 */
function report_dashboard_recent_transactions(mysqli $conn, int $company_id, string $start_date, string $end_date): array
{
    $fetch = function (string $sql) use ($conn, $company_id, $start_date, $end_date): array {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('report_dashboard_recent_transactions query failed: ' . $conn->error);
            return [];
        }
        $stmt->bind_param('iss', $company_id, $start_date, $end_date);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    };

    $rows = [];

    foreach ($fetch("
        SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.gold_weight, t.purity, t.rate, t.gold_amount, t.booking_type
        FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.company_id = ? AND t.transaction_type = 'Booking' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ") as $r) {
        $rows[] = array_merge($r, ['type' => 'Booking']);
    }

    foreach ($fetch("
        SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.received_weight, t.purity, t.fine_weight, t.fine_transferred,
               t.delivered_weight, t.difference_weight, t.amount, t.exchange_material
        FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.company_id = ? AND t.transaction_type = 'Exchange' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ") as $r) {
        $rows[] = array_merge($r, ['type' => 'Exchange']);
    }

    foreach ($fetch("
        SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.gold_weight, t.purity, t.rate, t.gold_amount, t.payment_amount,
               COALESCE(t.mode, t.receipt_method, t.booking_type, 'Cash') AS sale_mode,
               (SELECT GROUP_CONCAT(DISTINCT gsi.stock_name ORDER BY gsi.id SEPARATOR ', ') FROM gold_sale_items gsi WHERE gsi.transaction_id = t.id) AS stock_names
        FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.company_id = ? AND t.transaction_type = 'Sale' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ") as $r) {
        $rows[] = array_merge($r, ['type' => 'Sale']);
    }

    foreach ($fetch("
        SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.gold_weight, t.purity, t.rate, t.gold_amount, t.payment_amount, t.payment_method
        FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.company_id = ? AND t.transaction_type = 'Purchase' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ") as $r) {
        $rows[] = array_merge($r, ['type' => 'Purchase']);
    }

    foreach ($fetch("
        SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.payment_amount, t.payment_method, t.narration
        FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.company_id = ? AND t.transaction_type = 'Received' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ") as $r) {
        $rows[] = array_merge($r, ['type' => 'Received']);
    }

    foreach ($fetch("
        SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.payment_amount, t.payment_method, t.payment_type, t.narration
        FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.company_id = ? AND t.transaction_type = 'Payment' AND t.party_id IS NOT NULL AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ") as $r) {
        $rows[] = array_merge($r, ['type' => 'Payment']);
    }

    usort($rows, function ($a, $b) {
        $cmp = strcmp((string) $b['date_of_transaction'], (string) $a['date_of_transaction']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ((int) $b['id']) <=> ((int) $a['id']);
    });

    return $rows;
}

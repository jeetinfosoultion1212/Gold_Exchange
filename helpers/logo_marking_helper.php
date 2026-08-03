<?php

/**
 * Logo Marking helpers.
 * Expects transactions (Logo_Marking), logo_marking_items, parties, master_item_categories,
 * jeweller_product_rates, and account_balances to already exist in the database.
 */

/** Map a Logo_Marking transaction row to the API shape used by the UI. */
function lm_map_transaction_row(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'receipt_id' => $row['receipt_id'] ?? '',
        'request_date' => $row['date_of_transaction'] ?? '',
        'jeweller_id' => (int) ($row['party_id'] ?? 0),
        'mobile' => $row['contact_mobile'] ?? '',
        'logo' => $row['logo'] ?? '',
        'box_no' => $row['box_no'] ?? '',
        'total_amount' => (float) ($row['amount'] ?? $row['gold_amount'] ?? 0),
        'received_amount' => (float) ($row['payment_amount'] ?? 0),
        'payment_method' => $row['payment_method'] ?? 'Cash',
        'status' => $row['payment_status'] ?? 'Pending',
        'jeweller_name' => $row['jeweller_name'] ?? '',
    ];
}

function lm_payment_status_for_amounts(float $total, float $received): string
{
    if (!function_exists('ge_normalize_payment_status')) {
        require_once __DIR__ . '/transaction_helper.php';
    }
    return ge_normalize_payment_status('', $total, $received);
}

/**
 * Detect a recently saved logo marking entry with the same core details (prevents double-save).
 *
 * @return array{id:int,receipt_id:string}|null
 */
function lm_find_duplicate_logo_marking_entry(
    mysqli $conn,
    int $company_id,
    int $jeweller_id,
    string $box_no,
    string $logo,
    float $total_amount,
    int $item_count,
    int $exclude_id = 0,
    int $within_seconds = 180
): ?array {
    if ($jeweller_id <= 0 || $item_count <= 0) {
        return null;
    }

    $box_no = trim($box_no);
    $logo = trim($logo);
    $amountVal = round($total_amount, 2);

    try {
        $sql = "SELECT t.id, t.receipt_id
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.transaction_type = 'Logo_Marking'
                  AND t.party_id = ?
                  AND ROUND(t.amount, 2) = ?
                  AND COALESCE(t.box_no, '') = ?
                  AND COALESCE(t.logo, '') = ?
                  AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                  AND (SELECT COUNT(*) FROM logo_marking_items i WHERE i.transaction_id = t.id) = ?";
        if ($exclude_id > 0) {
            $sql .= ' AND t.id != ?';
        }
        $sql .= ' ORDER BY t.id DESC LIMIT 1';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($exclude_id > 0) {
            $stmt->bind_param('iidssiii', $company_id, $jeweller_id, $amountVal, $box_no, $logo, $within_seconds, $item_count, $exclude_id);
        } else {
            $stmt->bind_param('iidssii', $company_id, $jeweller_id, $amountVal, $box_no, $logo, $within_seconds, $item_count);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return ['id' => (int) $row['id'], 'receipt_id' => (string) $row['receipt_id']];
        }
    } catch (Throwable $e) {
        error_log('lm_find_duplicate_logo_marking_entry: ' . $e->getMessage());
    }

    return null;
}

/** Re-resolve receipt id inside an open transaction so parallel saves cannot reuse the same number. */
function lm_lock_receipt_id_for_new_entry(mysqli $conn, int $company_id, string $receipt_id, int $exclude_id = 0): string
{
    $receipt_id = trim($receipt_id);
    if ($receipt_id === '' || lm_receipt_exists($conn, $receipt_id, $exclude_id)) {
        return lm_next_receipt_id($conn, $company_id);
    }
    return $receipt_id;
}

/** Cash vs Bank for logo marking received amount. */
function lm_account_type_for_payment_method(string $payment_method): string
{
    return strcasecmp(trim($payment_method), 'UPI') === 0 ? 'Bank' : 'Cash';
}

/** Party ledger column for logo marking outstanding (due) balance. */
function lm_party_balance_column_for_method(string $payment_method): string
{
    return strcasecmp(trim($payment_method), 'UPI') === 0 ? 'bank_balance' : 'cash_balance';
}

/** Apply received payment change to company cash/bank balances. */
function lm_sync_logo_marking_payment_balance(
    mysqli $conn,
    int $company_id,
    float $old_received,
    string $old_method,
    float $new_received,
    string $new_method
): void {
    if (!function_exists('updateAccountBalance')) {
        $helper = __DIR__ . '/../handlers/account_balance_helper.php';
        if (is_file($helper)) {
            require_once $helper;
        }
    }
    if (!function_exists('updateAccountBalance')) {
        return;
    }

    $old_received = max(0, round($old_received, 2));
    $new_received = max(0, round($new_received, 2));

    if ($old_received > 0) {
        updateAccountBalance($conn, $company_id, lm_account_type_for_payment_method($old_method), -$old_received);
    }
    if ($new_received > 0) {
        updateAccountBalance($conn, $company_id, lm_account_type_for_payment_method($new_method), $new_received);
    }
}

/**
 * Apply outstanding (total − received) change to party cash/bank balance.
 * Positive due means the jeweller owes the firm (same model as sales on credit).
 */
function lm_sync_logo_marking_party_balance(
    mysqli $conn,
    int $party_id,
    float $old_due,
    string $old_method,
    float $new_due,
    string $new_method,
    int $old_party_id = 0
): void {
    $old_due = max(0, round($old_due, 2));
    $new_due = max(0, round($new_due, 2));
    $revert_party_id = $old_party_id > 0 ? $old_party_id : $party_id;

    if ($revert_party_id > 0 && $old_due > 0.009) {
        $col = lm_party_balance_column_for_method($old_method);
        if ($col === 'bank_balance') {
            $stmt = $conn->prepare('UPDATE parties SET bank_balance = bank_balance - ? WHERE id = ?');
        } else {
            $stmt = $conn->prepare('UPDATE parties SET cash_balance = cash_balance - ? WHERE id = ?');
        }
        if ($stmt) {
            $stmt->bind_param('di', $old_due, $revert_party_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($party_id > 0 && $new_due > 0.009) {
        $col = lm_party_balance_column_for_method($new_method);
        if ($col === 'bank_balance') {
            $stmt = $conn->prepare('UPDATE parties SET bank_balance = bank_balance + ? WHERE id = ?');
        } else {
            $stmt = $conn->prepare('UPDATE parties SET cash_balance = cash_balance + ? WHERE id = ?');
        }
        if ($stmt) {
            $stmt->bind_param('di', $new_due, $party_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/** Dashboard stats for logo marking page (filtered date + live cash/bank). */
function lm_fetch_dashboard_stats(mysqli $conn, int $company_id, string $date = ''): array
{
    $date = trim($date) !== '' ? trim($date) : date('Y-m-d');
    $stats = [
        'today_total' => 0.0,
        'today_received' => 0.0,
        'cash_in_hand' => 0.0,
        'bank_balance' => 0.0,
    ];

    try {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS today_total, COALESCE(SUM(payment_amount), 0) AS today_received
             FROM transactions
             WHERE company_id = ? AND transaction_type = 'Logo_Marking' AND DATE(date_of_transaction) = ?"
        );
        if ($stmt) {
            $stmt->bind_param('is', $company_id, $date);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $stats['today_total'] = (float) ($row['today_total'] ?? 0);
                $stats['today_received'] = (float) ($row['today_received'] ?? 0);
            }
            $stmt->close();
        }

        if (!function_exists('getAccountBalance')) {
            $helper = __DIR__ . '/../handlers/account_balance_helper.php';
            if (is_file($helper)) {
                require_once $helper;
            }
        }
        if (function_exists('getAccountBalance')) {
            $stats['cash_in_hand'] = getAccountBalance($conn, $company_id, 'Cash');
            $stats['bank_balance'] = getAccountBalance($conn, $company_id, 'Bank');
        } else {
            $cashRes = @$conn->query(
                "SELECT current_balance FROM account_balances WHERE company_id = $company_id AND account_type = 'Cash' LIMIT 1"
            );
            if ($cashRes && ($cr = $cashRes->fetch_assoc())) {
                $stats['cash_in_hand'] = (float) ($cr['current_balance'] ?? 0);
            }
            $bankRes = @$conn->query(
                "SELECT COALESCE(SUM(current_balance), 0) AS bal FROM account_balances WHERE company_id = $company_id AND account_type = 'Bank'"
            );
            if ($bankRes && ($br = $bankRes->fetch_assoc())) {
                $stats['bank_balance'] = (float) ($br['bal'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        error_log('lm_fetch_dashboard_stats: ' . $e->getMessage());
    }

    return $stats;
}

/** Normalize party / jeweller display name to uppercase. */
function lm_normalize_party_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($name, 'UTF-8');
    }
    return strtoupper($name);
}

function lm_next_receipt_id(mysqli $conn, int $firm_id): string
{
    $prefix = (string) $firm_id . 'LM';
    $max = 0;

    try {
        $stmt = $conn->prepare(
            "SELECT receipt_id FROM transactions
             WHERE company_id = ? AND transaction_type = 'Logo_Marking' AND receipt_id LIKE CONCAT(?, '%')"
        );
        if ($stmt) {
            $stmt->bind_param('is', $firm_id, $prefix);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $tail = substr((string) $row['receipt_id'], strlen($prefix));
                if ($tail !== '' && ctype_digit($tail)) {
                    $max = max($max, (int) $tail);
                }
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('lm_next_receipt_id: ' . $e->getMessage());
    }

    return $prefix . (string) ($max + 1);
}

function lm_receipt_exists(mysqli $conn, string $receipt_id, int $exclude_id = 0): bool
{
    try {
        if ($exclude_id > 0) {
            $stmt = $conn->prepare(
                "SELECT id FROM transactions WHERE receipt_id = ? AND transaction_type = 'Logo_Marking' AND id != ? LIMIT 1"
            );
            $stmt->bind_param('si', $receipt_id, $exclude_id);
        } else {
            $stmt = $conn->prepare(
                "SELECT id FROM transactions WHERE receipt_id = ? AND transaction_type = 'Logo_Marking' LIMIT 1"
            );
            $stmt->bind_param('s', $receipt_id);
        }
        if (!$stmt) {
            return false;
        }
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    } catch (Throwable $e) {
        error_log('lm_receipt_exists: ' . $e->getMessage());
        return false;
    }
}

function lm_ensure_unique_receipt_id(mysqli $conn, int $firm_id, string $proposed, int $exclude_id = 0): string
{
    $proposed = trim($proposed);
    if ($proposed !== '' && !lm_receipt_exists($conn, $proposed, $exclude_id)) {
        return $proposed;
    }
    return lm_next_receipt_id($conn, $firm_id);
}

/** @return array<int, array<string, mixed>> */
function lm_fetch_categories(mysqli $conn, int $firm_id): array
{
    $stmt = $conn->prepare(
        'SELECT id, category_name, default_rate, rate_basis FROM master_item_categories WHERE firm_id = ? ORDER BY category_name'
    );
    $stmt->bind_param('i', $firm_id);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/** @return array<int, array<string, mixed>> */
function lm_fetch_jeweller_products(mysqli $conn, int $firm_id, int $jeweller_id): array
{
    $stmt = $conn->prepare(
        'SELECT jpr.id, jpr.product_name, jpr.rate, jpr.category_id, mic.category_name, mic.rate_basis
         FROM jeweller_product_rates jpr
         LEFT JOIN master_item_categories mic ON mic.id = jpr.category_id
         WHERE jpr.firm_id = ? AND jpr.jeweller_id = ?
         ORDER BY jpr.product_name'
    );
    $stmt->bind_param('ii', $firm_id, $jeweller_id);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * Categories with default rate and optional jeweller custom rate from jeweller_product_rates.
 * Custom rates are stored as product_name = category_name + category_id.
 *
 * @return array<int, array<string, mixed>>
 */
function lm_fetch_jeweller_category_rates(mysqli $conn, int $firm_id, int $jeweller_id = 0): array
{
    $categories = lm_fetch_categories($conn, $firm_id);
    $customByCatId = [];
    if ($jeweller_id > 0) {
        $products = lm_fetch_jeweller_products($conn, $firm_id, $jeweller_id);
        foreach ($products as $p) {
            $cid = (int) ($p['category_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $catName = (string) ($p['category_name'] ?? '');
            $prodName = (string) ($p['product_name'] ?? '');
            $isCategoryRate = $catName !== '' && strcasecmp($prodName, $catName) === 0;
            if ($isCategoryRate) {
                $customByCatId[$cid] = (float) ($p['rate'] ?? 0);
            }
        }
    }

    $out = [];
    foreach ($categories as $c) {
        $id = (int) $c['id'];
        $default = (float) ($c['default_rate'] ?? 0);
        $hasCustom = array_key_exists($id, $customByCatId);
        $out[] = [
            'id' => $id,
            'category_name' => $c['category_name'],
            'default_rate' => $default,
            'rate_basis' => $c['rate_basis'] ?? 'per_piece',
            'custom_rate' => $hasCustom ? $customByCatId[$id] : null,
            'effective_rate' => $hasCustom ? $customByCatId[$id] : $default,
        ];
    }
    return $out;
}

/** Save / update a jeweller category custom rate in jeweller_product_rates. */
function lm_save_jeweller_category_rate(
    mysqli $conn,
    int $firm_id,
    int $jeweller_id,
    int $category_id,
    string $category_name,
    float $rate
): bool {
    if ($jeweller_id <= 0 || $category_id <= 0 || $category_name === '') {
        return false;
    }
    $stmt = $conn->prepare(
        'INSERT INTO jeweller_product_rates (jeweller_id, product_name, rate, firm_id, category_id)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rate = VALUES(rate), category_id = VALUES(category_id), updated_at = NOW()'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isdii', $jeweller_id, $category_name, $rate, $firm_id, $category_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** @return array<int, array<string, mixed>> */
function lm_fetch_request_items(mysqli $conn, int $request_id, int $firm_id): array
{
    try {
        $stmt = $conn->prepare(
            'SELECT * FROM logo_marking_items WHERE transaction_id = ? AND company_id = ? ORDER BY id'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $request_id, $firm_id);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        error_log('lm_fetch_request_items: ' . $e->getMessage());
        return [];
    }
}

/** Fetch logo marking header from transactions. */
function lm_fetch_logo_marking_header(mysqli $conn, int $id, int $company_id, ?string $receipt_id = null): ?array
{
    if ($id > 0) {
        try {
            $stmt = $conn->prepare(
                "SELECT t.*, p.party_name AS jeweller_name
                 FROM transactions t
                 LEFT JOIN parties p ON p.id = t.party_id
                 WHERE t.id = ? AND t.company_id = ? AND t.transaction_type = 'Logo_Marking'
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('ii', $id, $company_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return lm_map_transaction_row($row);
                }
            }
        } catch (Throwable $e) {
            error_log('lm_fetch_logo_marking_header: ' . $e->getMessage());
        }
        return null;
    }

    if ($receipt_id !== null && $receipt_id !== '') {
        try {
            $stmt = $conn->prepare(
                "SELECT t.*, p.party_name AS jeweller_name
                 FROM transactions t
                 LEFT JOIN parties p ON p.id = t.party_id
                 WHERE t.receipt_id = ? AND t.company_id = ? AND t.transaction_type = 'Logo_Marking'
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('si', $receipt_id, $company_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return lm_map_transaction_row($row);
                }
            }
        } catch (Throwable $e) {
            error_log('lm_fetch_logo_marking_header receipt: ' . $e->getMessage());
        }
    }

    return null;
}

function lm_calc_item_total(float $pieces, float $weight, float $rate, string $rate_basis): float
{
    if ($rate_basis === 'per_gram') {
        return round($weight * $rate, 2);
    }
    return round($pieces * $rate, 2);
}

function lm_format_inr(float $amount): string
{
    return number_format($amount, 0);
}

/** Purity / fineness options for logo marking items. */
function lm_purity_options(): array
{
    return ['22K', '20K', '18K', '14K', '9K', '999', '925', '875'];
}

/** Display purity label (e.g. legacy numeric 22 → 22K). */
function lm_format_purity_display($purity): string
{
    $s = trim((string) ($purity ?? ''));
    if ($s === '') {
        return '—';
    }
    if (in_array($s, lm_purity_options(), true)) {
        return $s;
    }
    if (preg_match('/^\d+(\.\d+)?$/', $s)) {
        $n = (int) round((float) $s);
        $k = $n . 'K';
        if (in_array($k, lm_purity_options(), true)) {
            return $k;
        }
        $plain = (string) $n;
        if (in_array($plain, lm_purity_options(), true)) {
            return $plain;
        }
    }
    return $s;
}

/** Standard jewellery item names for logo marking line items. */
function lm_jewellery_item_names(): array
{
    return [
        'Gold Ornament',
        'Ring',
        'Finger Ring',
        'Wedding Ring',
        'Chain',
        'Necklace',
        'Choker',
        'Haram',
        'Mangalsutra',
        'Pendant',
        'Locket',
        'Bangle',
        'Kada',
        'Bracelet',
        'Earring',
        'Ear Stud',
        'Stud',
        'Jhumka',
        'Tops',
        'Nose Pin',
        'Anklet',
        'Payal',
        'Toe Ring',
        'Armlet',
        'Waist Chain',
        'Brooch',
        'Gold Coin',
        'Gold Bar',
        'Biscuit',
        'Gold Button',
        'Gold Wire',
        'Gold Sheet',
        'Gold Pipe',
        'Gold Rod',
        'Gold Ball',
        'Gold Hook',
        'Gold Clip',
        'Gold Tag',
        'Gold Seal',
        'Gold Plate',
    ];
}

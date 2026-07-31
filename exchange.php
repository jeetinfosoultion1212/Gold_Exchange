<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header("Pragma: no-cache");
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');

// Load database configuration
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/handlers/account_balance_helper.php';
require_once __DIR__ . '/helpers/gold_rate_helper.php';
require_once __DIR__ . '/helpers/receipt_id_helper.php';

if ($conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Connection failed: ' . $conn->connect_error]));
}

/**
 * Normalize exchange vault metal: issued fine is taken from Fine Gold or Fine Silver stock row.
 */
function ge_normalize_exchange_material($v): string
{
    if (is_string($v) && strcasecmp(trim($v), 'Silver') === 0) {
        return 'Silver';
    }
    return 'Gold';
}

/**
 * Apply lightweight schema updates for gold/silver exchange (idempotent, runs once per request).
 */
function ge_ensure_exchange_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $r = @$conn->query("SHOW COLUMNS FROM exchange_items LIKE 'material'");
    if ($r && $r->num_rows === 0) {
        @$conn->query("ALTER TABLE exchange_items ADD COLUMN material ENUM('Gold','Silver') NOT NULL DEFAULT 'Gold' COMMENT 'Scrap metal (received)'");
    }
    if ($r) {
        $r->free();
    }
    $r2 = @$conn->query("SHOW COLUMNS FROM transactions LIKE 'exchange_material'");
    if ($r2 && $r2->num_rows === 0) {
        @$conn->query("ALTER TABLE transactions ADD COLUMN exchange_material VARCHAR(10) NOT NULL DEFAULT 'Gold'");
    }
    if ($r2) {
        $r2->free();
    }
}

/**
 * Signed cash/bank ledger delta for an Exchange transaction's due_amount.
 *
 * due_amount is always stored as a positive "how much is still outstanding" figure
 * (used for the Due/Partial/Paid status), but which DIRECTION it moves the party's
 * balance depends on who owes whom:
 *   - difference_weight >= 0 (issued more fine metal than received): the CUSTOMER
 *     owes the shop the difference -> due_amount should INCREASE the party's balance.
 *   - difference_weight < 0 (received more fine metal than issued): the SHOP owes
 *     the CUSTOMER the difference -> due_amount should DECREASE the party's balance
 *     (or push it negative, meaning the shop currently owes the customer money).
 */
function ge_signed_due_delta(float $due_amount, float $difference_weight): float
{
    return $difference_weight >= 0 ? $due_amount : -$due_amount;
}

/**
 * Render a Gold/Silver metal-split stat line, hiding whichever side is zero so an
 * unused metal (e.g. Silver, if the shop only deals in Gold that day) doesn't clutter
 * the stats bar. Falls back to a single "0.00 g" segment if both sides are zero.
 */
function ge_render_metal_split(float $gold, float $silver, string $goldTitle, string $silverTitle): string
{
    $goldHtml = $gold > 0
        ? '<span class="metal-seg" title="' . htmlspecialchars($goldTitle, ENT_QUOTES) . '"><i class="fas fa-coins metal-icon-gold" aria-hidden="true"></i><span class="metal-num">' . number_format($gold, 2) . '</span><span class="metal-unit">g</span></span>'
        : '';
    $silverHtml = $silver > 0
        ? '<span class="metal-seg" title="' . htmlspecialchars($silverTitle, ENT_QUOTES) . '"><i class="fas fa-coins metal-icon-silver" aria-hidden="true"></i><span class="metal-num">' . number_format($silver, 2) . '</span><span class="metal-unit">g</span></span>'
        : '';

    if ($goldHtml === '' && $silverHtml === '') {
        return '<span class="metal-seg"><span class="metal-num">0.00</span><span class="metal-unit">g</span></span>';
    }
    if ($goldHtml !== '' && $silverHtml !== '') {
        return $goldHtml . '<span class="text-slate-300" aria-hidden="true">&middot;</span>' . $silverHtml;
    }
    return $goldHtml . $silverHtml;
}

/** Indian digit grouping (e.g. 1385851 -> "13,85,851") instead of the Western 1,385,851. */
function ge_format_inr($amount, int $decimals = 0): string
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

/** SQL fragment: treat as "fine" bullion row (shops often use 99.90%, not 100%). */
function ge_sql_fine_purity(): string
{
    return '(purity >= 99.50 OR purity = 100.00 OR purity = 100.0 OR purity = 100)';
}

/**
 * Lock and return the Cash fine stock row for Gold or Silver vault.
 * Uses fine-grade purity (99.5%+ or 100%) so 99.90% Fine Gold / Fine Silver match shop stock cards.
 *
 * NOTE: used both inside the save/delete transaction (where FOR UPDATE locking matters) and by the
 * read-only stats cards below (where the lock is harmless/short-lived) to avoid duplicating this SQL.
 */
function ge_fetch_fine_stock_for_material(mysqli $conn, int $company_id, string $material): ?array
{
    $material = ge_normalize_exchange_material($material);
    $fineP = ge_sql_fine_purity();
    if ($material === 'Silver') {
        $sqls = [
            "SELECT id, current_stock, stock_name FROM gold_stock
                WHERE company_id = ? AND mode = 'Cash'
                AND {$fineP}
                AND (LOWER(stock_name) LIKE '%silver%')
                ORDER BY
                    CASE WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1 ELSE 2 END,
                    purity DESC, id ASC
                LIMIT 1 FOR UPDATE",
            "SELECT id, current_stock, stock_name FROM gold_stock
                WHERE company_id = ? AND mode = 'Cash'
                AND (LOWER(stock_name) LIKE '%silver%')
                ORDER BY
                    CASE WHEN ({$fineP}) THEN 0 ELSE 1 END,
                    CASE WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1 ELSE 2 END,
                    purity DESC, id ASC
                LIMIT 1 FOR UPDATE",
        ];
    } else {
        $sqls = [
            "SELECT id, current_stock, stock_name FROM gold_stock
                WHERE company_id = ? AND mode = 'Cash'
                AND {$fineP}
                AND NOT (LOWER(stock_name) LIKE '%silver%')
                ORDER BY
                    CASE WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1 ELSE 2 END,
                    purity DESC, id ASC
                LIMIT 1 FOR UPDATE",
        ];
    }
    foreach ($sqls as $sql) {
        $st = $conn->prepare($sql);
        if (!$st) {
            return null;
        }
        $st->bind_param("i", $company_id);
        $st->execute();
        $res = $st->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $st->close();
            return $row;
        }
        $st->close();
    }
    return null;
}

/** Weighted average of user-entered purities from received line items (not fine ÷ weight). */
function ge_weighted_purity_from_received_items(array $items): float
{
    $totalWeight = 0.0;
    $weightedSum = 0.0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $weight = floatval($item['weight'] ?? 0);
        if ($weight <= 0) {
            continue;
        }
        $weightedSum += $weight * floatval($item['purity'] ?? 0);
        $totalWeight += $weight;
    }
    return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0.0;
}

/** Base SQL for exchange transaction lists (recent sidebar + AJAX). */
function ge_exchange_list_base_sql(): string
{
    return "SELECT t.*, p.party_name,
        (SELECT CASE WHEN SUM(ei.weight) > 0
             THEN ROUND(SUM(ei.weight * ei.purity) / SUM(ei.weight), 2)
             ELSE NULL END
         FROM exchange_items ei
         WHERE ei.transaction_id = t.id AND ei.company_id = t.company_id AND ei.item_type = 'received'
        ) AS item_purity,
        (SELECT GROUP_CONCAT(CONCAT(COALESCE(ei.weight, 0), ':', COALESCE(ei.purity, 0), ':', COALESCE(ei.fine_weight, 0)) ORDER BY ei.id SEPARATOR '|')
         FROM exchange_items ei
         WHERE ei.transaction_id = t.id AND ei.company_id = t.company_id AND ei.item_type = 'received'
        ) AS items_concat
        FROM transactions t
        LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.company_id = ? AND DATE(t.date_of_transaction) BETWEEN ? AND ? AND t.transaction_type = 'Exchange'";
}

/** Parses the "weight:purity:fine|weight:purity:fine" concat into a list of received-item rows. */
function ge_parse_exchange_items_concat(?string $concat): array
{
    $items = [];
    if ($concat === null || $concat === '') {
        return $items;
    }
    foreach (explode('|', $concat) as $chunk) {
        $parts = explode(':', $chunk);
        if (count($parts) < 3) {
            continue;
        }
        $items[] = [
            'weight' => floatval($parts[0]),
            'purity' => floatval($parts[1]),
            'fine' => floatval($parts[2]),
        ];
    }
    return $items;
}

function ge_count_exchange_transactions(mysqli $conn, int $company_id, string $start_date, string $end_date, ?string $search = null): int
{
    $sql = "SELECT COUNT(*) AS cnt FROM transactions t
        LEFT JOIN parties p ON t.party_id = p.id
        WHERE t.company_id = ? AND DATE(t.date_of_transaction) BETWEEN ? AND ? AND t.transaction_type = 'Exchange'";
    if ($search !== null && $search !== '') {
        $sql .= " AND (p.party_name LIKE ? OR t.receipt_id LIKE ?)";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    if ($search !== null && $search !== '') {
        $like = '%' . $search . '%';
        $stmt->bind_param('isss', $company_id, $start_date, $end_date, $like, $like);
    } else {
        $stmt->bind_param('iss', $company_id, $start_date, $end_date);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int) ($row['cnt'] ?? 0);
}

function ge_fetch_exchange_transactions(
    mysqli $conn,
    int $company_id,
    string $start_date,
    string $end_date,
    ?string $search,
    int $offset,
    int $limit,
    string $gold_rate_unit
): array {
    $sql = ge_exchange_list_base_sql();
    if ($search !== null && $search !== '') {
        $sql .= " AND (p.party_name LIKE ? OR t.receipt_id LIKE ?)";
    }
    $sql .= " ORDER BY t.date_of_transaction DESC, t.id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($search !== null && $search !== '') {
        $like = '%' . $search . '%';
        $stmt->bind_param('isssii', $company_id, $start_date, $end_date, $like, $like, $limit, $offset);
    } else {
        $stmt->bind_param('issii', $company_id, $start_date, $end_date, $limit, $offset);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function ge_map_exchange_list_row(array $row, string $gold_rate_unit): array
{
    gold_rate_apply_display_to_row($row, $gold_rate_unit);
    $display_purity = ($row['item_purity'] !== null && $row['item_purity'] !== '')
        ? floatval($row['item_purity'])
        : floatval($row['purity']);
    return [
        'id' => (int) $row['id'],
        'receipt_id' => $row['receipt_id'],
        'party_name' => $row['party_name'] ?? '',
        'date_of_transaction' => $row['date_of_transaction'],
        'received_weight' => floatval($row['received_weight']),
        'fine_weight' => floatval($row['fine_weight']),
        'delivered_weight' => floatval($row['delivered_weight']),
        'difference_weight' => floatval($row['difference_weight']),
        'amount' => floatval($row['amount']),
        'payment_amount' => floatval($row['payment_amount']),
        'payment_type' => $row['payment_type'] ?? '',
        'exchange_material' => $row['exchange_material'] ?? 'Gold',
        'display_purity' => $display_purity,
        'rate_display' => gold_rate_to_display(floatval($row['rate']), $gold_rate_unit),
        'items' => ge_parse_exchange_items_concat($row['items_concat'] ?? null),
    ];
}

function ge_count_exchange_receipts(mysqli $conn, int $company_id, string $term = ''): int
{
    $sql = "SELECT COUNT(*) AS cnt FROM transactions t
        WHERE t.company_id = ? AND t.transaction_type = 'Exchange'";
    if ($term !== '') {
        $sql .= " AND t.receipt_id LIKE ?";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    if ($term !== '') {
        $like = $term . '%';
        $stmt->bind_param('is', $company_id, $like);
    } else {
        $stmt->bind_param('i', $company_id);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int) ($row['cnt'] ?? 0);
}

function ge_fetch_exchange_receipts(mysqli $conn, int $company_id, string $term, int $offset, int $limit): array
{
    $sql = "SELECT t.receipt_id, t.date_of_transaction, t.received_weight, t.amount,
                   t.payment_type, t.exchange_material, p.party_name
            FROM transactions t
            LEFT JOIN parties p ON t.party_id = p.id
            WHERE t.company_id = ? AND t.transaction_type = 'Exchange'";
    if ($term !== '') {
        $sql .= " AND t.receipt_id LIKE ?";
    }
    $sql .= " ORDER BY t.id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($term !== '') {
        $like = $term . '%';
        $stmt->bind_param('isii', $company_id, $like, $limit, $offset);
    } else {
        $stmt->bind_param('iii', $company_id, $limit, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'receipt_id' => $row['receipt_id'],
            'party_name' => $row['party_name'] ?? 'Unknown',
            'date_of_transaction' => $row['date_of_transaction'],
            'received_weight' => floatval($row['received_weight']),
            'amount' => floatval($row['amount']),
            'payment_type' => $row['payment_type'] ?? '',
            'exchange_material' => $row['exchange_material'] ?? 'Gold',
        ];
    }
    return $items;
}

// Get company_id from session
$company_id = $_SESSION['company_id'];
$gold_rate_unit = gold_rate_get_unit($conn, $company_id);
$gold_rate_label = gold_rate_label($gold_rate_unit);
$gold_rate_suffix = gold_rate_suffix($gold_rate_unit);
ge_ensure_exchange_schema($conn);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'search_parties':
                $search = $conn->real_escape_string($_POST['term']);
                $sql = "SELECT 
                        id, 
                        party_name, 
                        address,
                        (cash_balance + bank_balance) AS total_due_amount,
                        gold_balance AS total_due_gold,
                        silver_balance AS total_due_silver
                    FROM parties
                    WHERE company_id = $company_id AND party_name LIKE '%$search%'
                    LIMIT 10";

                $result = $conn->query($sql);
                $parties = [];
                while ($row = $result->fetch_assoc()) {
                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'total_due_amount' => $row['total_due_amount'],
                        'total_due_gold' => $row['total_due_gold'],
                        'total_due_silver' => $row['total_due_silver']
                    ];
                }
                echo json_encode($parties);
                exit;

            case 'save_party':
                $party_name = $conn->real_escape_string($_POST['party_name']);
                $address = $conn->real_escape_string($_POST['address']);
                $contact_no = $conn->real_escape_string($_POST['contact_no']);
                $gstin = $conn->real_escape_string($_POST['gstin'] ?? 'N/A');
                $state = $conn->real_escape_string($_POST['state'] ?? '');
                $city = $conn->real_escape_string($_POST['city'] ?? '');
                $bank_name = $conn->real_escape_string($_POST['bank_name'] ?? '');
                $account_no = $conn->real_escape_string($_POST['account_no'] ?? '');
                $ifsc_code = $conn->real_escape_string($_POST['ifsc_code'] ?? '');

                $cash_balance = floatval($_POST['cash_balance'] ?? 0);
                $bank_balance = floatval($_POST['bank_balance'] ?? 0);
                $gold_balance = floatval($_POST['gold_balance'] ?? 0);
                $silver_balance = floatval($_POST['silver_balance'] ?? 0);

                $conn->begin_transaction();
                try {
                    $sql = "INSERT INTO parties (company_id, party_name, address, contact_no, gstin, state, city, bank_name, account_no, ifsc_code, cash_balance, bank_balance, gold_balance, silver_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("isssssssssdddd", $company_id, $party_name, $address, $contact_no, $gstin, $state, $city, $bank_name, $account_no, $ifsc_code, $cash_balance, $bank_balance, $gold_balance, $silver_balance);
                    $stmt->execute();
                    $new_party_id = $stmt->insert_id;

                    $conn->commit();
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Party added successfully',
                        'party_id' => $new_party_id
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error adding party: ' . $e->getMessage()
                    ]);
                }
                exit;

            case 'get_exchange_by_receipt_id':
                $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');

                $sql = "SELECT t.*, p.party_name 
                        FROM transactions t 
                        LEFT JOIN parties p ON t.party_id = p.id
                        WHERE t.receipt_id = ? AND t.company_id = ? AND t.transaction_type = 'Exchange'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $receipt_id, $company_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $transaction = $result->fetch_assoc();

                    // Fetch received items only (no issued items needed)
                    $items_sql = "SELECT * FROM exchange_items WHERE transaction_id = ? AND item_type = 'received' ORDER BY id";
                    $items_stmt = $conn->prepare($items_sql);
                    $items_stmt->bind_param("i", $transaction['id']);
                    $items_stmt->execute();
                    $items_result = $items_stmt->get_result();

                    $received_items = [];

                    while ($item = $items_result->fetch_assoc()) {
                        $received_items[] = [
                            'weight' => $item['weight'],
                            'purity' => $item['purity'],
                            'fine' => $item['fine_weight'],
                            'material' => ge_normalize_exchange_material($item['material'] ?? 'Gold'),
                        ];
                    }

                    $transaction['received_items'] = $received_items;
                    gold_rate_apply_display_to_row($transaction, $gold_rate_unit);

                    echo json_encode([
                        'status' => 'success',
                        'data' => $transaction
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Receipt not found'
                    ]);
                }
                exit;

            case 'get_exchange_by_id':
                // Same shape as get_exchange_by_receipt_id, but looked up by primary key.
                // Used by the "Edit" button in the transaction list so it can restore ALL
                // received item rows (Gold/Silver lines), not just the aggregated totals.
                $id = intval($_POST['id'] ?? 0);

                $sql = "SELECT t.*, p.party_name 
                        FROM transactions t 
                        LEFT JOIN parties p ON t.party_id = p.id
                        WHERE t.id = ? AND t.company_id = ? AND t.transaction_type = 'Exchange'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $id, $company_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $transaction = $result->fetch_assoc();

                    $items_sql = "SELECT * FROM exchange_items WHERE transaction_id = ? AND item_type = 'received' ORDER BY id";
                    $items_stmt = $conn->prepare($items_sql);
                    $items_stmt->bind_param("i", $transaction['id']);
                    $items_stmt->execute();
                    $items_result = $items_stmt->get_result();

                    $received_items = [];
                    while ($item = $items_result->fetch_assoc()) {
                        $received_items[] = [
                            'weight' => $item['weight'],
                            'purity' => $item['purity'],
                            'fine' => $item['fine_weight'],
                            'material' => ge_normalize_exchange_material($item['material'] ?? 'Gold'),
                        ];
                    }

                    $transaction['received_items'] = $received_items;
                    gold_rate_apply_display_to_row($transaction, $gold_rate_unit);

                    echo json_encode([
                        'status' => 'success',
                        'data' => $transaction
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Transaction not found'
                    ]);
                }
                exit;

            case 'save_transaction':
                $conn->begin_transaction();
                try {
                    $data = $_POST;
                    $receipt_id = $conn->real_escape_string($data['receipt_id']);
                    $party_name = $conn->real_escape_string($data['party_name']);
                    $is_new_transaction = !isset($data['transaction_id']) || empty($data['transaction_id']);
                    if ($is_new_transaction) {
                        $receipt_id = $conn->real_escape_string(ensure_unique_receipt_id(
                            $conn,
                            $company_id,
                            'EX',
                            $receipt_id,
                            ['transaction_type' => 'Exchange']
                        ));
                    }
                    $date_of_transaction = $conn->real_escape_string($data['date_of_transaction']);
                    $received_weight = floatval($data['received_weight']);
                    $purity = floatval($data['purity']);
                    $fine_weight = floatval($data['fine_weight']);
                    $issue_weight = max(0, floatval($data['issue_weight']));
                    $difference_weight = $issue_weight - $fine_weight;
                    // Default from POST; received_items JSON overrides when present (Metal column is source of truth).
                    $exchange_material = ge_normalize_exchange_material($data['exchange_material'] ?? 'Gold');
                    if (isset($data['received_items']) && $data['received_items'] !== '') {
                        $decoded_rx = json_decode($data['received_items'], true);
                        if (is_array($decoded_rx)) {
                            $mats_from_items = [];
                            foreach ($decoded_rx as $it) {
                                if (floatval($it['weight'] ?? 0) > 0) {
                                    $mats_from_items[] = ge_normalize_exchange_material($it['material'] ?? 'Gold');
                                }
                            }
                            $mats_from_items = array_values(array_unique($mats_from_items));
                            if (count($mats_from_items) > 1) {
                                throw new Exception('All received items with weight must use the same metal (Gold or Silver).');
                            }
                            if (count($mats_from_items) === 1) {
                                $exchange_material = $mats_from_items[0];
                            } elseif (count($decoded_rx) > 0) {
                                // No line with weight yet / parsing edge: use first row's Metal (matches UI)
                                $exchange_material = ge_normalize_exchange_material($decoded_rx[0]['material'] ?? 'Gold');
                            }
                            // Store entered purities — never back-calculate from rounded fine weight.
                            $item_purity = ge_weighted_purity_from_received_items($decoded_rx);
                            if ($item_purity > 0) {
                                $purity = $item_purity;
                            }
                        }
                    }
                    $rate = gold_rate_from_display(floatval($data['rate']), $gold_rate_unit);
                    $amount = floatval($data['amount']);
                    $payment_method = $conn->real_escape_string($data['payment_method'] ?? 'Cash');
                    $payment_amount = floatval($data['payment_amount'] ?? 0);
                    $due_amount = $amount - $payment_amount;

                    // Calculate payment status based on payment amount and total amount
                    // If payment_amount >= amount: Paid
                    // If payment_amount > 0 but < amount: Partial
                    // If payment_amount = 0: Due
                    if ($amount > 0 && $payment_amount >= $amount) {
                        $payment_status = 'Paid';
                    } else if ($payment_amount > 0) {
                        $payment_status = 'Partial';
                    } else {
                        $payment_status = 'Due';
                    }

                    // Override with user-provided status if explicitly set (for edits)
                    if (isset($data['payment_status']) && !empty($data['payment_status'])) {
                        $payment_status = $conn->real_escape_string($data['payment_status']);
                    }

                    $narration = $conn->real_escape_string($data['narration'] ?? '');

                    $transaction_id = isset($data['transaction_id']) && !empty($data['transaction_id']) ? intval($data['transaction_id']) : null;

                    $original_transaction = null;
                    $original_due_amount = 0;
                    $original_difference_weight = 0;
                    $original_payment_amount = 0;
                    $original_delivered_weight = 0;
                    $original_exchange_material = 'Gold';

                    if ($transaction_id) {
                        $original_sql = "SELECT fine_weight, party_id, received_weight, due_amount, difference_weight, payment_amount, payment_method, delivered_weight, exchange_material FROM transactions WHERE id = ? FOR UPDATE";
                        $original_stmt = $conn->prepare($original_sql);
                        $original_stmt->bind_param("i", $transaction_id);
                        $original_stmt->execute();
                        $original_result = $original_stmt->get_result();
                        $original_transaction = $original_result->fetch_assoc();

                        if (!$original_transaction) {
                            throw new Exception("Original transaction not found");
                        }

                        $original_due_amount = floatval($original_transaction['due_amount'] ?? 0);
                        $original_difference_weight = floatval($original_transaction['difference_weight'] ?? 0);
                        $original_payment_amount = floatval($original_transaction['payment_amount'] ?? 0);
                        $original_delivered_weight = max(0, floatval($original_transaction['delivered_weight'] ?? 0));
                        $original_exchange_material = ge_normalize_exchange_material($original_transaction['exchange_material'] ?? 'Gold');
                        $party_id = $original_transaction['party_id'];
                    } else {
                        // Get party ID for new transaction
                        $party_sql = "SELECT id FROM parties WHERE company_id = ? AND party_name = ?";
                        $party_stmt = $conn->prepare($party_sql);
                        $party_stmt->bind_param("is", $company_id, $party_name);
                        $party_stmt->execute();
                        $party_result = $party_stmt->get_result();

                        if ($party_result->num_rows === 0) {
                            // Party doesn't exist - auto-create it
                            $create_party_sql = "INSERT INTO parties (company_id, party_name, address, contact_no) VALUES (?, ?, '', '')";
                            $create_party_stmt = $conn->prepare($create_party_sql);
                            $create_party_stmt->bind_param("is", $company_id, $party_name);

                            if (!$create_party_stmt->execute()) {
                                throw new Exception("Failed to create party: {$party_name}");
                            }

                            $party_id = $create_party_stmt->insert_id;
                            $create_party_stmt->close();
                        } else {
                            $party_id = $party_result->fetch_assoc()['id'];
                        }
                    }

                    // --- Issue (vault): deduct delivered_weight from Fine Gold or Fine Silver stock ---
                    $stock_update_fine = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                    $fine_stock_err = function (string $m) {
                        return $m === 'Silver'
                            ? "Fine silver stock not found. Add a Cash gold_stock row with \"Silver\" in the name and purity 99.5%+ (e.g. 99.90% Fine Silver)."
                            : "Fine gold stock not found. Add a Cash gold_stock row without \"Silver\" in the name and purity 99.5%+ or 100% (e.g. 99.90% Fine Gold).";
                    };

                    if ($transaction_id && strcasecmp($original_exchange_material, $exchange_material) === 0) {
                        $stock_row = ge_fetch_fine_stock_for_material($conn, $company_id, $exchange_material);
                        if (!$stock_row) {
                            throw new Exception($fine_stock_err($exchange_material));
                        }
                        $avail = floatval($stock_row['current_stock']) + $original_delivered_weight;
                        $new_issue_stock = $avail - $issue_weight;
                        if ($new_issue_stock < -0.00001) {
                            throw new Exception("Insufficient {$exchange_material} fine stock for issue. Available: {$avail} g, issue: {$issue_weight} g.");
                        }
                        $stock_stmt = $conn->prepare($stock_update_fine);
                        $stock_stmt->bind_param("di", $new_issue_stock, $stock_row['id']);
                        if (!$stock_stmt->execute()) {
                            throw new Exception("Failed to update fine stock: " . $stock_stmt->error);
                        }
                    } elseif ($transaction_id) {
                        if ($original_delivered_weight > 0) {
                            $old_row = ge_fetch_fine_stock_for_material($conn, $company_id, $original_exchange_material);
                            if (!$old_row) {
                                throw new Exception("Cannot edit: " . $fine_stock_err($original_exchange_material));
                            }
                            $restored = floatval($old_row['current_stock']) + $original_delivered_weight;
                            $stock_stmt = $conn->prepare($stock_update_fine);
                            $stock_stmt->bind_param("di", $restored, $old_row['id']);
                            if (!$stock_stmt->execute()) {
                                throw new Exception("Failed to restore original fine stock: " . $stock_stmt->error);
                            }
                        }
                        $stock_row = ge_fetch_fine_stock_for_material($conn, $company_id, $exchange_material);
                        if (!$stock_row) {
                            throw new Exception($fine_stock_err($exchange_material));
                        }
                        $new_issue_stock = floatval($stock_row['current_stock']) - $issue_weight;
                        if ($new_issue_stock < -0.00001) {
                            throw new Exception("Insufficient {$exchange_material} fine stock for issue. Available: {$stock_row['current_stock']} g, issue: {$issue_weight} g.");
                        }
                        $stock_stmt = $conn->prepare($stock_update_fine);
                        $stock_stmt->bind_param("di", $new_issue_stock, $stock_row['id']);
                        if (!$stock_stmt->execute()) {
                            throw new Exception("Failed to update fine stock: " . $stock_stmt->error);
                        }
                    } else {
                        $stock_row = ge_fetch_fine_stock_for_material($conn, $company_id, $exchange_material);
                        if (!$stock_row) {
                            throw new Exception($fine_stock_err($exchange_material));
                        }
                        $avail = floatval($stock_row['current_stock']);
                        $new_issue_stock = $avail - $issue_weight;
                        if ($new_issue_stock < -0.00001) {
                            throw new Exception("Insufficient {$exchange_material} fine stock for issue. Available: {$avail} g, issue: {$issue_weight} g.");
                        }
                        $stock_stmt = $conn->prepare($stock_update_fine);
                        $stock_stmt->bind_param("di", $new_issue_stock, $stock_row['id']);
                        if (!$stock_stmt->execute()) {
                            throw new Exception("Failed to update fine stock: " . $stock_stmt->error);
                        }
                    }

                    // --- RECEIVED GOLD STOCK LOGIC (MIX STOCK) ---
                    // 1. If Edit: Revert Old Received Stock (Subtract from MIX Stock)
                    if ($transaction_id) {
                        $old_rcv_wt = floatval($original_transaction['received_weight']);
                        $stock_name = "MIX Stock";
                        $mix_purity = 0.00;

                        if ($old_rcv_wt > 0) {
                            // Find existing stock (MIX Stock is always considered Cash/Kachha by default)
                            $find_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ? AND mode = 'Cash'";
                            $find_stock_stmt = $conn->prepare($find_stock_sql);
                            $find_stock_stmt->bind_param("isd", $company_id, $stock_name, $mix_purity);
                            $find_stock_stmt->execute();
                            $rs_res = $find_stock_stmt->get_result();

                            if ($rs_res->num_rows > 0) {
                                $rs_row = $rs_res->fetch_assoc();
                                $new_rs_val = max(0, $rs_row['current_stock'] - $old_rcv_wt);
                                $upd_rs_sql = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                                $upd_rs_stmt = $conn->prepare($upd_rs_sql);
                                $upd_rs_stmt->bind_param("di", $new_rs_val, $rs_row['id']);
                                $upd_rs_stmt->execute();
                            }
                        }
                    }

                    // 2. New/Edit: Add New Received Stock (Add to MIX Stock)
                    if ($received_weight > 0) {
                        $new_rcv_wt = floatval($received_weight);
                        $stock_name = "MIX Stock";
                        $mix_purity = 0.00;

                        $find_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ? AND mode = 'Cash'";
                        $find_stock_stmt = $conn->prepare($find_stock_sql);
                        $find_stock_stmt->bind_param("isd", $company_id, $stock_name, $mix_purity);
                        $find_stock_stmt->execute();
                        $rs_res = $find_stock_stmt->get_result();

                        if ($rs_res->num_rows > 0) {
                            // Update
                            $rs_row = $rs_res->fetch_assoc();
                            $new_rs_val = $rs_row['current_stock'] + $new_rcv_wt;
                            $upd_rs_sql = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                            $upd_rs_stmt = $conn->prepare($upd_rs_sql);
                            $upd_rs_stmt->bind_param("di", $new_rs_val, $rs_row['id']);
                            $upd_rs_stmt->execute();
                        } else {
                            // Insert (Default to Cash mode)
                            $ins_rs_sql = "INSERT INTO gold_stock (company_id, stock_name, purity, mode, current_stock, last_updated) VALUES (?, ?, ?, 'Cash', ?, NOW())";
                            $ins_rs_stmt = $conn->prepare($ins_rs_sql);
                            $ins_rs_stmt->bind_param("isdd", $company_id, $stock_name, $mix_purity, $new_rcv_wt);
                            $ins_rs_stmt->execute();
                        }
                    }
                    // --- END RECEIVED GOLD STOCK LOGIC ---

                    // Save transaction
                    $type = 'Exchange';
                    $payment_type = $difference_weight > 0 ? 'Payment_In' : 'Payment_Out';

                    if ($transaction_id) {
                        // Update existing transaction
                        $sql = "UPDATE transactions SET 
                            receipt_id = ?, company_id = ?, user_id = ?, party_id = ?, date_of_transaction = ?, 
                            received_weight = ?, purity = ?, fine_weight = ?, delivered_weight = ?, 
                            difference_weight = ?, rate = ?, amount = ?, payment_method = ?, 
                            payment_status = ?, due_amount = ?, narration = ?, payment_type = ?, 
                            transaction_type = ?, payment_amount = ?, exchange_material = ?
                            WHERE id = ?";

                        $stmt = $conn->prepare($sql);
                        $user_id = $_SESSION['user_id'];
                        $stmt->bind_param(
                            "siiisdddddddssdsssdsi",
                            $receipt_id,
                            $company_id,
                            $user_id,
                            $party_id,
                            $date_of_transaction,
                            $received_weight,
                            $purity,
                            $fine_weight,
                            $issue_weight,
                            $difference_weight,
                            $rate,
                            $amount,
                            $payment_method,
                            $payment_status,
                            $due_amount,
                            $narration,
                            $payment_type,
                            $type,
                            $payment_amount,
                            $exchange_material,
                            $transaction_id
                        );
                    } else {
                        // Insert new transaction
                        $sql = "INSERT INTO transactions (
                            company_id, user_id, receipt_id, party_id, date_of_transaction, received_weight,
                            purity, fine_weight, delivered_weight, difference_weight,
                            rate, amount, payment_method, payment_status, due_amount, narration,
                            payment_type, transaction_type, payment_amount, exchange_material, gold_weight, gold_amount
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                        $stmt = $conn->prepare($sql);
                        $user_id = $_SESSION['user_id'];
                        $gold_weight = $issue_weight; // delivered weight
                        $gold_amount = $amount;
                        $stmt->bind_param(
                            "iisisdddddddssdsssdsdd",
                            $company_id,
                            $user_id,
                            $receipt_id,
                            $party_id,
                            $date_of_transaction,
                            $received_weight,
                            $purity,
                            $fine_weight,
                            $issue_weight,
                            $difference_weight,
                            $rate,
                            $amount,
                            $payment_method,
                            $payment_status,
                            $due_amount,
                            $narration,
                            $payment_type,
                            $type,
                            $payment_amount,
                            $exchange_material,
                            $gold_weight,
                            $gold_amount
                        );
                    }

                    if (!$stmt->execute()) {
                        throw new Exception("Failed to save transaction: " . $stmt->error);
                    }

                    if (!$transaction_id) {
                        $transaction_id = $stmt->insert_id;
                    }

                    // Payment info is stored directly in the transactions table
                    // AND we create a separate transaction for Cash/Bank stats visibility

                    // 1. Delete any existing linked payment transactions for this receipt (for updates)
                    // First, revert balance changes for these transactions
                    $get_linked_sql = "SELECT payment_amount, payment_method, transaction_type FROM transactions WHERE company_id = ? AND narration LIKE ? AND (transaction_type = 'Payment' OR transaction_type = 'Received')";
                    $linked_pattern = "%Payment for Exchange " . $receipt_id . "%";
                    $get_linked_stmt = $conn->prepare($get_linked_sql);
                    $get_linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $get_linked_stmt->execute();
                    $linked_result = $get_linked_stmt->get_result();
                    while ($old_linked = $linked_result->fetch_assoc()) {
                        $old_amt = floatval($old_linked['payment_amount']);
                        $old_method = $old_linked['payment_method'];
                        $old_type = $old_linked['transaction_type'];

                        // Reversal Logic:
                        // If it was Received (we got money), we remove it (Subtract).
                        // If it was Payment (we paid money), we add it back.
                        $reversal_amt = ($old_type === 'Received') ? -$old_amt : $old_amt;

                        // Only Cash/Bank affect the balance table (UPI/Cheque -> Bank)
                        if ($old_method === 'Cash') {
                            updateAccountBalance($conn, $company_id, 'Cash', $reversal_amt);
                        } elseif (in_array($old_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                            updateAccountBalance($conn, $company_id, 'Bank', $reversal_amt);
                        }
                    }

                    $delete_linked_sql = "DELETE FROM transactions WHERE company_id = ? AND narration LIKE ? AND (transaction_type = 'Payment' OR transaction_type = 'Received')";
                    // $linked_pattern is already set
                    $delete_linked_stmt = $conn->prepare($delete_linked_sql);
                    $delete_linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $delete_linked_stmt->execute();

                    // 2. Insert new linked payment transaction if amount > 0
                    if ($payment_amount > 0) {
                        $linked_type = $payment_type === 'Payment_In' ? 'Received' : 'Payment';
                        $linked_receipt_id = 'PAY-' . $receipt_id . '-' . rand(1000, 9999);
                        $linked_narration = "Payment for Exchange " . $receipt_id;

                        $linked_sql = "INSERT INTO transactions (
                            company_id, user_id, receipt_id, party_id, date_of_transaction,
                            transaction_type, payment_type, payment_method, payment_amount,
                            narration, payment_status, due_amount, amount
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', 0, ?)";

                        $linked_stmt = $conn->prepare($linked_sql);
                        $linked_stmt->bind_param(
                            "iisissssdsd",
                            $company_id,
                            $user_id,
                            $linked_receipt_id,
                            $party_id,
                            $date_of_transaction,
                            $linked_type,
                            $payment_type,
                            $payment_method,
                            $payment_amount,
                            $linked_narration,
                            $payment_amount
                        );

                        if (!$linked_stmt->execute()) {
                            throw new Exception("Failed to save linked payment transaction: " . $linked_stmt->error);
                        }

                        // Update Account Balance for the new transaction
                        // Linked Type: 'Received' (In) or 'Payment' (Out)
                        $balance_amt = ($linked_type === 'Received') ? $payment_amount : -$payment_amount;

                        if ($payment_method === 'Cash') {
                            updateAccountBalance($conn, $company_id, 'Cash', $balance_amt);
                        } elseif (in_array($payment_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                            updateAccountBalance($conn, $company_id, 'Bank', $balance_amt);
                        }
                    }

                    // Update Party Balance
                    // Logic:
                    // 1. If editing: Revert old balance changes first
                    // 2. Apply new balance changes
                    // 3. due_amount = amount - payment_amount (what party still owes)
                    // 4. difference_weight = issue_weight - fine_weight (metal difference in g — gold_balance or silver_balance per exchange_material)

                    if ($transaction_id) {
                        $old_method = $original_transaction['payment_method'] ?? 'Cash';
                        $old_is_cash = !in_array($old_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS']);
                        $orig_metal_silver = strcasecmp($original_exchange_material, 'Silver') === 0;

                        $revert_amt = -ge_signed_due_delta($original_due_amount, $original_difference_weight);
                        $revert_metal = -$original_difference_weight;

                        if ($old_is_cash) {
                            if ($orig_metal_silver) {
                                $revert_sql = "UPDATE parties SET cash_balance = cash_balance + ?, silver_balance = silver_balance + ? WHERE id = ?";
                                $revert_stmt = $conn->prepare($revert_sql);
                                $revert_stmt->bind_param("ddi", $revert_amt, $revert_metal, $party_id);
                            } else {
                                $revert_sql = "UPDATE parties SET cash_balance = cash_balance + ? WHERE id = ?";
                                $revert_stmt = $conn->prepare($revert_sql);
                                $revert_stmt->bind_param("di", $revert_amt, $party_id);
                            }
                        } else {
                            if ($orig_metal_silver) {
                                $revert_sql = "UPDATE parties SET bank_balance = bank_balance + ?, silver_balance = silver_balance + ? WHERE id = ?";
                                $revert_stmt = $conn->prepare($revert_sql);
                                $revert_stmt->bind_param("ddi", $revert_amt, $revert_metal, $party_id);
                            } else {
                                $revert_sql = "UPDATE parties SET bank_balance = bank_balance + ? WHERE id = ?";
                                $revert_stmt = $conn->prepare($revert_sql);
                                $revert_stmt->bind_param("di", $revert_amt, $party_id);
                            }
                        }
                        $revert_stmt->execute();
                        $revert_stmt->close();
                    }

                    $is_current_cash = !in_array($payment_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS']);
                    $metal_silver = strcasecmp($exchange_material, 'Silver') === 0;
                    // Signed so a negative difference (shop owes customer) reduces the party's
                    // balance instead of always adding to it — see ge_signed_due_delta() above.
                    $signed_due_amount = ge_signed_due_delta($due_amount, $difference_weight);

                    if ($is_current_cash) {
                        if ($metal_silver) {
                            $update_party_sql = "UPDATE parties SET cash_balance = cash_balance + ?, silver_balance = silver_balance + ? WHERE id = ?";
                            $update_party_stmt = $conn->prepare($update_party_sql);
                            $update_party_stmt->bind_param("ddi", $signed_due_amount, $difference_weight, $party_id);
                        } else {
                            $update_party_sql = "UPDATE parties SET cash_balance = cash_balance + ? WHERE id = ?";
                            $update_party_stmt = $conn->prepare($update_party_sql);
                            $update_party_stmt->bind_param("di", $signed_due_amount, $party_id);
                        }
                    } else {
                        if ($metal_silver) {
                            $update_party_sql = "UPDATE parties SET bank_balance = bank_balance + ?, silver_balance = silver_balance + ? WHERE id = ?";
                            $update_party_stmt = $conn->prepare($update_party_sql);
                            $update_party_stmt->bind_param("ddi", $signed_due_amount, $difference_weight, $party_id);
                        } else {
                            $update_party_sql = "UPDATE parties SET bank_balance = bank_balance + ? WHERE id = ?";
                            $update_party_stmt = $conn->prepare($update_party_sql);
                            $update_party_stmt->bind_param("di", $signed_due_amount, $party_id);
                        }
                    }

                    if (!$update_party_stmt->execute()) {
                        throw new Exception("Failed to update party balance: " . $update_party_stmt->error);
                    }
                    $update_party_stmt->close();

                    // === MULTI-ITEM STORAGE: Save received items to exchange_items table ===
                    // Delete existing items for this transaction (if editing)
                    if ($transaction_id) {
                        $delete_items_sql = "DELETE FROM exchange_items WHERE transaction_id = ?";
                        $delete_items_stmt = $conn->prepare($delete_items_sql);
                        $delete_items_stmt->bind_param("i", $transaction_id);
                        $delete_items_stmt->execute();
                        $delete_items_stmt->close();
                    }

                    //Save received items (decode JSON from frontend)
                    if (isset($_POST['received_items']) && !empty($_POST['received_items'])) {
                        $raw_rx = $_POST['received_items'];
                        if (!is_string($raw_rx)) {
                            $raw_rx = is_array($raw_rx) ? json_encode($raw_rx) : '';
                        }
                        $received_items = json_decode($raw_rx, true);
                        $row_material_default = ge_normalize_exchange_material($exchange_material);

                        if (is_array($received_items) && count($received_items) > 0) {
                            $insert_item_sql = "INSERT INTO exchange_items (transaction_id, company_id, item_type, weight, purity, fine_weight, material) VALUES (?, ?, ?, ?, ?, ?, ?)";
                            $insert_item_stmt = $conn->prepare($insert_item_sql);

                            foreach ($received_items as $item) {
                                if (!is_array($item)) {
                                    continue;
                                }
                                $item_type = 'received';
                                $item_weight = floatval($item['weight'] ?? 0);
                                if ($item_weight <= 0) {
                                    continue;
                                }
                                $item_purity = floatval($item['purity'] ?? 0);
                                $item_fine = floatval($item['fine'] ?? $item['fine_weight'] ?? 0);
                                $mat_raw = $item['material'] ?? $item['Metal'] ?? null;
                                $item_material = $mat_raw !== null && $mat_raw !== ''
                                    ? ge_normalize_exchange_material($mat_raw)
                                    : $row_material_default;

                                $insert_item_stmt->bind_param(
                                    "iisddds",
                                    $transaction_id,
                                    $company_id,
                                    $item_type,
                                    $item_weight,
                                    $item_purity,
                                    $item_fine,
                                    $item_material
                                );

                                if (!$insert_item_stmt->execute()) {
                                    throw new Exception("Failed to save received item: " . $insert_item_stmt->error);
                                }
                            }

                            $insert_item_stmt->close();
                        }
                    }
                    // === END MULTI-ITEM STORAGE ===

                    $conn->commit();

                    // Get party name for receipt
                    $party_name_sql = "SELECT party_name FROM parties WHERE id = ?";
                    $party_stmt = $conn->prepare($party_name_sql);
                    $party_stmt->bind_param("i", $party_id);
                    $party_stmt->execute();
                    $party_name_result = $party_stmt->get_result()->fetch_assoc();
                    $party_name_for_receipt = $party_name_result['party_name'];

                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Transaction saved successfully',
                        'transaction_id' => $transaction_id,
                        'data' => [
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_name_for_receipt,
                            'date_of_transaction' => $date_of_transaction,
                            'received_weight' => $received_weight,
                            'purity' => $purity,
                            'fine_weight' => $fine_weight,
                            'issue_weight' => $issue_weight,
                            'difference_weight' => $difference_weight,
                            'rate' => gold_rate_to_display($rate, $gold_rate_unit),
                            'amount' => $amount,
                            'payment_amount' => $payment_amount,
                            'payment_method' => $payment_method,
                            'payment_status' => $payment_status,
                            'payment_type' => $payment_type,
                            'narration' => $narration
                        ]
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;

            case 'get_transaction_details':
                $id = intval($_POST['id']);
                $sql = "SELECT * FROM transactions WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $transaction = $result->fetch_assoc();
                echo json_encode($transaction);
                exit;

            case 'delete_transaction':
                $id = intval($_POST['id']);

                $conn->begin_transaction();

                try {
                    // Get transaction details first
                    $sql = "SELECT * FROM transactions WHERE id = ? FOR UPDATE";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $transaction = $result->fetch_assoc();

                    if (!$transaction) {
                        throw new Exception("Transaction not found");
                    }

                    // Delete linked payment transactions from transactions table
                    // First, revert account balances
                    $get_linked_sql = "SELECT payment_amount, payment_method, transaction_type FROM transactions WHERE company_id = ? AND narration LIKE ? AND (transaction_type = 'Payment' OR transaction_type = 'Received')";
                    $linked_pattern = "%Payment for Exchange " . $transaction['receipt_id'] . "%";
                    $get_linked_stmt = $conn->prepare($get_linked_sql);
                    $get_linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $get_linked_stmt->execute();
                    $linked_result = $get_linked_stmt->get_result();
                    while ($old_linked = $linked_result->fetch_assoc()) {
                        $old_amt = floatval($old_linked['payment_amount']);
                        $reversal_amt = ($old_linked['transaction_type'] === 'Received') ? -$old_amt : $old_amt;

                        if ($old_linked['payment_method'] === 'Cash') {
                            updateAccountBalance($conn, $company_id, 'Cash', $reversal_amt);
                        } elseif (in_array($old_linked['payment_method'], ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                            updateAccountBalance($conn, $company_id, 'Bank', $reversal_amt);
                        }
                    }

                    $delete_linked_sql = "DELETE FROM transactions WHERE company_id = ? AND narration LIKE ? AND (transaction_type = 'Payment' OR transaction_type = 'Received')";
                    $linked_stmt = $conn->prepare($delete_linked_sql);
                    $linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $linked_stmt->execute();

                    // --- REVERT RECEIVED GOLD STOCK LOGIC (MIX STOCK) ---
                    if ($transaction['received_weight'] > 0) {
                        $del_rcv_wt = floatval($transaction['received_weight']);
                        $stock_name = "MIX Stock";
                        $mix_purity = 0.00;

                        $find_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ?";
                        $find_stock_stmt = $conn->prepare($find_stock_sql);
                        $find_stock_stmt->bind_param("isd", $company_id, $stock_name, $mix_purity);
                        $find_stock_stmt->execute();
                        $rs_res = $find_stock_stmt->get_result();

                        if ($rs_res->num_rows > 0) {
                            $rs_row = $rs_res->fetch_assoc();
                            $new_rs_val = max(0, $rs_row['current_stock'] - $del_rcv_wt);
                            $upd_rs_sql = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                            $upd_rs_stmt = $conn->prepare($upd_rs_sql);
                            $upd_rs_stmt->bind_param("di", $new_rs_val, $rs_row['id']);
                            $upd_rs_stmt->execute();
                        }
                    }
                    // --- END REVERT RECEIVED GOLD STOCK LOGIC ---

                    // Restore issued fine metal to vault (same row as save/delete used)
                    $del_ex_mat = ge_normalize_exchange_material($transaction['exchange_material'] ?? 'Gold');
                    $del_issue_wt = max(0, floatval($transaction['delivered_weight'] ?? 0));
                    if ($del_issue_wt > 0) {
                        $iss_row = ge_fetch_fine_stock_for_material($conn, $company_id, $del_ex_mat);
                        if (!$iss_row) {
                            throw new Exception("Cannot delete: {$del_ex_mat} fine stock row not found (restore issue weight).");
                        }
                        $rest_issue = floatval($iss_row['current_stock']) + $del_issue_wt;
                        $stock_update = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                        $stock_stmt = $conn->prepare($stock_update);
                        $stock_stmt->bind_param("di", $rest_issue, $iss_row['id']);
                        if (!$stock_stmt->execute()) {
                            throw new Exception("Failed to restore issued fine stock");
                        }
                    }

                    // Revert party metal/cash effects (same sign as edit-revert)
                    $del_party_id = intval($transaction['party_id'] ?? 0);
                    if ($del_party_id > 0) {
                        $del_due = floatval($transaction['due_amount'] ?? 0);
                        $del_diff = floatval($transaction['difference_weight'] ?? 0);
                        $del_pm = $transaction['payment_method'] ?? 'Cash';
                        $del_cash = !in_array($del_pm, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'], true);
                        $del_silver = strcasecmp($del_ex_mat, 'Silver') === 0;
                        $rv_amt = -ge_signed_due_delta($del_due, $del_diff);
                        $rv_met = -$del_diff;
                        if ($del_cash) {
                            if ($del_silver) {
                                $p_rev = $conn->prepare("UPDATE parties SET cash_balance = cash_balance + ?, silver_balance = silver_balance + ? WHERE id = ?");
                                $p_rev->bind_param("ddi", $rv_amt, $rv_met, $del_party_id);
                            } else {
                                $p_rev = $conn->prepare("UPDATE parties SET cash_balance = cash_balance + ? WHERE id = ?");
                                $p_rev->bind_param("di", $rv_amt, $del_party_id);
                            }
                        } else {
                            if ($del_silver) {
                                $p_rev = $conn->prepare("UPDATE parties SET bank_balance = bank_balance + ?, silver_balance = silver_balance + ? WHERE id = ?");
                                $p_rev->bind_param("ddi", $rv_amt, $rv_met, $del_party_id);
                            } else {
                                $p_rev = $conn->prepare("UPDATE parties SET bank_balance = bank_balance + ? WHERE id = ?");
                                $p_rev->bind_param("di", $rv_amt, $del_party_id);
                            }
                        }
                        $p_rev->execute();
                        $p_rev->close();
                    }

                    // Finally delete the transaction
                    $delete_sql = "DELETE FROM transactions WHERE id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $id);

                    if (!$delete_stmt->execute()) {
                        throw new Exception("Failed to delete transaction");
                    }
                    $delete_stmt->close();

                    $conn->commit();

                    echo json_encode(['status' => 'success', 'message' => 'Transaction deleted successfully']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;

            case 'get_party_dues':
                $party_name = $conn->real_escape_string($_POST['party_name']);

                // Get party by name
                $party_sql = "SELECT id, (cash_balance + bank_balance) AS tot_cash, gold_balance FROM parties WHERE company_id = ? AND party_name = ?";
                $stmt = $conn->prepare($party_sql);
                $stmt->bind_param('is', $company_id, $party_name);
                $stmt->execute();
                $party_result = $stmt->get_result()->fetch_assoc();

                echo json_encode([
                    'due_amount' => floatval($party_result['tot_cash'] ?? 0),
                    'due_gold' => floatval($party_result['gold_balance'] ?? 0)
                ]);
                exit;

            case 'get_exchange_list':
                try {
                    $list_start = $_POST['start_date'] ?? date('Y-m-d');
                    $list_end = $_POST['end_date'] ?? date('Y-m-d');
                    $offset = max(0, (int) ($_POST['offset'] ?? 0));
                    $limit = min(100, max(1, (int) ($_POST['limit'] ?? 50)));
                    $list_search = trim($_POST['search'] ?? '');
                    $search_param = $list_search !== '' ? $list_search : null;
                    $total = ge_count_exchange_transactions($conn, $company_id, $list_start, $list_end, $search_param);
                    $rows = ge_fetch_exchange_transactions(
                        $conn,
                        $company_id,
                        $list_start,
                        $list_end,
                        $search_param,
                        $offset,
                        $limit,
                        $gold_rate_unit
                    );
                    $items = array_map(
                        fn($row) => ge_map_exchange_list_row($row, $gold_rate_unit),
                        $rows
                    );
                    echo json_encode([
                        'status' => 'success',
                        'items' => $items,
                        'total' => $total,
                        'offset' => $offset,
                        'limit' => $limit,
                        'has_more' => ($offset + count($items)) < $total,
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;

            case 'get_exchange_receipt_list':
                try {
                    $term = trim($_POST['term'] ?? '');
                    $offset = max(0, (int) ($_POST['offset'] ?? 0));
                    $limit = min(100, max(1, (int) ($_POST['limit'] ?? 100)));
                    $total = ge_count_exchange_receipts($conn, $company_id, $term);
                    $items = ge_fetch_exchange_receipts($conn, $company_id, $term, $offset, $limit);
                    echo json_encode([
                        'status' => 'success',
                        'items' => $items,
                        'total' => $total,
                        'offset' => $offset,
                        'limit' => $limit,
                        'has_more' => ($offset + count($items)) < $total,
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;

            case 'search_receipt_ids':
                $search = trim($_POST['term'] ?? '');
                $offset = max(0, (int) ($_POST['offset'] ?? 0));
                $limit = min(100, max(1, (int) ($_POST['limit'] ?? 100)));
                $items = ge_fetch_exchange_receipts($conn, $company_id, $search, $offset, $limit);
                $receipts = [];
                foreach ($items as $row) {
                    $receipts[] = [
                        'receipt_id' => $row['receipt_id'],
                        'date' => date('d M Y', strtotime($row['date_of_transaction'])),
                        'party_name' => $row['party_name'],
                        'received_weight' => $row['received_weight'],
                        'amount' => $row['amount'],
                        'exchange_material' => $row['exchange_material'],
                    ];
                }
                echo json_encode($receipts);
                exit;

            case 'get_next_receipt_id':
                $nextReceiptId = next_receipt_id($conn, $company_id, 'EX', ['transaction_type' => 'Exchange']);
                echo json_encode(['receipt_id' => $nextReceiptId]);
                exit;
        }
    }
}

// Get date range from user input (default: today only)
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d'); // Today by default
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Today

// Enhanced statistics SQL query with date range filter - Exchange specific
$stats_sql = "
SELECT 
    COALESCE(SUM(received_weight), 0) AS total_weight,
    COALESCE(SUM(received_weight), 0) AS total_received_weight,
    COALESCE(SUM(delivered_weight), 0) AS total_issue_gold,
    COALESCE(SUM(fine_weight), 0) AS total_fine_gold,
    COALESCE(SUM(amount), 0) AS total_amount,
    COUNT(DISTINCT party_id) AS total_parties,
    COUNT(*) AS total_transactions,
    COALESCE(SUM(CASE WHEN payment_type = 'Payment_In' THEN payment_amount ELSE 0 END), 0) AS total_paid_amount,
    COALESCE(SUM(CASE WHEN payment_type = 'Payment_Out' THEN payment_amount ELSE 0 END), 0) AS total_payment_amount,
    COALESCE(SUM(CASE WHEN payment_status IN ('Due', 'Partial') THEN due_amount ELSE 0 END), 0) AS total_due
FROM transactions
WHERE company_id = ? AND DATE(date_of_transaction) BETWEEN ? AND ? AND transaction_type = 'Exchange'";

$stats_stmt = $conn->prepare($stats_sql);
if (!$stats_stmt) {
    die("SQL Error in stats query: " . $conn->error . "<br><br>Query: " . $stats_sql);
}
$stats_stmt->bind_param("iss", $company_id, $start_date, $end_date);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Gold / Silver splits for stats cards (exchange_items + legacy txns without lines)
$stats['rcv_gold'] = 0.0;
$stats['rcv_silver'] = 0.0;
$stats['fine_gold_scrap'] = 0.0;
$stats['fine_silver_scrap'] = 0.0;
$stats['issue_gold'] = 0.0;
$stats['issue_silver'] = 0.0;

$ei_split_sql = "
SELECT
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ei.material, 'Gold'))) = 'silver' THEN ei.weight ELSE 0 END), 0) AS s_wt,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ei.material, 'Gold'))) <> 'silver' THEN ei.weight ELSE 0 END), 0) AS g_wt,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ei.material, 'Gold'))) = 'silver' THEN ei.fine_weight ELSE 0 END), 0) AS s_fn,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ei.material, 'Gold'))) <> 'silver' THEN ei.fine_weight ELSE 0 END), 0) AS g_fn
FROM exchange_items ei
INNER JOIN transactions t ON t.id = ei.transaction_id AND t.company_id = ei.company_id
WHERE t.company_id = ?
  AND DATE(t.date_of_transaction) BETWEEN ? AND ?
  AND t.transaction_type = 'Exchange'
  AND ei.item_type = 'received'";
$ei_st = $conn->prepare($ei_split_sql);
if ($ei_st) {
    $ei_st->bind_param("iss", $company_id, $start_date, $end_date);
    $ei_st->execute();
    $ei_row = $ei_st->get_result()->fetch_assoc();
    if ($ei_row) {
        $stats['rcv_silver'] += floatval($ei_row['s_wt']);
        $stats['rcv_gold'] += floatval($ei_row['g_wt']);
        $stats['fine_silver_scrap'] += floatval($ei_row['s_fn']);
        $stats['fine_gold_scrap'] += floatval($ei_row['g_fn']);
    }
    $ei_st->close();
}

$leg_split_sql = "
SELECT
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(t.exchange_material, 'Gold'))) = 'silver' THEN COALESCE(t.received_weight, 0) ELSE 0 END), 0) AS s_wt,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(t.exchange_material, 'Gold'))) <> 'silver' THEN COALESCE(t.received_weight, 0) ELSE 0 END), 0) AS g_wt,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(t.exchange_material, 'Gold'))) = 'silver' THEN COALESCE(t.fine_weight, 0) ELSE 0 END), 0) AS s_fn,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(t.exchange_material, 'Gold'))) <> 'silver' THEN COALESCE(t.fine_weight, 0) ELSE 0 END), 0) AS g_fn
FROM transactions t
WHERE t.company_id = ?
  AND DATE(t.date_of_transaction) BETWEEN ? AND ?
  AND t.transaction_type = 'Exchange'
  AND NOT EXISTS (
      SELECT 1 FROM exchange_items ei
      WHERE ei.transaction_id = t.id AND ei.item_type = 'received'
  )";
$leg_st = $conn->prepare($leg_split_sql);
if ($leg_st) {
    $leg_st->bind_param("iss", $company_id, $start_date, $end_date);
    $leg_st->execute();
    $leg_row = $leg_st->get_result()->fetch_assoc();
    if ($leg_row) {
        $stats['rcv_silver'] += floatval($leg_row['s_wt']);
        $stats['rcv_gold'] += floatval($leg_row['g_wt']);
        $stats['fine_silver_scrap'] += floatval($leg_row['s_fn']);
        $stats['fine_gold_scrap'] += floatval($leg_row['g_fn']);
    }
    $leg_st->close();
}

$issue_split_sql = "
SELECT
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(exchange_material, 'Gold'))) = 'silver' THEN COALESCE(delivered_weight, 0) ELSE 0 END), 0) AS s_is,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(exchange_material, 'Gold'))) <> 'silver' THEN COALESCE(delivered_weight, 0) ELSE 0 END), 0) AS g_is
FROM transactions
WHERE company_id = ?
  AND DATE(date_of_transaction) BETWEEN ? AND ?
  AND transaction_type = 'Exchange'";
$is_st = $conn->prepare($issue_split_sql);
if ($is_st) {
    $is_st->bind_param("iss", $company_id, $start_date, $end_date);
    $is_st->execute();
    $is_row = $is_st->get_result()->fetch_assoc();
    if ($is_row) {
        $stats['issue_silver'] = floatval($is_row['s_is']);
        $stats['issue_gold'] = floatval($is_row['g_is']);
    }
    $is_st->close();
}

// Card headline totals must match the splits: multi-item rows store weights in exchange_items,
// so SUM(received_weight)/SUM(fine_weight) on transactions is often wrong or incomplete.
$stats['total_received_weight'] = floatval($stats['rcv_gold']) + floatval($stats['rcv_silver']);
$stats['total_fine_gold'] = floatval($stats['fine_gold_scrap']) + floatval($stats['fine_silver_scrap']);
$stats['total_issue_gold'] = floatval($stats['issue_gold']) + floatval($stats['issue_silver']);

// --- Fine gold / fine silver vault stock (for stats cards) -------------------------------------
// Reuses ge_fetch_fine_stock_for_material() instead of duplicating the fine-purity/name SQL a
// third time. Gold keeps the same "any Cash row" fallback the original stats block had; Silver's
// two-step fallback already lives inside the helper.
$stats['current_stock'] = 0;
$goldStockRow = ge_fetch_fine_stock_for_material($conn, $company_id, 'Gold');
if ($goldStockRow) {
    $stats['current_stock'] = floatval($goldStockRow['current_stock']);
} else {
    $stock_fallback = "
        SELECT COALESCE(current_stock, 0) AS current_stock
        FROM gold_stock
        WHERE company_id = ? AND mode = 'Cash'
        ORDER BY id ASC
        LIMIT 1";
    $sf_stmt = $conn->prepare($stock_fallback);
    if ($sf_stmt) {
        $sf_stmt->bind_param("i", $company_id);
        $sf_stmt->execute();
        $sf_res = $sf_stmt->get_result();
        if ($sf_res && $sf_res->num_rows > 0) {
            $stats['current_stock'] = floatval($sf_res->fetch_assoc()['current_stock']);
        }
        $sf_stmt->close();
    }
}

$stats['fine_silver_stock'] = 0;
$silverStockRow = ge_fetch_fine_stock_for_material($conn, $company_id, 'Silver');
if ($silverStockRow) {
    $stats['fine_silver_stock'] = floatval($silverStockRow['current_stock']);
}

// Get cash balance (Cash In Hand) from account_balances
$cash_sql = "SELECT current_balance as cash_balance FROM account_balances WHERE company_id = ? AND account_type = 'Cash'";
$cash_stmt = $conn->prepare($cash_sql);
if ($cash_stmt) {
    $cash_stmt->bind_param("i", $company_id);
    $cash_stmt->execute();
    $cash_result = $cash_stmt->get_result();
    if ($cash_row = $cash_result->fetch_assoc()) {
        $stats['cash_balance'] = $cash_row['cash_balance'];
    } else {
        $stats['cash_balance'] = 0;
    }
    $cash_stmt->close();
} else {
    $stats['cash_balance'] = 0;
}

// Get search parameter (optional GET filter)
$search_raw = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_param = $search_raw !== '' ? $search_raw : null;
$exchange_list_initial = 100;
$exchange_list_page_size = 50;

$total_transactions = ge_count_exchange_transactions($conn, $company_id, $start_date, $end_date, $search_param);
$transactions = ge_fetch_exchange_transactions(
    $conn,
    $company_id,
    $start_date,
    $end_date,
    $search_param,
    0,
    $exchange_list_initial,
    $gold_rate_unit
);
$exchange_list_has_more = $total_transactions > count($transactions);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exchange - Mormukut</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.18/dist/sweetalert2.min.css">

    <style>
        :root {
            --primary: #FFD700;
            --primary-dark: #DAA520;
            --secondary: #2D3436;
            --success: #00B894;
            --danger: #FF7675;
            --warning: #FFEAA7;
            --info: #74B9FF;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #F8F9FA;
            color: var(--secondary);
            overflow-x: clip;
        }

        .soft-gradient-blue {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));
        }

        .soft-gradient-green {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05));
        }

        .soft-gradient-orange {
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(249, 115, 22, 0.05));
        }

        .soft-gradient-purple {
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.05));
        }

        .soft-gradient-teal {
            background: linear-gradient(135deg, rgba(20, 184, 166, 0.1), rgba(20, 184, 166, 0.05));
        }

        .soft-gradient-yellow {
            background: linear-gradient(135deg, rgba(234, 179, 8, 0.1), rgba(234, 179, 8, 0.05));
        }

        .soft-gradient-slate {
            background: linear-gradient(135deg, rgba(100, 116, 139, 0.09), rgba(100, 116, 139, 0.03));
        }

        .soft-gradient-rose {
            background: linear-gradient(135deg, rgba(244, 63, 94, 0.09), rgba(244, 63, 94, 0.03));
        }

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

        .stats-metal-split {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.15rem 0.45rem;
            font-size: 0.8125rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            line-height: 1.35;
            margin-top: 0.35rem;
            font-variant-numeric: tabular-nums;
            color: rgb(51 65 85);
        }

        .stats-metal-split .metal-seg {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
        }

        .stats-metal-split .metal-num {
            font-weight: 700;
            font-size: 0.8125rem;
        }

        .stats-metal-split .metal-unit {
            font-size: 0.6875rem;
            font-weight: 600;
            color: rgb(100 116 139);
            margin-left: 0.02rem;
        }

        .stats-metal-split .metal-icon-gold {
            color: #b45309;
            font-size: 0.625rem;
            line-height: 1;
        }

        .stats-metal-split .metal-icon-silver {
            color: #475569;
            font-size: 0.625rem;
            line-height: 1;
        }

        /* First three exchange cards: no headline total — breakdown is primary */
        .stats-metal-split--hero {
            margin-top: 0.2rem;
        }

        .stats-metal-split--hero .metal-num {
            font-size: 0.9375rem;
        }

        .stats-metal-split--hero .metal-icon-gold,
        .stats-metal-split--hero .metal-icon-silver {
            font-size: 0.6875rem;
        }

        .stats-icon-wrap {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .readonly-field {
            background-color: #F8F9FA;
            cursor: not-allowed;
        }

        #partyList {
            position: absolute !important;
            top: 100% !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            z-index: 1000 !important;
        }

        /* Compact UI Adjustments */
        @media (max-width: 1600px) {
            .compact-text {
                font-size: 0.7rem !important;
            }

            .compact-label {
                font-size: 0.65rem !important;
                margin-bottom: 0.1rem !important;
            }

            .compact-input {
                padding-top: 0.4rem !important;
                padding-bottom: 0.4rem !important;
                font-size: 0.75rem !important;
            }

            .lg\\:grid-cols-8 {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .stats-card {
                padding: 0.6rem !important;
            }

            .stats-icon {
                width: 1.75rem !important;
                height: 1.75rem !important;
            }

            .stats-icon i {
                font-size: 0.8rem !important;
            }
        }

        @media (max-width: 1280px) {
            .lg\\:grid-cols-8 {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        /* Exchange list action buttons */
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

        .ge-action-btn i {
            font-size: 10px;
            line-height: 1;
            pointer-events: none;
        }

        .ge-action-btn:hover {
            background: rgba(148, 163, 184, 0.18);
        }

        .ge-txn-table .ge-action-col {
            width: 3rem;
            min-width: 3rem;
            padding-left: 0.2rem !important;
            padding-right: 0.3rem !important;
        }

        /* Keep the Recent Transactions list within a fixed viewport height and scroll internally
           instead of growing the whole page when there are many transactions for the day. */
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

        .ge-txn-table thead th {
            white-space: nowrap;
        }

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

        /* Tighten the horizontal gap between every other column so the extra
           room freed up here can go to the Party column above instead. */
        .ge-txn-table td,
        .ge-txn-table th {
            padding-left: 0.35rem;
            padding-right: 0.35rem;
        }

        .ge-txn-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .ge-txn-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.5);
            border-radius: 3px;
        }

        .ge-txn-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        /* Compact SweetAlert2 for simple confirm / alert dialogs */
        .ge-swal-sm.swal2-popup {
            border-radius: 0.5rem;
        }

        .ge-swal-sm .swal2-title {
            font-size: 1rem !important;
            padding: 0.15rem 0 0 !important;
        }

        .ge-swal-sm .swal2-html-container {
            font-size: 0.8125rem !important;
            margin: 0.2rem 0 0 !important;
        }

        .ge-swal-sm .swal2-icon {
            margin: 0.35rem auto 0.1rem !important;
            transform: scale(0.78);
        }

        .ge-swal-sm .swal2-actions {
            margin-top: 0.65rem !important;
            gap: 0.45rem;
        }

        .ge-swal-sm .swal2-styled {
            font-size: 0.75rem !important;
            padding: 0.35rem 0.9rem !important;
            margin: 0 !important;
        }
    </style>
</head>

<body class="bg-gray-100 overflow-x-clip">
    <?php include 'components/sidebar.php'; ?>
    <?php include 'components/header.php'; ?>

    <div class="px-2 pt-1 pb-4 main-with-sidebar">
        <!-- Statistics Cards -->
        <div class="overflow-x-auto pb-1 -mx-0.5 px-0.5">
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 mb-4 min-w-0 w-full">
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Rcv. weight</p>
                        <p class="stats-metal-split stats-metal-split--hero">
                            <?= ge_render_metal_split($stats['rcv_gold'] ?? 0, $stats['rcv_silver'] ?? 0, 'Gold received weight', 'Silver received weight') ?>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-sky-100 stats-icon shrink-0">
                        <i class="fas fa-weight text-sky-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Fine (received)</p>
                        <p class="stats-metal-split stats-metal-split--hero">
                            <?= ge_render_metal_split($stats['fine_gold_scrap'] ?? 0, $stats['fine_silver_scrap'] ?? 0, 'Fine gold received', 'Fine silver received') ?>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-emerald-100 stats-icon shrink-0">
                        <i class="fas fa-coins text-emerald-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Issue weight</p>
                        <p class="stats-metal-split stats-metal-split--hero">
                            <?= ge_render_metal_split($stats['issue_gold'] ?? 0, $stats['issue_silver'] ?? 0, 'Gold issued from vault', 'Silver issued from vault') ?>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-amber-100 stats-icon shrink-0">
                        <i class="fas fa-box text-amber-700 text-xs"></i>
                    </div>
                </div>
            </div>

            <?php if (($stats['current_stock'] ?? 0) > 0): ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="stats-card-label uppercase">Fine gold stock</p>
                        <p class="stats-card-value leading-tight">
                            <?= number_format($stats['current_stock'] ?? 0, 2) ?><span
                                class="text-[10px] font-medium text-slate-500 ml-0.5">g</span></p>
                    </div>
                    <div class="stats-icon-wrap bg-violet-100 stats-icon">
                        <i class="fas fa-box-open text-violet-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (($stats['fine_silver_stock'] ?? 0) > 0): ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="stats-card-label uppercase">Fine silver</p>
                        <p class="stats-card-value leading-tight">
                            <?= number_format($stats['fine_silver_stock'] ?? 0, 2) ?><span
                                class="text-[10px] font-medium text-slate-500 ml-0.5">g</span></p>
                    </div>
                    <div class="stats-icon-wrap bg-slate-100 stats-icon">
                        <i class="fas fa-coins text-slate-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="stats-card-label uppercase">Cash</p>
                        <p class="stats-card-value leading-tight">
                            ₹<?= ge_format_inr($stats['cash_balance'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-slate-200/80 stats-icon">
                        <i class="fas fa-wallet text-slate-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="stats-card-label uppercase">Received</p>
                        <p class="stats-card-value leading-tight">
                            ₹<?= ge_format_inr($stats['total_paid_amount'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-teal-100 stats-icon">
                        <i class="fas fa-arrow-up text-teal-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="stats-card-label uppercase">Paid</p>
                        <p class="stats-card-value leading-tight">
                            ₹<?= ge_format_inr($stats['total_payment_amount'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-rose-100 stats-icon">
                        <i class="fas fa-arrow-down text-rose-500 text-xs"></i>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex flex-col lg:flex-row gap-6 min-w-0 w-full">
            <!-- Left Side - Form -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200 min-w-0 lg:flex-[1_1_55%]">
                <!-- Transaction Form -->
                <form id="exchangeForm" onsubmit="return false;"
                    class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">

                    <!-- Section 1: Transaction Details -->
                    <div class="bg-blue-50 px-3 py-1 border-b border-blue-100">
                        <h3 class="text-xs font-bold text-blue-800 flex items-center">
                            <i class="fas fa-file-invoice mr-1.5 text-xs"></i> Transaction Details
                        </h3>
                    </div>
                    <div class="p-2 grid grid-cols-12 gap-1.5">
                        <!-- Receipt ID (3 columns) -->
                        <div class="relative col-span-3">
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Receipt
                                ID</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500">
                                    <i class="fas fa-hashtag text-xs"></i>
                                </span>
                                <input type="text"
                                    class="block w-full pl-7 pr-8 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input cursor-pointer"
                                    name="receipt_id" id="receiptId" placeholder="Auto..." autocomplete="off" readonly
                                    title="Click for recent exchanges to load">
                                <button type="button"
                                    class="absolute right-1 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600 p-0.5"
                                    id="showReceiptListBtn" title="Recent exchanges / Load to edit"
                                    aria-label="Open exchange list">
                                    <i class="fas fa-history text-xs"></i>
                                </button>
                            </div>
                            <div id="receiptList"
                                class="hidden absolute z-[60] mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-72 overflow-y-auto w-[min(100%,20rem)] left-0 text-[9px] leading-tight">
                            </div>
                        </div>

                        <!-- Date (3 columns) -->
                        <div class="relative col-span-3">
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Date</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500">
                                    <i class="fas fa-calendar-alt text-xs"></i>
                                </span>
                                <input type="datetime-local"
                                    class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input"
                                    name="date_of_transaction" required>
                            </div>
                        </div>

                        <!-- Party Name (6 columns) -->
                        <div class="relative col-span-6">
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 flex items-center justify-between uppercase tracking-tighter compact-label">
                                <span>Party Name</span>
                                <!-- Outstanding Balance Inline -->
                                <span id="partyDueInfoInline" class="hidden text-[10px] font-bold tracking-tighter">
                                    <span class="text-orange-600">Bal:</span>
                                    <span class="text-red-600 ml-0.5" id="dueAmountValueInline">₹0</span>
                                    <span class="text-yellow-700 ml-0.5" id="dueGoldValueInline">0g</span>
                                </span>
                            </label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-blue-500">
                                    <i class="fas fa-user text-xs"></i>
                                </span>
                                <input type="text"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input"
                                    name="party_name" id="partyNameInput" required placeholder="Select Party"
                                    autocomplete="off">
                                <input type="hidden" name="party_id" id="partyId">
                            </div>
                            <div id="partyList"
                                class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Received Items (scrap gold / silver) -->
                    <div class="bg-blue-50 px-3 py-1 border-t border-b border-blue-100">
                        <h3 class="text-xs font-bold text-blue-800 flex items-center justify-between">
                            <span><i class="fas fa-arrow-down mr-1.5 text-xs"></i> Received Items (Gold / Silver)</span>
                            <button type="button" onclick="addReceivedItem()"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-0.5 rounded text-xs font-bold shadow-sm transition-all hover:scale-105 active:scale-95">
                                <i class="fas fa-plus mr-1"></i> Add
                            </button>
                        </h3>
                    </div>
                    <div class="p-2">
                        <div class="border border-gray-200 rounded overflow-hidden">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-100">
                                    <tr class="text-[10px] uppercase font-bold text-slate-600 tracking-tighter">
                                        <th class="px-2 py-1.5 text-left border-b w-8">#</th>
                                        <th class="px-2 py-1.5 text-left border-b min-w-[4.5rem]">Metal</th>
                                        <th class="px-2 py-1.5 text-left border-b"><i
                                                class="fas fa-weight text-blue-500 mr-1"></i>Weight (g)</th>
                                        <th class="px-2 py-1.5 text-left border-b"><i
                                                class="fas fa-percent text-orange-400 mr-1"></i>Purity (%)</th>
                                        <th class="px-2 py-1.5 text-left border-b"><i
                                                class="fas fa-gem text-emerald-500 mr-1"></i>Fine (g)</th>
                                        <th class="px-2 py-1.5 text-center border-b w-10">Act</th>
                                    </tr>
                                </thead>
                            </table>
                            <div class="overflow-y-auto" style="max-height: 120px;">
                                <table class="w-full text-xs">
                                    <tbody id="receivedItemsTable">
                                        <tr class="received-item-row group">
                                            <td class="px-2 py-1 border-b text-gray-500 font-bold item-number w-8">1
                                            </td>
                                            <td class="px-2 py-1 border-b min-w-[4.5rem]">
                                                <select class="w-full px-1 py-1 text-[10px] font-bold text-gray-800 bg-white border border-gray-200 rounded received-material compact-input">
                                                    <option value="Gold" selected>Gold</option>
                                                    <option value="Silver">Silver</option>
                                                </select>
                                            </td>
                                            <td class="px-2 py-1 border-b">
                                                <input type="number" step="0.001"
                                                    class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 received-weight"
                                                    placeholder="0.000" required>
                                            </td>
                                            <td class="px-2 py-1 border-b">
                                                <input type="number" step="0.01"
                                                    class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-yellow-400 focus:border-yellow-400 received-purity"
                                                    placeholder="0.00" required>
                                            </td>
                                            <td class="px-2 py-1 border-b">
                                                <input type="number" step="0.001"
                                                    class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-emerald-400 focus:border-emerald-400 received-fine"
                                                    placeholder="0.000">
                                            </td>
                                            <td class="px-2 py-1 border-b text-center w-10">
                                                <button type="button" onclick="removeReceivedItem(this)"
                                                    class="text-red-400 hover:text-red-600 text-xs transition-colors">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Totals, Issue & Difference -->
                    <div class="bg-orange-50 px-3 py-1 border-t border-b border-orange-100">
                        <h3 class="text-xs font-bold text-orange-800 flex items-center">
                            <i class="fas fa-exchange-alt mr-1.5 text-xs"></i> Totals, Issue & Difference
                        </h3>
                    </div>
                    <div class="p-2 grid grid-cols-2 md:grid-cols-5 gap-1.5">
                        <!-- Total Weight -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase tracking-tighter compact-label">Total
                                Wt (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-weight text-green-600 text-xs"></i>
                                </span>
                                <input type="text" id="totalReceivedWeight"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input"
                                    readonly value="0.000">
                            </div>
                        </div>

                        <!-- Total Fine -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase tracking-tighter compact-label">Fine
                                (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-gem text-teal-600 text-xs"></i>
                                </span>
                                <input type="text" id="totalReceivedFine"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input"
                                    readonly value="0.000">
                            </div>
                        </div>

                        <!-- Vault metal for issue (follows Metal column on received lines) -->
                        <div id="issueMetalDisplayWrap">
                            <label
                                class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase tracking-tighter compact-label">Issue
                                (vault)</label>
                            <input type="hidden" name="exchange_material" id="exchangeMaterialHidden" value="Gold">
                            <div id="issueMetalDisplay"
                                class="block w-full px-2 py-1.5 text-xs font-bold text-amber-900 bg-amber-50 border border-amber-200 rounded compact-input">
                                Fine gold
                            </div>
                        </div>

                        <!-- Issue Weight -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-rose-700 mb-0.5 uppercase tracking-tighter compact-label">Issue
                                (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-arrow-up text-rose-500 text-xs"></i>
                                </span>
                                <input type="number" step="0.001"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-rose-400 focus:border-rose-400 compact-input"
                                    id="issueWeightInput" placeholder="0.000" required>
                            </div>
                        </div>

                        <!-- Difference -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-blue-700 mb-0.5 uppercase tracking-tighter compact-label">Diff
                                (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-balance-scale text-blue-500 text-xs"></i>
                                </span>
                                <input type="text"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input"
                                    id="differenceWeight" readonly placeholder="0.000">
                            </div>
                        </div>
                    </div>

                    <!-- Hidden fields for backward compatibility -->
                    <input type="hidden" name="action" value="save_transaction">
                    <input type="hidden" name="transaction_id" value="">
                    <input type="hidden" name="received_weight" id="receivedWeight">
                    <input type="hidden" name="purity" id="purity">
                    <input type="hidden" name="fine_weight" id="fineWeight">
                    <input type="hidden" name="issue_weight" id="issueWeight">

                    <!-- Section 4: Payment Details -->
                    <div class="bg-emerald-50 px-3 py-1 border-t border-b border-emerald-100">
                        <h3 class="text-xs font-bold text-emerald-800 flex items-center">
                            <i class="fas fa-money-bill-wave mr-1.5 text-xs"></i> Payment Details
                        </h3>
                    </div>
                    <div class="p-2 grid grid-cols-2 md:grid-cols-4 gap-1.5">
                        <!-- Rate -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Rate
                                (<?= htmlspecialchars($gold_rate_label) ?>)</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-orange-500">
                                    <i class="fas fa-rupee-sign text-xs"></i>
                                </span>
                                <input type="number" step="0.01"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-orange-400 focus:border-orange-400 compact-input"
                                    name="rate" id="rate" required placeholder="0.00">
                            </div>
                        </div>

                        <!-- Amount -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-green-700 mb-0.5 uppercase tracking-tighter compact-label">Amount
                                (₹)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-coins text-green-600 text-xs"></i>
                                </span>
                                <input type="text"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-700 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input"
                                    name="amount" id="amount" readonly>
                            </div>
                        </div>

                        <!-- Paid Amount -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label"
                                id="paymentAmountLabel">Paid Amt (₹)</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-indigo-500">
                                    <i class="fas fa-wallet text-xs"></i>
                                </span>
                                <input type="number" step="0.01"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-indigo-400 focus:border-indigo-400 compact-input"
                                    name="payment_amount" id="paymentAmount" value="0">
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Mode</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-600">
                                    <i class="fas fa-credit-card text-xs"></i>
                                </span>
                                <select
                                    class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 focus:border-gray-400 compact-input"
                                    name="payment_method">
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer">Bank</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="UPI">UPI</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Footer: Narration & Buttons -->
                    <div
                        class="bg-gray-50 p-1.5 border-t border-gray-200 grid grid-cols-1 md:grid-cols-4 gap-1.5 items-center">
                        <div class="md:col-span-2 relative">
                            <span
                                class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500">
                                <i class="fas fa-comment-alt text-xs"></i>
                            </span>
                            <input type="text"
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input"
                                name="narration" placeholder="Narration...">
                        </div>
                        <div class="md:col-span-2 flex space-x-1">
                            <button type="submit" id="submitBtn"
                                class="flex-1 bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-3 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter">
                                <i class="fas fa-save mr-1" id="submitIcon"></i><span id="submitText">Save</span>
                            </button>
                            <button type="button" id="deleteBtn"
                                class="hidden px-2.5 py-1.5 bg-gradient-to-r from-red-600 to-red-700 text-white text-[10px] font-bold rounded hover:from-red-700 hover:to-red-800 shadow-sm"
                                title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            <button type="button" id="resetFormBtn"
                                class="px-2.5 py-1.5 bg-white border border-gray-300 text-gray-700 text-[10px] font-bold rounded hover:bg-gray-50 shadow-sm"
                                title="Reset">
                                <i class="fas fa-undo"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Hidden fields to maintain structure logic -->
                    <input type="hidden" name="payment_status" value="Due">
                    <input type="hidden" name="payment_type" id="paymentType" value="Payment_In">
                </form>
            </div>

            <!-- Right Side - Transactions List -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200 min-w-0 lg:flex-[1_1_45%]">
                <div class="bg-blue-50 px-3 py-1.5 border-b border-blue-100 rounded-t-lg">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-xs font-bold text-blue-800 flex items-center">
                            <i class="fas fa-list mr-1.5 text-xs"></i>
                            Recent Transactions
                        </h2>
                        <!-- Compact Date Range Filter -->
                        <form method="GET" action="" id="dateRangeForm" class="flex items-center gap-1.5">
                            <input type="date" name="start_date" id="startDate"
                                value="<?= htmlspecialchars($start_date) ?>"
                                class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 focus:border-blue-400 bg-white font-medium"
                                max="<?= date('Y-m-d') ?>" title="From Date">
                            <span class="text-gray-400 text-[10px] font-bold">to</span>
                            <input type="date" name="end_date" id="endDate" value="<?= htmlspecialchars($end_date) ?>"
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
                    <div class="ge-txn-scroll" id="geTxnScroll">
                        <table class="w-full text-sm text-left text-gray-500 ge-txn-table">
                            <thead class="bg-slate-50 border-b border-slate-100">
                                <tr>
                                    <th class="py-2 px-1 text-center text-[9px] font-bold text-slate-500 ge-serial-col">#</th>
                                    <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 w-16">Id</th>
                                    <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 ge-party-col">Party</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Rcv.Wt</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Fine Wt</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Issue Wt</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Amount</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500 ge-action-col">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="recentTransactionList">
                                <?php if (count($transactions) > 0):
                                    foreach ($transactions as $index => $t):
                                        $serial = $index + 1;
                                        $isPaymentIn = $t['payment_type'] === 'Payment_In';
                                        $ex_mat_list = strtolower(trim($t['exchange_material'] ?? 'Gold'));
                                        $is_silver_row = ($ex_mat_list === 'silver');
                                        $display_purity = ($t['item_purity'] !== null && $t['item_purity'] !== '')
                                            ? floatval($t['item_purity'])
                                            : floatval($t['purity']);
                                        $row_items = ge_parse_exchange_items_concat($t['items_concat'] ?? null);
                                        $is_multi_item_row = count($row_items) > 1;

                                        // Payment Column Logic (Shows ACTUAL Paid Amount)
                                        $paidAmount = $t['payment_amount'];
                                        if ($paidAmount > 0) {
                                            if ($isPaymentIn) {
                                                $payDisplay = '<span class="text-green-600 font-bold">₹' . number_format($paidAmount, 0) . '</span>';
                                            } else {
                                                $payDisplay = '<span class="text-red-500 font-bold">- ₹' . number_format($paidAmount, 0) . '</span>';
                                            }
                                        } else {
                                            $payDisplay = '<span class="text-gray-400 font-medium">-</span>';
                                        }

                                        // Difference color
                                        $diffColor = $t['difference_weight'] > 0 ? 'text-green-600' : ($t['difference_weight'] < 0 ? 'text-red-600' : 'text-gray-600');
                                        ?>
                                        <tr
                                            class="ge-txn-row hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0">
                                            <!-- Serial -->
                                            <td class="py-1.5 px-1 align-top text-center ge-serial-col">
                                                <span class="text-[9px] font-bold text-slate-400 tabular-nums"><?= $serial ?></span>
                                            </td>

                                            <!-- ID, Date & Time -->
                                            <td class="py-1.5 px-2 align-top group">
                                                <div
                                                    class="text-[10px] font-bold text-blue-600 group-hover:underline truncate flex items-center gap-0.5">
                                                    <span class="truncate">#<?= htmlspecialchars($t['receipt_id']) ?></span>
                                                    <?php if ($is_silver_row): ?>
                                                        <i class="fas fa-coins text-slate-600 text-[9px] shrink-0"
                                                            title="Silver (vault issue)" aria-hidden="true"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-coins text-amber-600 text-[9px] shrink-0"
                                                            title="Gold (vault issue)" aria-hidden="true"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-[8px] font-semibold text-slate-400 leading-tight tabular-nums whitespace-nowrap">
                                                    <?= date('d M', strtotime($t['date_of_transaction'])) ?> · <?= date('h:i A', strtotime($t['date_of_transaction'])) ?>
                                                </div>
                                            </td>

                                            <!-- Party -->
                                            <td class="py-1.5 px-2 align-top ge-party-col">
                                                <div
                                                    class="text-[10px] font-semibold text-slate-800 truncate uppercase"
                                                    title="<?= htmlspecialchars($t['party_name']) ?>">
                                                    <?= htmlspecialchars($t['party_name']) ?></div>
                                            </td>

                                            <!-- Weight & Purity -->
                                            <td class="py-1.5 px-2 align-top text-right">
                                                <?php if ($is_multi_item_row): ?>
                                                    <div class="space-y-0.5">
                                                        <?php foreach ($row_items as $ri): ?>
                                                            <div class="flex items-baseline justify-end gap-1 whitespace-nowrap">
                                                                <span class="text-[9px] font-bold text-slate-700 leading-none"><?= number_format($ri['weight'], 3) ?><span
                                                                        class="text-[7px] font-normal ml-0.5">g</span></span>
                                                                <span class="text-[7px] font-bold text-slate-400 uppercase"><?= number_format($ri['purity'], 2) ?>%</span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-[10px] font-bold text-slate-700 leading-none">
                                                        <?= number_format($t['received_weight'], 3) ?><span
                                                            class="text-[8px] font-normal ml-0.5">g</span></div>
                                                    <div class="text-[8px] font-bold text-slate-400 uppercase mt-0.5">
                                                        <?= number_format($display_purity, 2) ?>%</div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Fine & Rate -->
                                            <td class="py-1.5 px-2 align-top text-right">
                                                <?php if ($is_multi_item_row): ?>
                                                    <div class="space-y-0.5">
                                                        <?php foreach ($row_items as $ri): ?>
                                                            <div class="text-[9px] font-semibold text-amber-600 leading-none whitespace-nowrap">
                                                                <?= number_format($ri['fine'], 3) ?><span
                                                                    class="text-[7px] font-normal ml-0.5">g</span></div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="text-[8px] font-medium text-slate-400 uppercase mt-0.5">@
                                                        ₹<?= number_format(gold_rate_to_display(floatval($t['rate']), $gold_rate_unit), 0) ?><?= htmlspecialchars($gold_rate_suffix) ?></div>
                                                <?php else: ?>
                                                    <div class="text-[10px] font-semibold text-amber-600 leading-none">
                                                        <?= number_format($t['fine_weight'], 3) ?><span
                                                            class="text-[8px] font-normal ml-0.5">g</span></div>
                                                    <div class="text-[8px] font-medium text-slate-400 uppercase mt-0.5">@
                                                        ₹<?= number_format(gold_rate_to_display(floatval($t['rate']), $gold_rate_unit), 0) ?><?= htmlspecialchars($gold_rate_suffix) ?></div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Issue & Diff -->
                                            <td class="py-1.5 px-2 align-top text-right">
                                                <div class="text-[10px] font-semibold text-slate-600 leading-none">
                                                    <?= number_format($t['delivered_weight'], 3) ?><span
                                                        class="text-[8px] font-normal ml-0.5">g</span></div>
                                                <div class="text-[8px] font-bold <?= $diffColor ?> uppercase mt-0.5">
                                                    <?= $t['difference_weight'] > 0 ? '+' : '' ?>        <?= number_format($t['difference_weight'], 3) ?>
                                                </div>
                                            </td>

                                            <!-- Bill & Status -->
                                            <td class="py-1.5 px-2 align-top text-right">
                                                <div class="text-[10px] font-bold text-slate-800 leading-none">
                                                    ₹<?= number_format($t['amount'], 0) ?></div>
                                                <div class="mt-1">
                                                    <?php if ($t['payment_amount'] >= $t['amount']): ?>
                                                        <span
                                                            class="text-[7.5px] px-1 py-0.5 rounded bg-green-100 text-green-700 font-bold uppercase tracking-tighter">Paid</span>
                                                    <?php else: ?>
                                                        <span
                                                            class="text-[7.5px] px-1 py-0.5 rounded bg-rose-100 text-rose-700 font-bold uppercase tracking-tighter">Due</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Actions -->
                                            <td class="py-1.5 px-2 align-top ge-action-col whitespace-nowrap">
                                                <div class="flex items-center justify-end gap-0.5">
                                                    <button type="button" onclick="event.stopPropagation(); loadTransaction(<?= (int)$t['id'] ?>);"
                                                        class="ge-action-btn text-blue-500 hover:text-blue-700" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button"
                                                        onclick="event.stopPropagation(); openExchangeReceiptPrint(<?= (int)$t['id'] ?>); return false;"
                                                        class="ge-action-btn print-exchange-receipt text-emerald-600 hover:text-emerald-800"
                                                        data-id="<?= (int)$t['id'] ?>" title="Print Receipt">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-inbox text-2xl mb-2"></i><br>
                                            No transactions found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Pass company name to the unified JS file
        const companyName = '<?php echo $_SESSION['company_name'] ?? 'Gold Trading Company'; ?>';
        window.companyId = <?= (int) $company_id ?>;
        window.GOLD_RATE_CONFIG = <?= json_encode(gold_rate_js_config($gold_rate_unit)) ?>;
        window.EXCHANGE_LIST_CONFIG = <?= json_encode([
            'startDate' => $start_date,
            'endDate' => $end_date,
            'goldRateSuffix' => $gold_rate_suffix,
            'initialOffset' => count($transactions),
            'total' => $total_transactions,
            'hasMore' => $exchange_list_has_more,
            'pageSize' => $exchange_list_page_size,
            'search' => $search_raw,
        ]) ?>;
    </script>
    <script src="js/gold-rate-utils.js"></script>
    <!-- Everything else (party search, receipt search, multi-item rows, save/edit/delete,
         add-party modal, clear-party button) now lives in this ONE file. -->
    <script src="js/exchange.js?v=<?= filemtime(__DIR__ . '/js/exchange.js') ?>"></script>
</body>

</html>
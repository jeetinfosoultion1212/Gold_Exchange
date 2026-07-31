<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Load database configuration
require_once __DIR__ . '/config/database.php';

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$company_id = $_SESSION['company_id'];
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$r_ft = @$conn->query("SHOW COLUMNS FROM transactions LIKE 'fine_transferred'");
if ($r_ft && $r_ft->num_rows === 0) {
    @$conn->query("ALTER TABLE transactions ADD COLUMN fine_transferred DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'Fine g moved to fine stock'");
}
if ($r_ft) {
    $r_ft->free();
}

/**
 * Small helper: run a prepared SELECT with (company_id, start_date, end_date) bound as (i,s,s)
 * and return all rows. Keeps the seven tab queries below short and consistent.
 */
function report_fetch(mysqli $conn, string $sql, int $company_id, string $start_date, string $end_date): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('report.php query failed: ' . $conn->error . ' | SQL: ' . $sql);
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
}

// Fetch Party-wise Summary (Overview tab)
$sql = "SELECT 
            p.party_name,
            p.contact_no,
            SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END) as booking_weight,
            SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END) as sale_weight,
            SUM(CASE WHEN t.transaction_type = 'Purchase' THEN t.gold_weight ELSE 0 END) as purchase_weight,
            SUM(CASE WHEN t.transaction_type = 'Exchange' THEN t.gold_weight ELSE 0 END) as issue_weight,
            SUM(CASE WHEN t.transaction_type = 'Exchange' THEN t.received_weight ELSE 0 END) as exchange_received_weight,
            SUM(CASE WHEN t.transaction_type = 'Received' THEN t.gold_weight ELSE 0 END) as gold_received_weight,
            
            -- Payment In (Received from Party)
            SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND (t.payment_method = 'Cash' OR t.payment_method IS NULL OR t.payment_method = '') THEN t.payment_amount ELSE 0 END) as cash_in,
            SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_method IN ('Bank', 'UPI', 'Cheque') THEN t.payment_amount ELSE 0 END) as bank_in,
            
            -- Payment Out (Paid to Party)
            SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Purchase') AND t.payment_type = 'Payment_Out' AND (t.payment_method = 'Cash' OR t.payment_method IS NULL OR t.payment_method = '') THEN t.payment_amount ELSE 0 END) as cash_out,
            SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Purchase') AND t.payment_type = 'Payment_Out' AND t.payment_method IN ('Bank', 'UPI', 'Cheque') THEN t.payment_amount ELSE 0 END) as bank_out
            
        FROM parties p
        LEFT JOIN transactions t ON p.id = t.party_id 
            AND t.company_id = $company_id 
            AND DATE(t.date_of_transaction) BETWEEN '$start_date' AND '$end_date'
        WHERE p.company_id = $company_id
        GROUP BY p.id
        HAVING booking_weight > 0 OR sale_weight > 0 OR purchase_weight > 0 OR issue_weight > 0 OR gold_received_weight > 0 OR cash_in > 0 OR bank_in > 0 OR cash_out > 0 OR bank_out > 0
        ORDER BY p.party_name";

$result = $conn->query($sql);
$reports = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
}

// --- Per-transaction-type tabs (Bookings / Exchanges / Sales / Purchases / Received / Payments) ---
$tab_bookings = report_fetch($conn, "
    SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.gold_weight, t.purity, t.rate, t.gold_amount, t.booking_type
    FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
    WHERE t.company_id = ? AND t.transaction_type = 'Booking' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ORDER BY t.date_of_transaction DESC, t.id DESC
", $company_id, $start_date, $end_date);

$tab_exchanges = report_fetch($conn, "
    SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.received_weight, t.purity, t.fine_weight, t.fine_transferred,
           t.delivered_weight, t.difference_weight, t.amount, t.exchange_material
    FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
    WHERE t.company_id = ? AND t.transaction_type = 'Exchange' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ORDER BY t.date_of_transaction DESC, t.id DESC
", $company_id, $start_date, $end_date);

$ex_total_rcv = 0.0;
$ex_total_fine = 0.0;
$ex_total_issue = 0.0;
$ex_total_diff = 0.0;
$ex_total_amt = 0.0;
$ex_purity_wsum = 0.0;
$ex_total_transferable = 0.0;
$ex_total_transferred = 0.0;
$ex_total_rcv_pending = 0.0;
foreach ($tab_exchanges as $ex_row) {
    $ex_rcv = (float) $ex_row['received_weight'];
    $ex_fn = (float) $ex_row['fine_weight'];
    $ex_xf = (float) ($ex_row['fine_transferred'] ?? 0);
    $ex_total_rcv += $ex_rcv;
    $ex_total_fine += $ex_fn;
    $ex_total_issue += (float) $ex_row['delivered_weight'];
    $ex_total_diff += (float) $ex_row['difference_weight'];
    $ex_total_amt += (float) $ex_row['amount'];
    $ex_purity_wsum += $ex_rcv * (float) $ex_row['purity'];
    $ex_total_transferred += $ex_xf;
    $pending_fn = max(0, $ex_fn - $ex_xf);
    $ex_total_transferable += $pending_fn;
    if ($pending_fn > 0.0005 && $ex_fn > 0.0005) {
        $ex_total_rcv_pending += $ex_rcv * ($pending_fn / $ex_fn);
    } elseif ($pending_fn > 0.0005) {
        $ex_total_rcv_pending += $ex_rcv;
    }
}
$ex_avg_purity = $ex_total_rcv > 0.0005 ? $ex_purity_wsum / $ex_total_rcv : 0.0;

$tab_sales = report_fetch($conn, "
    SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.gold_weight, t.purity, t.rate, t.gold_amount, t.payment_amount,
           COALESCE(t.mode, t.receipt_method, t.booking_type, 'Cash') AS sale_mode,
           (SELECT GROUP_CONCAT(DISTINCT gsi.stock_name ORDER BY gsi.id SEPARATOR ', ') FROM gold_sale_items gsi WHERE gsi.transaction_id = t.id) AS stock_names
    FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
    WHERE t.company_id = ? AND t.transaction_type = 'Sale' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ORDER BY t.date_of_transaction DESC, t.id DESC
", $company_id, $start_date, $end_date);

$tab_purchases = report_fetch($conn, "
    SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.gold_weight, t.purity, t.rate, t.gold_amount, t.payment_amount, t.payment_method
    FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
    WHERE t.company_id = ? AND t.transaction_type = 'Purchase' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ORDER BY t.date_of_transaction DESC, t.id DESC
", $company_id, $start_date, $end_date);

$tab_received = report_fetch($conn, "
    SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.payment_amount, t.payment_method, t.narration
    FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
    WHERE t.company_id = ? AND t.transaction_type = 'Received' AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ORDER BY t.date_of_transaction DESC, t.id DESC
", $company_id, $start_date, $end_date);

$tab_payments = report_fetch($conn, "
    SELECT t.id, t.receipt_id, t.date_of_transaction, p.party_name, t.payment_amount, t.payment_method, t.payment_type, t.narration
    FROM transactions t LEFT JOIN parties p ON t.party_id = p.id
    WHERE t.company_id = ? AND t.transaction_type = 'Payment' AND t.party_id IS NOT NULL AND DATE(t.date_of_transaction) BETWEEN ? AND ?
    ORDER BY t.date_of_transaction DESC, t.id DESC
", $company_id, $start_date, $end_date);

// Cash/Bank Balance (All Time) - Fetched from account_balances table
$balance_sql = "SELECT 
    COALESCE(SUM(CASE WHEN account_type = 'Cash' THEN current_balance ELSE 0 END), 0) as cash_balance,
    COALESCE(SUM(CASE WHEN account_type = 'Bank' THEN current_balance ELSE 0 END), 0) as bank_balance
FROM account_balances
WHERE company_id = $company_id";
$balance_result = $conn->query($balance_sql);
$balance_data = $balance_result->fetch_assoc();

// Stock by Category and Mode
$stock_sql = "SELECT id, category, mode, stock_name, purity, current_stock FROM gold_stock WHERE company_id = $company_id ORDER BY category ASC, mode ASC, purity DESC";
$stock_result = $conn->query($stock_sql);
$gold_stocks = [];
if ($stock_result) {
    while ($row = $stock_result->fetch_assoc()) {
        $gold_stocks[] = $row;
    }
}

$has_mix_gold_stock = false;
$has_mix_silver_stock = false;
foreach ($gold_stocks as $s) {
    if (stripos($s['stock_name'], 'mix') === false) {
        continue;
    }
    if ($s['category'] === 'Gold') {
        $has_mix_gold_stock = true;
    } elseif ($s['category'] === 'Silver') {
        $has_mix_silver_stock = true;
    }
}

// Mix stock card: exchange received in date range, split gold vs silver (exchange_items + legacy rows)
$mix_rcv_gold = 0.0;
$mix_rcv_silver = 0.0;
$mix_fine_gold = 0.0;
$mix_fine_silver = 0.0;

$mix_ei_sql = "
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
$mix_ei_st = $conn->prepare($mix_ei_sql);
if ($mix_ei_st) {
    $mix_ei_st->bind_param('iss', $company_id, $start_date, $end_date);
    $mix_ei_st->execute();
    $mix_ei_row = $mix_ei_st->get_result()->fetch_assoc();
    if ($mix_ei_row) {
        $mix_rcv_silver += (float) $mix_ei_row['s_wt'];
        $mix_rcv_gold += (float) $mix_ei_row['g_wt'];
        $mix_fine_silver += (float) $mix_ei_row['s_fn'];
        $mix_fine_gold += (float) $mix_ei_row['g_fn'];
    }
    $mix_ei_st->close();
}

$mix_leg_sql = "
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
$mix_leg_st = $conn->prepare($mix_leg_sql);
if ($mix_leg_st) {
    $mix_leg_st->bind_param('iss', $company_id, $start_date, $end_date);
    $mix_leg_st->execute();
    $mix_leg_row = $mix_leg_st->get_result()->fetch_assoc();
    if ($mix_leg_row) {
        $mix_rcv_silver += (float) $mix_leg_row['s_wt'];
        $mix_rcv_gold += (float) $mix_leg_row['g_wt'];
        $mix_fine_silver += (float) $mix_leg_row['s_fn'];
        $mix_fine_gold += (float) $mix_leg_row['g_fn'];
    }
    $mix_leg_st->close();
}

$show_synth_mix_gold = !$has_mix_gold_stock
    && ($has_mix_silver_stock || $mix_rcv_gold > 0.0005 || $mix_fine_gold > 0.0005);
$show_synth_mix_silver = !$has_mix_silver_stock
    && ($has_mix_gold_stock || $mix_rcv_silver > 0.0005 || $mix_fine_silver > 0.0005);

// Tally sums for the Overview footer row
$total_booking = 0; $total_sale = 0; $total_purchase = 0; $total_issue = 0;
$total_exchange_rcv = 0; $total_gold_rcv = 0;
$total_cash_in = 0; $total_bank_in = 0; $total_cash_out = 0; $total_bank_out = 0;
foreach ($reports as $row) {
    $total_booking += $row['booking_weight'];
    $total_sale += $row['sale_weight'];
    $total_purchase += $row['purchase_weight'];
    $total_issue += $row['issue_weight'];
    $total_exchange_rcv += $row['exchange_received_weight'];
    $total_gold_rcv += $row['gold_received_weight'];
    $total_cash_in += $row['cash_in'];
    $total_bank_in += $row['bank_in'];
    $total_cash_out += $row['cash_out'];
    $total_bank_out += $row['bank_out'];
}

$page_title = "Daily Report";
ob_start();
?>
<style>
    /* Shared stats-card look (matches gold_exchange.php / book.php / sell.php) */
    .stats-card-label { font-size: 10px; font-weight: 500; letter-spacing: 0.02em; color: rgb(100 116 139); }
    .stats-card-value { font-size: 1rem; font-weight: 600; color: rgb(51 65 85); font-variant-numeric: tabular-nums; }
    .stats-metal-split { display: flex; flex-wrap: wrap; align-items: center; gap: 0.15rem 0.45rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.02em; line-height: 1.35; margin-top: 0.35rem; font-variant-numeric: tabular-nums; color: rgb(51 65 85); }
    .stats-metal-split .metal-seg { display: inline-flex; align-items: center; gap: 0.2rem; }
    .stats-metal-split .metal-num { font-weight: 700; font-size: 0.8125rem; }
    .stats-metal-split .metal-unit { font-size: 0.6875rem; font-weight: 600; color: rgb(100 116 139); margin-left: 0.02rem; }
    .stats-metal-split .metal-icon-gold { color: #b45309; font-size: 0.625rem; line-height: 1; }
    .stats-metal-split .metal-icon-silver { color: #475569; font-size: 0.625rem; line-height: 1; }
    .stats-icon-wrap { width: 2rem; height: 2rem; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; }

    /* Card action menu ("...") */
    .card-menu-btn { width: 1.4rem; height: 1.4rem; border-radius: 0.375rem; display: inline-flex; align-items: center; justify-content: center; color: rgb(148 163 184); }
    .card-menu-btn:hover { background: rgb(241 245 249); color: rgb(71 85 105); }
    .card-menu { position: absolute; right: 0.4rem; top: 2.1rem; width: 10rem; background: #fff; border: 1px solid rgb(226 232 240); border-radius: 0.5rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.08); z-index: 30; overflow: hidden; }
    .card-menu button { display: flex; align-items: center; gap: 0.45rem; width: 100%; text-align: left; padding: 0.45rem 0.65rem; font-size: 10.5px; font-weight: 700; color: rgb(51 65 85); }
    .card-menu button:hover { background: rgb(248 250 252); }
    .card-menu button.danger { color: #dc2626; }
    .card-menu button.danger:hover { background: #fef2f2; }
    .card-menu button i { width: 12px; font-size: 10px; }

    /* Tabs */
    .report-tab-btn { padding: 0.45rem 0.85rem; font-size: 11px; font-weight: 700; letter-spacing: 0.02em; text-transform: uppercase; color: rgb(100 116 139); border-bottom: 2px solid transparent; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.4rem; }
    .report-tab-btn:hover { color: rgb(30 41 59); }
    .report-tab-btn.active { color: rgb(37 99 235); border-bottom-color: rgb(37 99 235); }
    .report-tab-count { font-size: 9.5px; font-weight: 800; background: rgb(241 245 249); color: rgb(100 116 139); border-radius: 9999px; padding: 0.05rem 0.4rem; }
    .report-tab-btn.active .report-tab-count { background: rgb(219 234 254); color: rgb(37 99 235); }

    .rtable { width: 100%; font-size: 11px; }
    .rtable thead th { padding: 0.55rem 0.75rem; text-align: left; font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: rgb(100 116 139); background: rgb(248 250 252); border-bottom: 1px solid rgb(226 232 240); }
    .rtable thead th.num { text-align: right; }
    .rtable tbody td { padding: 0.5rem 0.75rem; border-bottom: 1px solid rgb(241 245 249); vertical-align: top; }
    .rtable tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .rtable tbody tr:hover { background: rgb(248 250 252); }
    .badge { display: inline-block; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; padding: 0.1rem 0.4rem; border-radius: 0.25rem; }
    .ex-fine-input { width: 4.75rem; text-align: right; font-size: 10px; font-weight: 700; padding: 0.15rem 0.3rem; border: 1px solid rgb(226 232 240); border-radius: 0.25rem; color: rgb(180 83 9); background: #fff; font-variant-numeric: tabular-nums; }
    .ex-fine-input:focus { outline: none; border-color: rgb(251 191 36); box-shadow: 0 0 0 2px rgba(251,191,36,.25); }
    .ex-fine-input.is-transferred { color: rgb(100 116 139); background: rgb(248 250 252); }
    .ex-transfer-btn { font-size: 9px; font-weight: 800; text-transform: uppercase; padding: 0.15rem 0.4rem; border-radius: 0.25rem; border: 1px solid rgb(251 191 36); color: rgb(180 83 9); background: rgb(255 251 235); white-space: nowrap; }
    .ex-transfer-btn:hover { background: rgb(254 243 199); }
    .ex-transfer-btn:disabled { opacity: 0.45; cursor: not-allowed; }
    .ex-xferred-tag { font-size: 8px; font-weight: 700; color: rgb(16 185 129); text-transform: uppercase; }
</style>

<div class="w-full">

    <!-- ============ STATS CARDS (unified look) ============ -->
    <div class="overflow-x-auto pb-1 mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 min-w-[40rem] sm:min-w-0">

            <!-- Cash -->
            <?php if ((float) ($balance_data['cash_balance'] ?? 0) > 0.0005): ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card relative">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Cash In Hand</p>
                        <p class="stats-card-value leading-tight">&#8377;<?= number_format($balance_data['cash_balance'] ?? 0) ?></p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <div class="stats-icon-wrap bg-emerald-100 shrink-0">
                            <i class="fas fa-wallet text-emerald-700 text-xs"></i>
                        </div>
                        <button type="button" class="card-menu-btn" onclick="toggleCardMenu(event, 'menu-cash')" aria-label="Cash actions"><i class="fas fa-ellipsis-v text-[10px]"></i></button>
                    </div>
                </div>
                <div id="menu-cash" class="card-menu hidden">
                    <button onclick="closeAllCardMenus(); openAddCashModal();"><i class="fas fa-plus"></i> Add Cash</button>
                    <button onclick="closeAllCardMenus(); openTransactionsModal('cash');"><i class="fas fa-clock-rotate-left"></i> History</button>
                    <button class="danger" onclick="closeAllCardMenus(); openResetCashModal();"><i class="fas fa-rotate-left"></i> Reset</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Bank -->
            <?php if ((float) ($balance_data['bank_balance'] ?? 0) > 0.0005): ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card relative">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Bank Balance</p>
                        <p class="stats-card-value leading-tight">&#8377;<?= number_format($balance_data['bank_balance'] ?? 0) ?></p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <div class="stats-icon-wrap bg-sky-100 shrink-0">
                            <i class="fas fa-university text-sky-700 text-xs"></i>
                        </div>
                        <button type="button" class="card-menu-btn" onclick="toggleCardMenu(event, 'menu-bank')" aria-label="Bank actions"><i class="fas fa-ellipsis-v text-[10px]"></i></button>
                    </div>
                </div>
                <div id="menu-bank" class="card-menu hidden">
                    <button onclick="closeAllCardMenus(); openAddBankModal();"><i class="fas fa-plus"></i> Add Bank</button>
                    <button onclick="closeAllCardMenus(); openTransactionsModal('bank');"><i class="fas fa-clock-rotate-left"></i> History</button>
                    <button class="danger" onclick="closeAllCardMenus(); openResetBankModal();"><i class="fas fa-rotate-left"></i> Reset</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Dynamic stock cards -->
            <?php foreach ($gold_stocks as $stock):
                $is_gold = $stock['category'] === 'Gold';
                $is_cash = $stock['mode'] === 'Cash';
                $is_mix_stock = (stripos($stock['stock_name'], 'mix') !== false);
                if ($is_mix_stock) {
                    $mix_line_rcv_chk = $is_gold ? $mix_rcv_gold : $mix_rcv_silver;
                    $mix_line_fn_chk = $is_gold ? $mix_fine_gold : $mix_fine_silver;
                    if ($mix_line_fn_chk <= 0.0005 && $mix_line_rcv_chk <= 0.0005 && (float) $stock['current_stock'] <= 0.0005) {
                        continue;
                    }
                } elseif ((float) $stock['current_stock'] <= 0.0005) {
                    continue;
                }
                $menu_id = 'menu-stock-' . (int) $stock['id'];
                $stock_name_js = htmlspecialchars($stock['stock_name'], ENT_QUOTES, 'UTF-8');
            ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card relative">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase truncate inline-flex items-center gap-1 flex-wrap">
                            <i class="fas fa-coins <?= $is_gold ? 'text-amber-600' : 'text-slate-500' ?> text-[10px] shrink-0" aria-hidden="true"></i>
                            <span class="truncate"><?= $is_mix_stock ? ($is_gold ? 'Mix Gold' : 'Mix Silver') : htmlspecialchars($stock['stock_name']) ?></span>
                            <?php if (!$is_mix_stock && (float) $stock['purity'] > 0): ?>
                            <span class="text-[9px] font-semibold normal-case text-slate-400 tracking-normal"><?= number_format((float) $stock['purity'], 2) ?>%</span>
                            <?php endif; ?>
                        </p>
                        <?php if ($is_mix_stock):
                            $mix_line_rcv = $is_gold ? $mix_rcv_gold : $mix_rcv_silver;
                            $mix_line_fn = $is_gold ? $mix_fine_gold : $mix_fine_silver;
                        ?>
                        <p class="stats-card-value leading-tight"><?= number_format($mix_line_fn, 2) ?><span class="text-[10px] font-medium text-slate-500 ml-0.5">g fine</span></p>
                        <p class="stats-metal-split">
                            <span class="metal-seg" title="Received this period">
                                <i class="fas fa-arrow-down metal-icon-gold" aria-hidden="true"></i>
                                <span class="metal-num"><?= number_format($mix_line_rcv, 2) ?></span><span class="metal-unit">g rcv</span>
                            </span>
                        </p>
                        <?php else: ?>
                        <p class="stats-card-value leading-tight"><?= number_format((float) $stock['current_stock'], 2) ?><span class="text-[10px] font-medium text-slate-500 ml-0.5">g</span></p>
                        <p class="stats-metal-split">
                            <span class="metal-seg" title="<?= $is_cash ? 'Cash (Kachha)' : 'Bank (Pakka)' ?>">
                                <i class="fas <?= $is_cash ? 'fa-wallet metal-icon-gold' : 'fa-university metal-icon-silver' ?>" aria-hidden="true"></i>
                            </span>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <div class="stats-icon-wrap <?= $is_gold ? 'bg-amber-100' : 'bg-slate-100' ?> shrink-0">
                            <i class="fas fa-coins <?= $is_gold ? 'text-amber-700' : 'text-slate-600' ?> text-xs"></i>
                        </div>
                        <button type="button" class="card-menu-btn" onclick="toggleCardMenu(event, '<?= $menu_id ?>')" aria-label="Stock actions"><i class="fas fa-ellipsis-v text-[10px]"></i></button>
                    </div>
                </div>
                <div id="<?= $menu_id ?>" class="card-menu hidden">
                    <button onclick="closeAllCardMenus(); openAddStockModal(<?= (int) $stock['id'] ?>, '<?= $stock_name_js ?>', <?= (float) $stock['purity'] ?>, '<?= $stock['category'] ?>', '<?= $stock['mode'] ?>');"><i class="fas fa-plus"></i> Add Weight</button>
                    <button onclick="closeAllCardMenus(); openTransactionsModal('stock', <?= (int) $stock['id'] ?>);"><i class="fas fa-clock-rotate-left"></i> History</button>
                    <button class="danger" onclick="closeAllCardMenus(); openResetStockModal(<?= (int) $stock['id'] ?>, '<?= $stock_name_js ?>', <?= (float) $stock['purity'] ?>);"><i class="fas fa-rotate-left"></i> Reset</button>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Synthetic (placeholder) Mix cards when no real row exists yet -->
            <?php if ($show_synth_mix_gold): ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-dashed border-amber-200 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase inline-flex items-center gap-1"><i class="fas fa-coins text-amber-600 text-[10px]" aria-hidden="true"></i> Mix Gold</p>
                        <p class="stats-card-value leading-tight"><?= number_format($mix_fine_gold, 2) ?><span class="text-[10px] font-medium text-slate-500 ml-0.5">g fine</span></p>
                        <p class="stats-metal-split"><span class="metal-seg" title="Received this period"><i class="fas fa-arrow-down metal-icon-gold"></i><span class="metal-num"><?= number_format($mix_rcv_gold, 2) ?></span><span class="metal-unit">g rcv</span></span></p>
                    </div>
                    <div class="stats-icon-wrap bg-amber-50 shrink-0"><i class="fas fa-coins text-amber-400 text-xs"></i></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($show_synth_mix_silver): ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-dashed border-slate-300 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase inline-flex items-center gap-1"><i class="fas fa-coins text-slate-500 text-[10px]" aria-hidden="true"></i> Mix Silver</p>
                        <p class="stats-card-value leading-tight"><?= number_format($mix_fine_silver, 2) ?><span class="text-[10px] font-medium text-slate-500 ml-0.5">g fine</span></p>
                        <p class="stats-metal-split"><span class="metal-seg" title="Received this period"><i class="fas fa-arrow-down metal-icon-silver"></i><span class="metal-num"><?= number_format($mix_rcv_silver, 2) ?></span><span class="metal-unit">g rcv</span></span></p>
                    </div>
                    <div class="stats-icon-wrap bg-slate-50 shrink-0"><i class="fas fa-coins text-slate-400 text-xs"></i></div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ============ HEADER / FILTER BAR ============ -->
    <div class="bg-white rounded-xl shadow-sm px-3 py-2.5 mb-4 border border-slate-200/50">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-tighter">Daily Report</h2>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest"><?= date('d M Y', strtotime($start_date)) ?> &ndash; <?= date('d M Y', strtotime($end_date)) ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button onclick="openAddStockModalFree()" class="px-3 py-1.5 bg-amber-600 text-white text-[10px] font-bold uppercase rounded shadow-sm hover:bg-amber-700 tracking-tighter">
                    <i class="fas fa-plus mr-1"></i>Stock
                </button>
                <form class="flex flex-wrap items-center gap-2" method="GET">
                    <div class="flex items-center bg-slate-50 border border-slate-200 rounded px-2 py-1">
                        <label class="text-[9px] font-bold text-slate-400 uppercase mr-2 tracking-tighter">From</label>
                        <input type="date" name="start_date" value="<?= $start_date ?>" class="bg-transparent border-none p-0 text-[10px] font-bold text-slate-700 focus:ring-0 w-24">
                    </div>
                    <div class="flex items-center bg-slate-50 border border-slate-200 rounded px-2 py-1">
                        <label class="text-[9px] font-bold text-slate-400 uppercase mr-2 tracking-tighter">To</label>
                        <input type="date" name="end_date" value="<?= $end_date ?>" class="bg-transparent border-none p-0 text-[10px] font-bold text-slate-700 focus:ring-0 w-24">
                    </div>
                    <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-[10px] font-bold uppercase rounded shadow-sm hover:bg-blue-700 tracking-tighter">
                        <i class="fas fa-filter mr-1"></i>Filter
                    </button>
                    <a href="export_report_pdf.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" target="_blank" class="px-3 py-1.5 bg-white border border-slate-200 text-slate-600 text-[10px] font-bold uppercase rounded hover:bg-slate-50 tracking-tighter shadow-sm">
                        <i class="fas fa-file-pdf mr-1 text-red-500"></i>PDF
                    </a>
                </form>
            </div>
        </div>
    </div>

    <!-- ============ TABS ============ -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="flex items-center overflow-x-auto border-b border-slate-100 px-1">
            <button class="report-tab-btn active" data-tab="overview" onclick="showReportTab('overview', this)">
                <i class="fas fa-layer-group"></i> Overview <span class="report-tab-count"><?= count($reports) ?></span>
            </button>
            <button class="report-tab-btn" data-tab="bookings" onclick="showReportTab('bookings', this)">
                <i class="fas fa-book"></i> Bookings <span class="report-tab-count"><?= count($tab_bookings) ?></span>
            </button>
            <button class="report-tab-btn" data-tab="exchanges" onclick="showReportTab('exchanges', this)">
                <i class="fas fa-right-left"></i> Exchanges <span class="report-tab-count"><?= count($tab_exchanges) ?></span>
            </button>
            <button class="report-tab-btn" data-tab="sales" onclick="showReportTab('sales', this)">
                <i class="fas fa-cart-shopping"></i> Sales <span class="report-tab-count"><?= count($tab_sales) ?></span>
            </button>
            <button class="report-tab-btn" data-tab="purchases" onclick="showReportTab('purchases', this)">
                <i class="fas fa-basket-shopping"></i> Purchases <span class="report-tab-count"><?= count($tab_purchases) ?></span>
            </button>
            <button class="report-tab-btn" data-tab="received" onclick="showReportTab('received', this)">
                <i class="fas fa-arrow-down"></i> Received <span class="report-tab-count"><?= count($tab_received) ?></span>
            </button>
            <button class="report-tab-btn" data-tab="payments" onclick="showReportTab('payments', this)">
                <i class="fas fa-arrow-up"></i> Payments <span class="report-tab-count"><?= count($tab_payments) ?></span>
            </button>
        </div>

        <!-- ---- Overview panel (party-wise summary) ---- -->
        <div id="tab-overview" class="report-tab-panel">
            <div class="overflow-x-auto">
                <table class="rtable">
                    <thead>
                        <tr>
                            <th>Party</th>
                            <th class="num">Booking (g)</th>
                            <th class="num">Sale (g)</th>
                            <th class="num">Purchase (g)</th>
                            <th class="num">Issue (g)</th>
                            <th class="num">Gold Rcv (g)</th>
                            <th class="num">Exch. Rcv (g)</th>
                            <th class="num">Cash In</th>
                            <th class="num">Bank In</th>
                            <th class="num">Cash Out</th>
                            <th class="num">Bank Out</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reports)): ?>
                        <tr><td colspan="11" class="text-center py-10 text-slate-400"><i class="fas fa-inbox text-2xl mb-2 block"></i>No transactions found for the selected period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reports as $row): ?>
                            <tr>
                                <td>
                                    <div class="font-bold text-slate-800"><?= htmlspecialchars($row['party_name']) ?></div>
                                    <?php if ($row['contact_no']): ?><div class="text-[9px] text-slate-400"><?= htmlspecialchars($row['contact_no']) ?></div><?php endif; ?>
                                </td>
                                <td class="num text-slate-600"><?= $row['booking_weight'] > 0 ? number_format($row['booking_weight'], 2) : '&mdash;' ?></td>
                                <td class="num text-slate-600"><?= $row['sale_weight'] > 0 ? number_format($row['sale_weight'], 2) : '&mdash;' ?></td>
                                <td class="num text-slate-600"><?= $row['purchase_weight'] > 0 ? number_format($row['purchase_weight'], 2) : '&mdash;' ?></td>
                                <td class="num text-slate-600"><?= $row['issue_weight'] > 0 ? number_format($row['issue_weight'], 2) : '&mdash;' ?></td>
                                <td class="num text-slate-600"><?= $row['gold_received_weight'] > 0 ? number_format($row['gold_received_weight'], 2) : '&mdash;' ?></td>
                                <td class="num text-slate-600"><?= $row['exchange_received_weight'] > 0 ? number_format($row['exchange_received_weight'], 2) : '&mdash;' ?></td>
                                <td class="num <?= $row['cash_in'] > 0 ? 'text-emerald-600 font-bold' : 'text-slate-300' ?>"><?= $row['cash_in'] > 0 ? '&#8377;' . number_format($row['cash_in']) : '&mdash;' ?></td>
                                <td class="num <?= $row['bank_in'] > 0 ? 'text-blue-600 font-bold' : 'text-slate-300' ?>"><?= $row['bank_in'] > 0 ? '&#8377;' . number_format($row['bank_in']) : '&mdash;' ?></td>
                                <td class="num <?= $row['cash_out'] > 0 ? 'text-rose-600 font-bold' : 'text-slate-300' ?>"><?= $row['cash_out'] > 0 ? '&#8377;' . number_format($row['cash_out']) : '&mdash;' ?></td>
                                <td class="num <?= $row['bank_out'] > 0 ? 'text-orange-600 font-bold' : 'text-slate-300' ?>"><?= $row['bank_out'] > 0 ? '&#8377;' . number_format($row['bank_out']) : '&mdash;' ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-slate-50 font-black">
                                <td>TOTAL</td>
                                <td class="num"><?= number_format($total_booking, 2) ?></td>
                                <td class="num"><?= number_format($total_sale, 2) ?></td>
                                <td class="num"><?= number_format($total_purchase, 2) ?></td>
                                <td class="num"><?= number_format($total_issue, 2) ?></td>
                                <td class="num"><?= number_format($total_gold_rcv, 2) ?></td>
                                <td class="num"><?= number_format($total_exchange_rcv, 2) ?></td>
                                <td class="num text-emerald-700">&#8377;<?= number_format($total_cash_in) ?></td>
                                <td class="num text-blue-700">&#8377;<?= number_format($total_bank_in) ?></td>
                                <td class="num text-rose-700">&#8377;<?= number_format($total_cash_out) ?></td>
                                <td class="num text-orange-700">&#8377;<?= number_format($total_bank_out) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---- Bookings panel ---- -->
        <div id="tab-bookings" class="report-tab-panel hidden">
            <div class="overflow-x-auto">
                <table class="rtable">
                    <thead><tr><th>Id / Date</th><th>Party</th><th class="num">Weight</th><th class="num">Purity</th><th class="num">Rate</th><th class="num">Amount</th><th>Mode</th></tr></thead>
                    <tbody>
                        <?php if (empty($tab_bookings)): ?>
                        <tr><td colspan="7" class="text-center py-10 text-slate-400"><i class="fas fa-book text-2xl mb-2 block"></i>No bookings in this period.</td></tr>
                        <?php else: foreach ($tab_bookings as $r): ?>
                        <tr>
                            <td><div class="font-bold text-blue-600">#<?= htmlspecialchars($r['receipt_id']) ?></div><div class="text-[9px] text-slate-400 uppercase"><?= date('d M', strtotime($r['date_of_transaction'])) ?></div></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="num"><?= number_format((float) $r['gold_weight'], 3) ?> g</td>
                            <td class="num text-slate-500"><?= number_format((float) $r['purity'], 2) ?>%</td>
                            <td class="num text-slate-500">&#8377;<?= number_format((float) $r['rate'], 0) ?></td>
                            <td class="num font-bold text-slate-800">&#8377;<?= number_format((float) $r['gold_amount'], 0) ?></td>
                            <td><span class="badge <?= strcasecmp((string) $r['booking_type'], 'Bank') === 0 ? 'bg-indigo-50 text-indigo-700' : 'bg-emerald-50 text-emerald-700' ?>"><?= htmlspecialchars($r['booking_type'] ?: 'Cash') ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---- Exchanges panel ---- -->
        <div id="tab-exchanges" class="report-tab-panel hidden">
            <div class="overflow-x-auto">
                <table class="rtable">
                    <thead><tr><th>Id / Date</th><th>Party</th><th class="num">Rcv Wt</th><th class="num">Purity</th><th class="num">Fine</th><th class="num">Issue Wt</th><th class="num">Diff</th><th class="num">Amount</th><th>Metal</th></tr></thead>
                    <tbody>
                        <?php if (empty($tab_exchanges)): ?>
                        <tr><td colspan="9" class="text-center py-10 text-slate-400"><i class="fas fa-right-left text-2xl mb-2 block"></i>No exchanges in this period.</td></tr>
                        <?php else: foreach ($tab_exchanges as $r):
                            $diff = (float) $r['difference_weight'];
                            $diffColor = $diff > 0 ? 'text-emerald-600' : ($diff < 0 ? 'text-rose-600' : 'text-slate-500');
                            $isSilver = strcasecmp((string) $r['exchange_material'], 'Silver') === 0;
                        ?>
                        <tr data-exchange-id="<?= (int) $r['id'] ?>">
                            <td><div class="font-bold text-blue-600">#<?= htmlspecialchars($r['receipt_id']) ?></div><div class="text-[9px] text-slate-400 uppercase"><?= date('d M', strtotime($r['date_of_transaction'])) ?></div></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="num ex-rcv-cell"><?= number_format((float) $r['received_weight'], 3) ?> g</td>
                            <td class="num text-slate-500 ex-purity-cell"><?= number_format((float) $r['purity'], 2) ?>%</td>
                            <td class="num text-amber-600 font-semibold ex-fine-cell"><?= number_format((float) $r['fine_weight'], 3) ?> g</td>
                            <td class="num ex-issue-cell"><?= number_format((float) $r['delivered_weight'], 3) ?> g</td>
                            <td class="num ex-diff-cell <?= $diffColor ?> font-semibold"><?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 3) ?></td>
                            <td class="num font-bold text-slate-800 ex-amt-cell">&#8377;<?= number_format((float) $r['amount'], 0) ?></td>
                            <td><span class="badge <?= $isSilver ? 'bg-slate-100 text-slate-700' : 'bg-amber-50 text-amber-700' ?>"><?= $isSilver ? 'Silver' : 'Gold' ?></span></td>
                        </tr>
                        <?php endforeach;
                            $ex_diff_color = $ex_total_diff > 0 ? 'text-emerald-600' : ($ex_total_diff < 0 ? 'text-rose-600' : 'text-slate-500');
                            $ex_fine_for_transfer = $ex_total_transferable > 0.0005 ? $ex_total_transferable : $ex_total_fine;
                        ?>
                        <tr class="bg-slate-50 font-black" id="exchanges-total-row">
                            <td colspan="2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span>TOTAL</span>
                                    <?php if ($ex_total_transferable > 0.0005 || ($ex_total_fine > 0.0005 && $ex_total_transferred < $ex_total_fine - 0.0005)): ?>
                                    <button type="button" id="transferAllFineBtn" class="ex-transfer-btn"
                                        data-start="<?= htmlspecialchars($start_date) ?>"
                                        data-end="<?= htmlspecialchars($end_date) ?>"
                                        title="Transfer total fine to fine stock">
                                        <i class="fas fa-coins text-[8px]"></i> Transfer fine
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="num"><?= number_format($ex_total_rcv, 3) ?> g</td>
                            <td class="num text-slate-500"><?= number_format($ex_avg_purity, 2) ?>%</td>
                            <td class="num text-amber-700">
                                <div class="inline-flex items-center gap-1 justify-end">
                                    <input type="number" step="0.001" min="0" id="ex-total-fine-input"
                                        class="ex-fine-input font-black"
                                        value="<?= number_format($ex_fine_for_transfer, 3, '.', '') ?>"
                                        title="Edit total fine weight after refine, then transfer">
                                    <span class="text-[9px] font-bold text-slate-500">g</span>
                                </div>
                                <?php if ($ex_total_transferred > 0.0005): ?>
                                <div class="text-[8px] font-bold text-emerald-600 normal-case mt-0.5"><?= number_format($ex_total_transferred, 3) ?> g already transferred</div>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= number_format($ex_total_issue, 3) ?> g</td>
                            <td class="num <?= $ex_diff_color ?>"><?= $ex_total_diff > 0 ? '+' : '' ?><?= number_format($ex_total_diff, 3) ?></td>
                            <td class="num">&#8377;<?= number_format($ex_total_amt, 0) ?></td>
                            <td></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---- Sales panel ---- -->
        <div id="tab-sales" class="report-tab-panel hidden">
            <div class="overflow-x-auto">
                <table class="rtable">
                    <thead><tr><th>Id / Date</th><th>Party</th><th>Stock</th><th class="num">Weight</th><th class="num">Rate</th><th class="num">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($tab_sales)): ?>
                        <tr><td colspan="7" class="text-center py-10 text-slate-400"><i class="fas fa-cart-shopping text-2xl mb-2 block"></i>No sales in this period.</td></tr>
                        <?php else: foreach ($tab_sales as $r):
                            $paid = (float) $r['payment_amount']; $amt = (float) $r['gold_amount'];
                            $status = ($paid >= $amt && $amt > 0) ? ['Paid', 'bg-slate-100 text-slate-800'] : (($paid > 0) ? ['Partial', 'bg-yellow-100 text-yellow-700'] : ['Due', 'bg-rose-100 text-rose-700']);
                        ?>
                        <tr>
                            <td><div class="font-bold text-blue-600">#<?= htmlspecialchars($r['receipt_id']) ?></div><div class="text-[9px] text-slate-400 uppercase"><?= date('d M', strtotime($r['date_of_transaction'])) ?></div></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="text-slate-600 max-w-[10rem] truncate" title="<?= htmlspecialchars((string) $r['stock_names']) ?>"><?= htmlspecialchars($r['stock_names'] ?: '—') ?></td>
                            <td class="num"><?= number_format((float) $r['gold_weight'], 3) ?> g<div class="text-[9px] text-slate-400"><?= number_format((float) $r['purity'], 1) ?>%</div></td>
                            <td class="num text-slate-500">&#8377;<?= number_format((float) $r['rate'], 0) ?></td>
                            <td class="num font-bold text-slate-800">&#8377;<?= number_format($amt, 0) ?></td>
                            <td><span class="badge <?= $status[1] ?>"><?= $status[0] ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---- Purchases panel ---- -->
        <div id="tab-purchases" class="report-tab-panel hidden">
            <div class="overflow-x-auto">
                <table class="rtable">
                    <thead><tr><th>Id / Date</th><th>Party</th><th class="num">Weight</th><th class="num">Purity</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">Paid</th></tr></thead>
                    <tbody>
                        <?php if (empty($tab_purchases)): ?>
                        <tr><td colspan="7" class="text-center py-10 text-slate-400"><i class="fas fa-basket-shopping text-2xl mb-2 block"></i>No purchases in this period.</td></tr>
                        <?php else: foreach ($tab_purchases as $r): ?>
                        <tr>
                            <td><div class="font-bold text-blue-600">#<?= htmlspecialchars($r['receipt_id']) ?></div><div class="text-[9px] text-slate-400 uppercase"><?= date('d M', strtotime($r['date_of_transaction'])) ?></div></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="num"><?= number_format((float) $r['gold_weight'], 3) ?> g</td>
                            <td class="num text-slate-500"><?= number_format((float) $r['purity'], 2) ?>%</td>
                            <td class="num text-slate-500">&#8377;<?= number_format((float) $r['rate'], 0) ?></td>
                            <td class="num font-bold text-slate-800">&#8377;<?= number_format((float) $r['gold_amount'], 0) ?></td>
                            <td class="num text-slate-500">&#8377;<?= number_format((float) $r['payment_amount'], 0) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---- Received panel ---- -->
        <div id="tab-received" class="report-tab-panel hidden">
            <div class="overflow-x-auto">
                <table class="rtable">
                    <thead><tr><th>Id / Date</th><th>Party</th><th class="num">Amount</th><th>Method</th><th>Narration</th></tr></thead>
                    <tbody>
                        <?php if (empty($tab_received)): ?>
                        <tr><td colspan="5" class="text-center py-10 text-slate-400"><i class="fas fa-arrow-down text-2xl mb-2 block"></i>No receipts in this period.</td></tr>
                        <?php else: foreach ($tab_received as $r): ?>
                        <tr>
                            <td><div class="font-bold text-blue-600">#<?= htmlspecialchars($r['receipt_id']) ?></div><div class="text-[9px] text-slate-400 uppercase"><?= date('d M', strtotime($r['date_of_transaction'])) ?></div></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="num font-bold text-emerald-600">&#8377;<?= number_format((float) $r['payment_amount'], 0) ?></td>
                            <td><span class="badge bg-emerald-50 text-emerald-700"><?= htmlspecialchars($r['payment_method'] ?: 'Cash') ?></span></td>
                            <td class="text-slate-500 max-w-[14rem] truncate" title="<?= htmlspecialchars((string) $r['narration']) ?>"><?= htmlspecialchars($r['narration'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---- Payments panel ---- -->
        <div id="tab-payments" class="report-tab-panel hidden">
            <div class="overflow-x-auto">
                <table class="rtable">
                    <thead><tr><th>Id / Date</th><th>Party</th><th class="num">Amount</th><th>Method</th><th>Narration</th></tr></thead>
                    <tbody>
                        <?php if (empty($tab_payments)): ?>
                        <tr><td colspan="5" class="text-center py-10 text-slate-400"><i class="fas fa-arrow-up text-2xl mb-2 block"></i>No payments in this period.</td></tr>
                        <?php else: foreach ($tab_payments as $r): ?>
                        <tr>
                            <td><div class="font-bold text-blue-600">#<?= htmlspecialchars($r['receipt_id']) ?></div><div class="text-[9px] text-slate-400 uppercase"><?= date('d M', strtotime($r['date_of_transaction'])) ?></div></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="num font-bold text-rose-600">&#8377;<?= number_format((float) $r['payment_amount'], 0) ?></td>
                            <td><span class="badge bg-rose-50 text-rose-700"><?= htmlspecialchars($r['payment_method'] ?: 'Cash') ?></span></td>
                            <td class="text-slate-500 max-w-[14rem] truncate" title="<?= htmlspecialchars((string) $r['narration']) ?>"><?= htmlspecialchars($r['narration'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ============ Transaction History modal (kept as a real modal — a data table doesn't fit well in SweetAlert) ============ -->
<div id="transactionsModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-[1px] overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto w-11/12 md:w-3/4 lg:w-2/3 bg-white shadow-2xl rounded-xl overflow-hidden">
        <div class="flex justify-between items-center px-4 py-3 border-b border-slate-100 bg-slate-50">
            <h3 class="text-xs font-black text-slate-800 uppercase tracking-tight flex items-center gap-2" id="transactionsModalTitle">
                <i class="fas fa-clock-rotate-left text-blue-500"></i> Transaction History
            </h3>
            <button onclick="closeModal('transactionsModal')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
        </div>

        <div id="transactionsLoading" class="text-center py-10">
            <i class="fas fa-spinner fa-spin text-2xl text-slate-300"></i>
            <p class="text-slate-400 text-xs font-bold uppercase mt-2">Loading&hellip;</p>
        </div>

        <div id="transactionsContent" class="hidden">
            <div class="overflow-x-auto max-h-96">
                <table class="rtable">
                    <thead>
                        <tr>
                            <th>Date</th><th>Type</th><th class="num">Amount</th><th>Notes</th><th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody"></tbody>
                </table>
            </div>
            <div id="noTransactions" class="hidden text-center py-10 text-slate-400">
                <i class="fas fa-inbox text-2xl mb-2 block"></i>No transactions found
            </div>
        </div>

        <div class="px-4 py-2.5 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="closeModal('transactionsModal')" class="px-3 py-1.5 bg-white border border-slate-200 text-slate-600 text-[10px] font-bold uppercase rounded hover:bg-slate-100">Close</button>
        </div>
    </div>
</div>

<script>
/* ============================================================
   Tabs
   ============================================================ */
function showReportTab(tab, btn) {
    document.querySelectorAll('.report-tab-panel').forEach(p => p.classList.add('hidden'));
    document.getElementById('tab-' + tab).classList.remove('hidden');
    document.querySelectorAll('.report-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

/* ============================================================
   Card "..." menus
   ============================================================ */
function closeAllCardMenus() {
    document.querySelectorAll('.card-menu').forEach(m => m.classList.add('hidden'));
}
function toggleCardMenu(e, menuId) {
    e.stopPropagation();
    const menu = document.getElementById(menuId);
    const isOpen = !menu.classList.contains('hidden');
    closeAllCardMenus();
    if (!isOpen) menu.classList.remove('hidden');
}
document.addEventListener('click', () => closeAllCardMenus());

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

/* ============================================================
   Generic POST helper to handlers/manage_stock_cash.php
   (field names match exactly what that file already expects —
   no backend changes needed)
   ============================================================ */
function postStockCashAction(action, dataObj) {
    const fd = new FormData();
    fd.append('action', action);
    Object.keys(dataObj).forEach(k => fd.append(k, dataObj[k]));

    Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('handlers/manage_stock_cash.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Done', text: data.message, timer: 1400, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Something went wrong.' });
            }
        })
        .catch(err => {
            Swal.fire({ icon: 'error', title: 'Network Error', text: err.message });
        });
}

/* ============================================================
   Add / Reset Stock (SweetAlert2 forms)
   ============================================================ */
function openAddStockModalFree() {
    showAddStockSwal({});
}
function openAddStockModal(stockId, stockName, purity, category, mode) {
    showAddStockSwal({ stockId, stockName, purity, category, mode });
}
function showAddStockSwal(prefill) {
    const isExisting = !!prefill.stockId;
    Swal.fire({
        title: isExisting ? `Add Weight — ${prefill.stockName} (${prefill.purity}%)` : 'Add Gold / Silver Stock',
        html: `
            <div class="text-left space-y-2.5">
                ${isExisting ? '' : `
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Category</label>
                    <select id="swalCategory" class="swal2-select" style="margin:0;width:100%;">
                        <option value="Gold">Gold</option>
                        <option value="Silver">Silver</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Stock Name</label>
                    <input id="swalStockName" class="swal2-input" style="margin:0;width:100%;" placeholder="e.g. Fine Gold, 22K">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Purity (%)</label>
                        <input id="swalPurity" type="number" step="0.01" class="swal2-input" style="margin:0;width:100%;" placeholder="0.00">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Mode</label>
                        <select id="swalMode" class="swal2-select" style="margin:0;width:100%;">
                            <option value="Cash">Cash (Kachha)</option>
                            <option value="Bank">Bank (Pakka)</option>
                        </select>
                    </div>
                </div>
                `}
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Weight to Add (g)</label>
                    <input id="swalWeight" type="number" step="0.001" class="swal2-input" style="margin:0;width:100%;" placeholder="0.000">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Notes</label>
                    <textarea id="swalNotes" class="swal2-textarea" style="margin:0;width:100%;" placeholder="Optional"></textarea>
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-plus mr-1"></i> Add Stock',
        confirmButtonColor: '#d97706',
        cancelButtonColor: '#64748b',
        preConfirm: () => {
            const weight = parseFloat(document.getElementById('swalWeight').value) || 0;
            if (weight <= 0) { Swal.showValidationMessage('Enter a valid weight'); return false; }
            const data = { amount: weight, notes: document.getElementById('swalNotes').value || '' };
            if (isExisting) {
                data.stock_id = prefill.stockId;
                data.category = prefill.category;
                data.purity = prefill.purity;
                data.stock_type = prefill.mode;
                data.stock_name = prefill.stockName;
            } else {
                data.category = document.getElementById('swalCategory').value;
                data.stock_name = document.getElementById('swalStockName').value.trim();
                data.purity = parseFloat(document.getElementById('swalPurity').value) || 0;
                data.stock_type = document.getElementById('swalMode').value;
                if (!data.stock_name) { Swal.showValidationMessage('Enter a stock name'); return false; }
                if (data.purity <= 0) { Swal.showValidationMessage('Enter a valid purity'); return false; }
            }
            return data;
        }
    }).then(result => {
        if (result.isConfirmed) postStockCashAction('add_stock', result.value);
    });
}

function openResetStockModal(stockId, stockName, purity) {
    Swal.fire({
        title: `Reset ${stockName} (${purity}%)?`,
        html: `
            <div class="text-left space-y-2">
                <div class="bg-amber-50 border border-amber-200 rounded-md p-2.5 text-xs text-amber-800">
                    <i class="fas fa-triangle-exclamation mr-1"></i> This sets the stock to <b>0 g</b>. The action is logged.
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Reason for reset</label>
                    <textarea id="swalResetNotes" class="swal2-textarea" style="margin:0;width:100%;" placeholder="Optional"></textarea>
                </div>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-rotate-left mr-1"></i> Reset to 0',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        preConfirm: () => ({ stock_id: stockId, purity: purity, notes: document.getElementById('swalResetNotes').value || '' })
    }).then(result => {
        if (result.isConfirmed) postStockCashAction('reset_stock', result.value);
    });
}

/* ============================================================
   Add / Reset Cash & Bank
   ============================================================ */
function showAmountNotesSwal({ title, confirmText, confirmColor, icon }) {
    return Swal.fire({
        title,
        icon: icon || undefined,
        html: `
            <div class="text-left space-y-2.5">
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Amount (&#8377;)</label>
                    <input id="swalAmount" type="number" step="0.01" class="swal2-input" style="margin:0;width:100%;" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Notes</label>
                    <textarea id="swalNotes2" class="swal2-textarea" style="margin:0;width:100%;" placeholder="Optional"></textarea>
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: confirmText,
        confirmButtonColor: confirmColor,
        cancelButtonColor: '#64748b',
        preConfirm: () => {
            const amount = parseFloat(document.getElementById('swalAmount').value) || 0;
            if (amount <= 0) { Swal.showValidationMessage('Enter a valid amount'); return false; }
            return { amount, notes: document.getElementById('swalNotes2').value || '' };
        }
    });
}

function openAddCashModal() {
    showAmountNotesSwal({ title: 'Add Cash', confirmText: '<i class="fas fa-plus mr-1"></i> Add Cash', confirmColor: '#059669' })
        .then(result => { if (result.isConfirmed) postStockCashAction('add_cash', result.value); });
}
function openAddBankModal() {
    showAmountNotesSwal({ title: 'Add Bank Amount', confirmText: '<i class="fas fa-plus mr-1"></i> Add Bank', confirmColor: '#0284c7' })
        .then(result => { if (result.isConfirmed) postStockCashAction('add_bank', result.value); });
}

function showResetBalanceSwal({ title, actionLabel }) {
    return Swal.fire({
        title,
        icon: 'warning',
        html: `
            <div class="text-left space-y-2">
                <div class="bg-amber-50 border border-amber-200 rounded-md p-2.5 text-xs text-amber-800">
                    <i class="fas fa-triangle-exclamation mr-1"></i> This sets the balance to <b>&#8377;0</b>. The action is logged.
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Reason for reset</label>
                    <textarea id="swalResetNotes2" class="swal2-textarea" style="margin:0;width:100%;" placeholder="Optional"></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: `<i class="fas fa-rotate-left mr-1"></i> ${actionLabel}`,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        preConfirm: () => ({ notes: document.getElementById('swalResetNotes2').value || '' })
    });
}
function openResetCashModal() {
    showResetBalanceSwal({ title: 'Reset Cash Balance?', actionLabel: 'Reset Cash' })
        .then(result => { if (result.isConfirmed) postStockCashAction('reset_cash', result.value); });
}
function openResetBankModal() {
    showResetBalanceSwal({ title: 'Reset Bank Balance?', actionLabel: 'Reset Bank' })
        .then(result => { if (result.isConfirmed) postStockCashAction('reset_bank', result.value); });
}

/* ============================================================
   Transaction History modal
   ============================================================ */
function openTransactionsModal(type, id = null) {
    const modal = document.getElementById('transactionsModal');
    const title = document.getElementById('transactionsModalTitle');
    const loading = document.getElementById('transactionsLoading');
    const content = document.getElementById('transactionsContent');

    title.innerHTML = type === 'cash'
        ? '<i class="fas fa-clock-rotate-left text-blue-500"></i> Cash Transaction History'
        : type === 'bank'
            ? '<i class="fas fa-clock-rotate-left text-blue-500"></i> Bank Transaction History'
            : '<i class="fas fa-clock-rotate-left text-blue-500"></i> Gold Stock Transaction History';

    modal.classList.remove('hidden');
    loading.classList.remove('hidden');
    content.classList.add('hidden');
    closeAllCardMenus();

    const fd = new FormData();
    fd.append('action', 'get_transactions');
    fd.append('type', type);
    if (id) fd.append('stock_id', id);

    fetch('handlers/manage_stock_cash.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            loading.classList.add('hidden');
            content.classList.remove('hidden');
            if (data.success && data.transactions.length > 0) {
                displayTransactions(data.transactions, type);
                document.getElementById('noTransactions').classList.add('hidden');
            } else {
                document.getElementById('transactionsTableBody').innerHTML = '';
                document.getElementById('noTransactions').classList.remove('hidden');
            }
        })
        .catch(error => {
            loading.classList.add('hidden');
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load transactions: ' + error.message });
            closeModal('transactionsModal');
        });
}

function displayTransactions(transactions, type) {
    const tbody = document.getElementById('transactionsTableBody');
    tbody.innerHTML = '';

    transactions.forEach(transaction => {
        const row = document.createElement('tr');

        const date = new Date(transaction.date_of_transaction);
        const dateStr = date.toLocaleDateString('en-IN') + ' ' + date.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });

        let amount, typeDisplay, typeColorClass;
        if (type === 'stock') {
            amount = (transaction.transaction_type === 'Exchange' && transaction.fine_weight > 0)
                ? parseFloat(transaction.fine_weight).toFixed(3) + ' g'
                : parseFloat(transaction.gold_weight).toFixed(3) + ' g';
            typeDisplay = transaction.transaction_type.replace('_', ' ');

            if (['Stock_Addition', 'Purchase', 'Exchange_Received', 'Received'].includes(transaction.transaction_type)) {
                typeColorClass = 'text-emerald-600';
            } else if (['Sale', 'Stock_Deduction', 'Issue', 'Exchange'].includes(transaction.transaction_type)) {
                typeColorClass = 'text-rose-600';
            } else if (transaction.transaction_type === 'Stock_Reset') {
                typeColorClass = 'text-blue-600';
            } else {
                typeColorClass = 'text-slate-500';
            }
        } else {
            amount = '₹' + parseFloat(transaction.payment_amount).toLocaleString('en-IN');
            if (transaction.payment_type === 'Payment_In') {
                typeDisplay = 'Payment In'; typeColorClass = 'text-emerald-600';
            } else if (transaction.payment_type === 'Payment_Out') {
                typeDisplay = 'Payment Out'; typeColorClass = 'text-rose-600';
            } else {
                typeDisplay = transaction.transaction_type; typeColorClass = 'text-slate-500';
            }
        }

        let notes = transaction.narration || '-';
        notes = notes.replace(/^(Cash Addition:|Bank Addition:|Stock Addition \(.*?\):)\s*/, '');
        if (notes.trim() === '') notes = '-';

        row.innerHTML = `
            <td class="text-slate-500">${dateStr}</td>
            <td>
                <span class="font-bold ${typeColorClass}">${typeDisplay}</span>
                ${transaction.payment_method ? '<div class="text-[9px] text-slate-400">' + transaction.payment_method + '</div>' : ''}
            </td>
            <td class="num font-bold text-slate-800">${amount}</td>
            <td class="text-slate-500 max-w-[14rem] truncate" title="${notes}">${notes}</td>
            <td class="text-center">
                <button onclick="deleteHistoryTransaction(${transaction.id})" class="text-rose-500 hover:text-rose-700" title="Delete"><i class="fas fa-trash-alt text-[11px]"></i></button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function deleteHistoryTransaction(transactionId) {
    Swal.fire({
        title: 'Delete this transaction?',
        text: 'This action will be logged for audit purposes.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b'
    }).then(result => {
        if (!result.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'delete_transaction');
        fd.append('transaction_id', transactionId);
        fetch('handlers/manage_stock_cash.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            })
            .catch(error => Swal.fire({ icon: 'error', title: 'Error', text: error.message }));
    });
}

/* ============================================================
   Exchange list — total fine edit & single transfer to fine stock
   ============================================================ */
function postExchangeAction(action, dataObj) {
    const fd = new FormData();
    fd.append('action', action);
    Object.keys(dataObj).forEach(k => fd.append(k, dataObj[k]));
    return fetch('handlers/manage_stock_cash.php', { method: 'POST', body: fd }).then(r => r.json());
}

const transferAllBtn = document.getElementById('transferAllFineBtn');
const totalFineInput = document.getElementById('ex-total-fine-input');
if (transferAllBtn && totalFineInput) {
    transferAllBtn.addEventListener('click', () => {
        const fine = parseFloat(totalFineInput.value) || 0;
        if (fine <= 0) {
            Swal.fire({ icon: 'warning', title: 'Enter fine weight', text: 'Set the total fine weight to transfer.' });
            return;
        }
        Swal.fire({
            title: 'Transfer ' + fine.toFixed(3) + ' g to fine stock?',
            html: '<p class="text-xs text-slate-600">Moves total fine from MIX stock to fine gold stock for this period.<br>Edit the total fine above if refine result differs.</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Transfer',
            confirmButtonColor: '#d97706',
            cancelButtonColor: '#64748b'
        }).then(result => {
            if (!result.isConfirmed) return;
            Swal.fire({ title: 'Transferring…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            postExchangeAction('transfer_period_fine', {
                start_date: transferAllBtn.dataset.start,
                end_date: transferAllBtn.dataset.end,
                fine_weight: fine
            }).then(data => {
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Transferred', text: data.message, timer: 1600, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Transfer failed' });
                }
            }).catch(err => Swal.fire({ icon: 'error', title: 'Network Error', text: err.message }));
        });
    });
}
</script>

<?php
$content = ob_get_clean();
include 'components/layout.php';
?>
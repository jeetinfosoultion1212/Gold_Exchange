<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Load database configuration
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/report_ledger_helper.php';
require_once __DIR__ . '/helpers/report_dashboard_helper.php';

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

// ============ Unified Recent Transactions feed (replaces the old Bookings / Exchanges / Sales / Purchases / Received / Payments tabs) ============
$recent_transactions = report_dashboard_recent_transactions($conn, $company_id, $start_date, $end_date);
$recent_counts = ['Booking' => 0, 'Exchange' => 0, 'Sale' => 0, 'Purchase' => 0, 'Received' => 0, 'Payment' => 0];
$tab_exchanges = [];
foreach ($recent_transactions as $rt) {
    if (isset($recent_counts[$rt['type']])) {
        $recent_counts[$rt['type']]++;
    }
    if ($rt['type'] === 'Exchange') {
        $tab_exchanges[] = $rt;
    }
}

$ex_total_fine = 0.0;
$ex_total_transferable = 0.0;
$ex_total_transferred = 0.0;
foreach ($tab_exchanges as $ex_row) {
    $ex_fn = (float) $ex_row['fine_weight'];
    $ex_xf = (float) ($ex_row['fine_transferred'] ?? 0);
    $ex_total_fine += $ex_fn;
    $ex_total_transferred += $ex_xf;
    $ex_total_transferable += max(0, $ex_fn - $ex_xf);
}

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

// ============ Party List (name, address, GST, cash/bank/gold balance) ============
$party_list_sql = "SELECT id, party_name, contact_no, address, city, state, gstin,
                           cash_balance, bank_balance, gold_balance, silver_balance
                    FROM parties WHERE company_id = $company_id ORDER BY party_name ASC";
$party_list_result = $conn->query($party_list_sql);
$party_list = [];
if ($party_list_result) {
    while ($row = $party_list_result->fetch_assoc()) {
        $party_list[] = $row;
    }
}
$party_list_totals = ['cash' => 0.0, 'bank' => 0.0, 'gold' => 0.0, 'silver' => 0.0];
foreach ($party_list as $p) {
    $party_list_totals['cash'] += (float) $p['cash_balance'];
    $party_list_totals['bank'] += (float) $p['bank_balance'];
    $party_list_totals['gold'] += (float) $p['gold_balance'];
    $party_list_totals['silver'] += (float) $p['silver_balance'];
}

// ============ Cash / Bank Ledger — full history, independent of the report's date filter ============
$ledger_all_start = '2000-01-01';
$ledger_all_end = '2099-12-31';
$ledger_rows_all = report_ledger_fetch_cash_bank_rows($conn, $company_id);
$cash_ledger = report_ledger_running($ledger_rows_all['Cash'], (float) ($balance_data['cash_balance'] ?? 0), $ledger_all_start, $ledger_all_end);
$bank_ledger = report_ledger_running($ledger_rows_all['Bank'], (float) ($balance_data['bank_balance'] ?? 0), $ledger_all_start, $ledger_all_end);

// ============ Stock Ledger — full history per stock, independent of the report's date filter ============
$stock_ledgers = [];
foreach ($gold_stocks as $sk) {
    $sk_rows = report_stock_ledger_fetch_rows($conn, $company_id, $sk);
    $stock_ledgers[(int) $sk['id']] = report_stock_ledger_running($sk_rows, (float) $sk['current_stock'], $ledger_all_start, $ledger_all_end);
}

// ============ Stock bar chart data ============
$stock_chart_labels = [];
$stock_chart_values = [];
$stock_chart_colors = [];
foreach ($gold_stocks as $sk) {
    $is_gold_sk = $sk['category'] === 'Gold';
    $stock_chart_labels[] = $sk['stock_name'] . ' (' . $sk['mode'] . ')';
    $stock_chart_values[] = round((float) $sk['current_stock'], 3);
    $stock_chart_colors[] = $is_gold_sk ? '#d97706' : '#64748b';
}

// ============ Dashboard widgets: receivable/payable KPI + trend charts ============
$receivable_payable = report_dashboard_receivable_payable($conn, $company_id);
$total_receivable = $receivable_payable['receivable'];
$total_payable = $receivable_payable['payable'];

$daily_trend = report_dashboard_daily_trend($conn, $company_id, $start_date, $end_date);
$trend_labels = array_map(fn($d) => date('d M', strtotime($d['d'])), $daily_trend);
$trend_sale_values = array_map(fn($d) => round((float) $d['sale_amt'], 2), $daily_trend);
$trend_purchase_values = array_map(fn($d) => round((float) $d['purchase_amt'], 2), $daily_trend);

$payment_trend = report_dashboard_payment_trend($conn, $company_id, $start_date, $end_date);
$payment_trend_labels = array_map(fn($d) => date('d M', strtotime($d['d'])), $payment_trend);
$payment_in_values = array_map(fn($d) => round((float) $d['payment_in'], 2), $payment_trend);
$payment_out_values = array_map(fn($d) => round((float) $d['payment_out'], 2), $payment_trend);

$page_title = "Dashboard";
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

    /* Cash / Bank sub-toggle inside the Cash Ledger tab */
    .ledger-subtab-btn { padding: 0.35rem 0.75rem; font-size: 10.5px; font-weight: 800; letter-spacing: 0.02em; text-transform: uppercase; color: rgb(100 116 139); background: rgb(248 250 252); border: 1px solid rgb(226 232 240); border-radius: 0.4rem; display: inline-flex; align-items: center; gap: 0.35rem; }
    .ledger-subtab-btn:hover { color: rgb(30 41 59); }
    .ledger-subtab-btn.active { color: #fff; background: rgb(37 99 235); border-color: rgb(37 99 235); }

    /* Scrollable tab panels — keeps the tab bar / filters visible instead of growing the whole page */
    .rtable-scroll { max-height: 32rem; overflow-y: auto; }
    .rtable-scroll thead th { position: sticky; top: 0; z-index: 5; }
    .alltime-note { font-size: 9.5px; font-weight: 700; color: rgb(100 116 139); text-transform: uppercase; letter-spacing: 0.03em; display: inline-flex; align-items: center; gap: 0.3rem; }

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

    /* Compact stock modals (SweetAlert2) */
    .swal2-popup.rpt-stock-modal { width: 25.5rem !important; max-width: calc(100vw - 2rem) !important; padding: 1.35rem 1.5rem 1.5rem !important; border-radius: 0.9rem !important; }
    .swal2-popup.rpt-stock-modal .swal2-title { font-size: 1.05rem !important; font-weight: 800 !important; padding: 0 0 0.15rem !important; color: rgb(30 41 59); }
    .swal2-popup.rpt-stock-modal .swal2-html-container { margin: 0.35rem 0 0 !important; padding: 0 !important; overflow: visible !important; font-size: 12.5px !important; }
    .swal2-popup.rpt-stock-modal .swal2-actions { margin: 1.1rem 0 0 !important; gap: 0.55rem !important; flex-wrap: wrap; width: 100%; }
    .swal2-popup.rpt-stock-modal .swal2-styled { font-size: 12px !important; font-weight: 700 !important; padding: 0.55rem 1.1rem !important; margin: 0 !important; border-radius: 0.5rem !important; }
    .swal2-popup.rpt-stock-modal .swal2-footer { margin: 0.75rem 0 0 !important; padding: 0.6rem 0 0 !important; border-top: 1px solid rgb(241 245 249) !important; }
    .rpt-stock-form { text-align: left; }
    .rpt-stock-badge { display: flex; align-items: center; gap: 0.6rem; background: rgb(255 251 235); border: 1px solid rgb(253 230 138); border-radius: 0.6rem; padding: 0.55rem 0.7rem; margin-bottom: 0.9rem; }
    .rpt-stock-badge .rpt-stock-badge-icon { width: 2.1rem; height: 2.1rem; border-radius: 0.5rem; background: rgb(253 230 138); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .rpt-stock-badge .rpt-stock-badge-icon i { color: rgb(146 64 14); font-size: 13px; }
    .rpt-stock-badge-name { font-size: 12.5px; font-weight: 800; color: rgb(120 53 15); line-height: 1.25; }
    .rpt-stock-badge-sub { font-size: 10.5px; font-weight: 600; color: rgb(180 130 40); text-transform: uppercase; letter-spacing: 0.02em; }
    .rpt-stock-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 0.75rem; align-items: end; }
    .rpt-field label { display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: rgb(100 116 139); margin-bottom: 4px; }
    .rpt-field input,
    .rpt-field select { width: 100% !important; margin: 0 !important; padding: 0.55rem 0.65rem !important; font-size: 13px !important; font-weight: 600; border: 1.5px solid rgb(226 232 240); border-radius: 0.5rem; box-sizing: border-box; color: rgb(30 41 59); }
    .rpt-field input::placeholder { font-weight: 400; color: rgb(148 163 184); }
    .rpt-field input:focus,
    .rpt-field select:focus { outline: none; border-color: rgb(217 119 6); box-shadow: 0 0 0 3px rgba(217,119,6,.15); }
    .rpt-col-3 { grid-column: span 3; }
    .rpt-col-4 { grid-column: span 4; }
    .rpt-col-5 { grid-column: span 5; }
    .rpt-col-6 { grid-column: span 6; }
    .rpt-col-8 { grid-column: span 8; }
    .rpt-col-12 { grid-column: span 12; }
    .rpt-stock-hint { font-size: 9px; color: rgb(100 116 139); line-height: 1.3; margin-bottom: 0.35rem; }
    .rpt-stock-clear-btn { background: none; border: none; color: rgb(100 116 139); font-size: 11px; font-weight: 700; cursor: pointer; padding: 0.2rem 0; display: inline-flex; align-items: center; gap: 0.3rem; }
    .rpt-stock-clear-btn:hover { color: rgb(217 119 6); }
    .stock-card-clickable { cursor: pointer; transition: box-shadow .15s ease, border-color .15s ease, transform .15s ease; }
    .stock-card-clickable:hover { box-shadow: 0 4px 14px -4px rgba(15,23,42,.18); border-color: rgb(203 213 225); transform: translateY(-1px); }
    .stock-card-clickable:active { transform: translateY(0); }

    /* Top stats: cash KPIs + stock chips in one line */
    .stats-top-row { display: flex; flex-wrap: nowrap; gap: 0.4rem; margin-bottom: 0.75rem; overflow-x: auto; padding-bottom: 2px; -webkit-overflow-scrolling: touch; }
    .kpi-tile, .stock-chip {
        background: #fff; border: 1px solid rgb(226 232 240); border-radius: 0.55rem;
        padding: 0.4rem 0.55rem; box-shadow: 0 1px 2px rgba(15,23,42,.04);
        position: relative; display: flex; align-items: center; gap: 0.4rem;
        flex: 1 1 0; min-width: 8.5rem; max-width: none;
    }
    .kpi-tile-clickable, .stock-card-clickable { cursor: pointer; }
    .kpi-tile-clickable:hover, .stock-card-clickable:hover { border-color: rgb(203 213 225); box-shadow: 0 3px 10px -4px rgba(15,23,42,.12); }
    .kpi-tile-body { min-width: 0; flex: 1; }
    .kpi-tile-label, .stock-chip-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; color: rgb(100 116 139); margin: 0 0 0.1rem; line-height: 1.2; white-space: nowrap; }
    .kpi-tile-value, .stock-chip-value { font-size: 0.8125rem; font-weight: 800; color: rgb(30 41 59); font-variant-numeric: tabular-nums; line-height: 1.15; letter-spacing: -0.02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kpi-tile-sub { display: none; }
    .kpi-tile-icon, .stock-chip-icon { width: 1.5rem; height: 1.5rem; border-radius: 0.35rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .kpi-tile-icon i, .stock-chip-icon i { font-size: 0.6rem; }
    .kpi-tile .card-menu-btn, .stock-chip .card-menu-btn { position: absolute; top: 0.15rem; right: 0.15rem; width: 1.1rem; height: 1.1rem; }
    .stock-chip-dashed { border-style: dashed; }
    .stock-chip-meta { font-size: 9px; font-weight: 600; color: rgb(100 116 139); white-space: nowrap; margin: 0; }

    /* Customer list — same height as Stock Overview; list scrolls inside */
    .chart-row { align-items: stretch; }
    .chart-row > .dash-card { display: flex; flex-direction: column; min-height: 0; height: 100%; }
    .customer-list-card { overflow: hidden; }
    .customer-list-scroll { flex: 1 1 0; min-height: 0; overflow-y: auto; overflow-x: hidden; }
    .customer-list-table thead th { padding: 0.4rem 0.45rem; font-size: 8.5px; position: sticky; top: 0; z-index: 2; background: rgb(248 250 252); }
    .customer-list-table tbody td { padding: 0.35rem 0.45rem; font-size: 10px; vertical-align: middle; }
    .customer-list-sn { width: 1.5rem; text-align: center; color: rgb(148 163 184); font-weight: 800; font-size: 9px; }
    .customer-list-avatar { width: 1.45rem; height: 1.45rem; border-radius: 9999px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-size: 8px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }

    .party-txn-item { display: flex; align-items: center; gap: 0.65rem; padding: 0.65rem 0.85rem; border-bottom: 1px solid rgb(241 245 249); }
    .party-txn-item:hover { background: rgb(248 250 252); }
    .party-txn-avatar { width: 2.1rem; height: 2.1rem; border-radius: 9999px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .dash-card { background: #fff; border: 1px solid rgb(226 232 240); border-radius: 0.85rem; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
    .dash-card-title { font-size: 12px; font-weight: 800; color: rgb(30 41 59); letter-spacing: -0.01em; }
</style>

<div class="w-full">

    <!-- ============ FILTER BAR ============ -->
    <div class="dash-card px-4 py-3 mb-4">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-extrabold text-slate-800 tracking-tight">Dashboard</h2>
                <p class="text-[11px] text-slate-400 font-semibold"><?= date('d M Y', strtotime($start_date)) ?> &ndash; <?= date('d M Y', strtotime($end_date)) ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button onclick="openAddStockModalFree()" class="px-3 py-1.5 bg-amber-600 text-white text-[10px] font-bold uppercase rounded-lg shadow-sm hover:bg-amber-700">
                    <i class="fas fa-plus mr-1"></i>Stock
                </button>
                <form class="flex flex-wrap items-center gap-2" method="GET">
                    <div class="flex items-center bg-slate-50 border border-slate-200 rounded-lg px-2 py-1">
                        <label class="text-[9px] font-bold text-slate-400 uppercase mr-2">From</label>
                        <input type="date" name="start_date" value="<?= $start_date ?>" class="bg-transparent border-none p-0 text-[10px] font-bold text-slate-700 focus:ring-0 w-24">
                    </div>
                    <div class="flex items-center bg-slate-50 border border-slate-200 rounded-lg px-2 py-1">
                        <label class="text-[9px] font-bold text-slate-400 uppercase mr-2">To</label>
                        <input type="date" name="end_date" value="<?= $end_date ?>" class="bg-transparent border-none p-0 text-[10px] font-bold text-slate-700 focus:ring-0 w-24">
                    </div>
                    <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-[10px] font-bold uppercase rounded-lg shadow-sm hover:bg-blue-700">
                        <i class="fas fa-filter mr-1"></i>Filter
                    </button>
                    <a href="export_report_pdf.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" target="_blank" class="px-3 py-1.5 bg-white border border-slate-200 text-slate-600 text-[10px] font-bold uppercase rounded-lg hover:bg-slate-50 shadow-sm">
                        <i class="fas fa-file-pdf mr-1 text-red-500"></i>PDF
                    </a>
                </form>
            </div>
        </div>
    </div>

    <!-- ============ Top stats: cash + stock in one line ============ -->
    <div class="stats-top-row">
        <div class="kpi-tile kpi-tile-clickable relative" onclick="openAddCashModal();" title="Click to add cash">
            <div class="kpi-tile-icon bg-emerald-100 text-emerald-600"><i class="fas fa-wallet"></i></div>
            <div class="kpi-tile-body">
                <p class="kpi-tile-label">Cash In Hand</p>
                <p class="kpi-tile-value">&#8377;<?= number_format((float) ($balance_data['cash_balance'] ?? 0)) ?></p>
            </div>
            <button type="button" class="card-menu-btn" onclick="toggleCardMenu(event, 'menu-cash')" aria-label="Cash actions"><i class="fas fa-ellipsis-v text-[9px]"></i></button>
            <div id="menu-cash" class="card-menu hidden" onclick="event.stopPropagation();" style="top:1.4rem;right:0.2rem;">
                <button onclick="closeAllCardMenus(); openAddCashModal();"><i class="fas fa-plus"></i> Add Cash</button>
                <button onclick="closeAllCardMenus(); openTransactionsModal('cash');"><i class="fas fa-clock-rotate-left"></i> History</button>
                <button class="danger" onclick="closeAllCardMenus(); openResetCashModal();"><i class="fas fa-rotate-left"></i> Reset</button>
            </div>
        </div>
        <div class="kpi-tile kpi-tile-clickable relative" onclick="openAddBankModal();" title="Click to add bank amount">
            <div class="kpi-tile-icon bg-sky-100 text-sky-600"><i class="fas fa-university"></i></div>
            <div class="kpi-tile-body">
                <p class="kpi-tile-label">Bank Balance</p>
                <p class="kpi-tile-value">&#8377;<?= number_format((float) ($balance_data['bank_balance'] ?? 0)) ?></p>
            </div>
            <button type="button" class="card-menu-btn" onclick="toggleCardMenu(event, 'menu-bank')" aria-label="Bank actions"><i class="fas fa-ellipsis-v text-[9px]"></i></button>
            <div id="menu-bank" class="card-menu hidden" onclick="event.stopPropagation();" style="top:1.4rem;right:0.2rem;">
                <button onclick="closeAllCardMenus(); openAddBankModal();"><i class="fas fa-plus"></i> Add Bank</button>
                <button onclick="closeAllCardMenus(); openTransactionsModal('bank');"><i class="fas fa-clock-rotate-left"></i> History</button>
                <button class="danger" onclick="closeAllCardMenus(); openResetBankModal();"><i class="fas fa-rotate-left"></i> Reset</button>
            </div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-tile-icon bg-rose-100 text-rose-600"><i class="fas fa-arrow-down"></i></div>
            <div class="kpi-tile-body">
                <p class="kpi-tile-label">Total Receivable</p>
                <p class="kpi-tile-value text-rose-600">&#8377;<?= number_format($total_receivable) ?></p>
            </div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-tile-icon bg-orange-100 text-orange-600"><i class="fas fa-arrow-up"></i></div>
            <div class="kpi-tile-body">
                <p class="kpi-tile-label">Total Payable</p>
                <p class="kpi-tile-value text-orange-600">&#8377;<?= number_format($total_payable) ?></p>
            </div>
        </div>
        <?php foreach ($gold_stocks as $stock):
            $is_gold = $stock['category'] === 'Gold';
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
            $chip_label = $is_mix_stock
                ? ($is_gold ? 'Mix Gold' : 'Mix Silver')
                : htmlspecialchars($stock['stock_name']) . ((float) $stock['purity'] > 0 ? ' ' . number_format((float) $stock['purity'], 2) . '%' : '');
        ?>
        <div class="stock-chip stock-card-clickable relative" onclick="openAddStockModal(<?= (int) $stock['id'] ?>, '<?= $stock_name_js ?>', <?= (float) $stock['purity'] ?>, '<?= $stock['category'] ?>', '<?= $stock['mode'] ?>');" title="Click to add / update weight">
            <div class="stock-chip-icon <?= $is_gold ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600' ?>"><i class="fas fa-coins"></i></div>
            <div class="kpi-tile-body">
                <p class="stock-chip-label"><?= $chip_label ?></p>
                <?php if ($is_mix_stock):
                    $mix_line_rcv = $is_gold ? $mix_rcv_gold : $mix_rcv_silver;
                    $mix_line_fn = $is_gold ? $mix_fine_gold : $mix_fine_silver;
                ?>
                <p class="stock-chip-value"><?= number_format($mix_line_fn, 2) ?> <span class="font-semibold text-slate-500">g fine</span></p>
                <p class="stock-chip-meta"><i class="fas fa-arrow-down text-[8px] text-amber-600 mr-0.5"></i><?= number_format($mix_line_rcv, 2) ?> g rcv</p>
                <?php else: ?>
                <p class="stock-chip-value"><?= number_format((float) $stock['current_stock'], 2) ?> <span class="font-semibold text-slate-500">g</span></p>
                <?php endif; ?>
            </div>
            <button type="button" class="card-menu-btn" onclick="toggleCardMenu(event, '<?= $menu_id ?>')" aria-label="Stock actions"><i class="fas fa-ellipsis-v text-[9px]"></i></button>
            <div id="<?= $menu_id ?>" class="card-menu hidden" onclick="event.stopPropagation();" style="top:1.4rem;right:0.2rem;">
                <button onclick="closeAllCardMenus(); openAddStockModal(<?= (int) $stock['id'] ?>, '<?= $stock_name_js ?>', <?= (float) $stock['purity'] ?>, '<?= $stock['category'] ?>', '<?= $stock['mode'] ?>');"><i class="fas fa-plus"></i> Add Weight</button>
                <button onclick="closeAllCardMenus(); openTransactionsModal('stock', <?= (int) $stock['id'] ?>);"><i class="fas fa-clock-rotate-left"></i> History</button>
                <button class="danger" onclick="closeAllCardMenus(); openResetStockModal(<?= (int) $stock['id'] ?>, '<?= $stock_name_js ?>', <?= (float) $stock['purity'] ?>);"><i class="fas fa-rotate-left"></i> Reset</button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($show_synth_mix_gold): ?>
        <div class="stock-chip stock-chip-dashed stock-card-clickable" onclick="openAddStockModalPrefilled('Gold', 'Mix Gold');">
            <div class="stock-chip-icon bg-amber-100 text-amber-700"><i class="fas fa-coins"></i></div>
            <div class="kpi-tile-body">
                <p class="stock-chip-label">Mix Gold</p>
                <p class="stock-chip-value"><?= number_format($mix_fine_gold, 2) ?> <span class="font-semibold text-slate-500">g fine</span></p>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($show_synth_mix_silver): ?>
        <div class="stock-chip stock-chip-dashed stock-card-clickable" onclick="openAddStockModalPrefilled('Silver', 'Mix Silver');">
            <div class="stock-chip-icon bg-slate-100 text-slate-600"><i class="fas fa-coins"></i></div>
            <div class="kpi-tile-body">
                <p class="stock-chip-label">Mix Silver</p>
                <p class="stock-chip-value"><?= number_format($mix_fine_silver, 2) ?> <span class="font-semibold text-slate-500">g fine</span></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============ Charts first: Stock Overview | Customer List ============ -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 mb-3 chart-row">
        <div class="lg:col-span-8 dash-card p-3">
            <h3 class="dash-card-title mb-1.5 flex items-center gap-2 shrink-0"><i class="fas fa-chart-column text-amber-500"></i> Stock Overview</h3>
            <?php if (empty($gold_stocks)): ?>
            <div class="text-center py-8 text-slate-400 flex-1"><i class="fas fa-chart-column text-2xl mb-2 block"></i>No stock recorded yet.</div>
            <?php else: ?>
            <div class="flex-1 min-h-0"><canvas id="stockBarChart" height="95"></canvas></div>
            <?php endif; ?>
        </div>
        <div class="lg:col-span-4 dash-card customer-list-card">
            <div class="flex items-center justify-between px-3 pt-3 pb-2 border-b border-slate-100 shrink-0">
                <h3 class="dash-card-title flex items-center gap-2"><i class="fas fa-users text-violet-500"></i> Customer List</h3>
                <span class="text-[9px] font-bold text-slate-400"><?= count($party_list) ?> parties</span>
            </div>
            <div class="customer-list-scroll">
                <table class="rtable customer-list-table">
                    <thead>
                        <tr>
                            <th class="customer-list-sn">#</th>
                            <th class="w-8"></th>
                            <th>Name</th>
                            <th class="num">Cash</th>
                            <th class="num">Bank</th>
                            <th class="num">Gold</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($party_list)): ?>
                        <tr><td colspan="6" class="text-center py-8 text-slate-400 text-[10px]">No customers yet.</td></tr>
                        <?php else: $party_sn = 0; foreach ($party_list as $p):
                            $party_sn++;
                            $cb = (float) $p['cash_balance'];
                            $bb = (float) $p['bank_balance'];
                            $gb = (float) $p['gold_balance'];
                            $initials = strtoupper(substr(preg_replace('/\s+/', '', $p['party_name']), 0, 2));
                        ?>
                        <tr>
                            <td class="customer-list-sn"><?= $party_sn ?></td>
                            <td><span class="customer-list-avatar"><?= htmlspecialchars($initials ?: 'P') ?></span></td>
                            <td class="font-semibold text-slate-800 max-w-[6.5rem] truncate" title="<?= htmlspecialchars($p['party_name']) ?>"><?= htmlspecialchars($p['party_name']) ?></td>
                            <td class="num font-semibold <?= $cb > 0 ? 'text-rose-600' : ($cb < 0 ? 'text-emerald-600' : 'text-slate-400') ?>"><?= abs($cb) > 0.0005 ? '&#8377;' . number_format(abs($cb)) : '&mdash;' ?></td>
                            <td class="num font-semibold <?= $bb > 0 ? 'text-rose-600' : ($bb < 0 ? 'text-emerald-600' : 'text-slate-400') ?>"><?= abs($bb) > 0.0005 ? '&#8377;' . number_format(abs($bb)) : '&mdash;' ?></td>
                            <td class="num font-semibold <?= abs($gb) > 0.0005 ? 'text-amber-700' : 'text-slate-400' ?>"><?= abs($gb) > 0.0005 ? number_format($gb, 2) . ' g' : '&mdash;' ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ Sales vs Purchase | Payment In vs Out ============ -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 mb-4">
        <div class="lg:col-span-8 dash-card p-3">
            <div class="flex items-center justify-between mb-1.5">
                <h3 class="dash-card-title">Sales vs Purchase</h3>
                <span class="text-[9px] font-bold text-slate-400 uppercase">Selected range</span>
            </div>
            <canvas id="salesPurchaseTrendChart" height="95"></canvas>
        </div>
        <div class="lg:col-span-4 dash-card p-3">
            <h3 class="dash-card-title mb-1.5 flex items-center gap-2"><i class="fas fa-money-bill-trend-up text-sky-500"></i> Payment In vs Out</h3>
            <canvas id="paymentTrendChart" height="120"></canvas>
        </div>
    </div>

    <!-- ============ RECENT ORDERS + PARTY TRANSACTIONS (reference middle row) ============ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
        <!-- Recent Orders (left 2/3) -->
        <div class="lg:col-span-2 dash-card overflow-hidden">
            <div class="flex flex-wrap items-center gap-1 px-3 pt-3 pb-1 border-b border-slate-100">
                <h3 class="dash-card-title mr-3 flex items-center gap-2"><i class="fas fa-receipt text-blue-500"></i> Recent Orders</h3>
                <button type="button" class="report-tab-btn active" data-rtxn-filter="all" onclick="filterRecentTransactions('all', this)">All <span class="report-tab-count"><?= count($recent_transactions) ?></span></button>
                <button type="button" class="report-tab-btn" data-rtxn-filter="Booking" onclick="filterRecentTransactions('Booking', this)">Booking <span class="report-tab-count"><?= $recent_counts['Booking'] ?></span></button>
                <button type="button" class="report-tab-btn" data-rtxn-filter="Sale" onclick="filterRecentTransactions('Sale', this)">Sale <span class="report-tab-count"><?= $recent_counts['Sale'] ?></span></button>
                <button type="button" class="report-tab-btn" data-rtxn-filter="Purchase" onclick="filterRecentTransactions('Purchase', this)">Purchase <span class="report-tab-count"><?= $recent_counts['Purchase'] ?></span></button>
                <button type="button" class="report-tab-btn" data-rtxn-filter="Exchange" onclick="filterRecentTransactions('Exchange', this)">Exchange <span class="report-tab-count"><?= $recent_counts['Exchange'] ?></span></button>
                <button type="button" class="report-tab-btn" data-rtxn-filter="Received" onclick="filterRecentTransactions('Received', this)">Received <span class="report-tab-count"><?= $recent_counts['Received'] ?></span></button>
                <button type="button" class="report-tab-btn" data-rtxn-filter="Payment" onclick="filterRecentTransactions('Payment', this)">Payment <span class="report-tab-count"><?= $recent_counts['Payment'] ?></span></button>
                <?php
                    $ex_fine_for_transfer = $ex_total_transferable > 0.0005 ? $ex_total_transferable : $ex_total_fine;
                    $show_bulk_transfer = $ex_total_transferable > 0.0005 || ($ex_total_fine > 0.0005 && $ex_total_transferred < $ex_total_fine - 0.0005);
                ?>
                <?php if ($show_bulk_transfer): ?>
                <div id="bulkFineTransferBar" class="hidden ml-auto flex items-center gap-1.5">
                    <input type="number" step="0.001" min="0" id="ex-total-fine-input" class="ex-fine-input font-black" value="<?= number_format($ex_fine_for_transfer, 3, '.', '') ?>">
                    <span class="text-[9px] font-bold text-slate-500">g</span>
                    <button type="button" id="transferAllFineBtn" class="ex-transfer-btn" data-start="<?= htmlspecialchars($start_date) ?>" data-end="<?= htmlspecialchars($end_date) ?>">
                        <i class="fas fa-coins text-[8px]"></i> Transfer fine
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto rtable-scroll">
                <table class="rtable">
                    <thead><tr><th>Id / Date</th><th>Type</th><th>Party</th><th>Details</th><th class="num">Weight</th><th class="num">Amount</th><th>Fine / Transfer</th></tr></thead>
                    <tbody id="recentTransactionsBody">
                        <?php if (empty($recent_transactions)): ?>
                        <tr><td colspan="7" class="text-center py-10 text-slate-400"><i class="fas fa-inbox text-2xl mb-2 block"></i>No transactions found for the selected period.</td></tr>
                        <?php else: foreach ($recent_transactions as $r):
                            $rtype = $r['type'];
                            $typeBadge = [
                                'Booking' => 'bg-indigo-50 text-indigo-700',
                                'Sale' => 'bg-blue-50 text-blue-700',
                                'Purchase' => 'bg-purple-50 text-purple-700',
                                'Exchange' => 'bg-amber-50 text-amber-700',
                                'Received' => 'bg-emerald-50 text-emerald-700',
                                'Payment' => 'bg-rose-50 text-rose-700',
                            ][$rtype] ?? 'bg-slate-100 text-slate-700';
                        ?>
                        <tr data-txn-type="<?= $rtype ?>" <?= $rtype === 'Exchange' ? 'data-exchange-id="' . (int) $r['id'] . '"' : '' ?>>
                            <td><div class="font-bold text-blue-600">#<?= htmlspecialchars($r['receipt_id']) ?></div><div class="text-[9px] text-slate-400 uppercase"><?= date('d M', strtotime($r['date_of_transaction'])) ?></div></td>
                            <td><span class="badge <?= $typeBadge ?>"><?= htmlspecialchars($rtype) ?></span></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="text-slate-500 max-w-[13rem] truncate">
                                <?php if ($rtype === 'Booking'): ?>
                                    <?= number_format((float) $r['purity'], 2) ?>% &middot; &#8377;<?= number_format((float) $r['rate'], 0) ?>/g
                                <?php elseif ($rtype === 'Sale'):
                                    $paid = (float) $r['payment_amount']; $amt = (float) $r['gold_amount'];
                                    $status = ($paid >= $amt && $amt > 0) ? ['Paid', 'bg-emerald-100 text-emerald-700'] : (($paid > 0) ? ['Partial', 'bg-yellow-100 text-yellow-700'] : ['Due', 'bg-rose-100 text-rose-700']);
                                ?>
                                    <?= htmlspecialchars($r['stock_names'] ?: '—') ?> <span class="badge <?= $status[1] ?>"><?= $status[0] ?></span>
                                <?php elseif ($rtype === 'Purchase'): ?>
                                    <?= number_format((float) $r['purity'], 2) ?>% &middot; Paid &#8377;<?= number_format((float) $r['payment_amount'], 0) ?>
                                <?php elseif ($rtype === 'Exchange'):
                                    $diff = (float) $r['difference_weight'];
                                    $diffColor = $diff > 0 ? 'text-emerald-600' : ($diff < 0 ? 'text-rose-600' : 'text-slate-500');
                                ?>
                                    Rcv <?= number_format((float) $r['received_weight'], 3) ?>g &rarr; Issue <?= number_format((float) $r['delivered_weight'], 3) ?>g
                                    <span class="<?= $diffColor ?> font-semibold"><?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 3) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($r['payment_method'] ?: 'Cash') ?> · <?= htmlspecialchars($r['narration'] ?: '—') ?>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if (in_array($rtype, ['Booking', 'Sale', 'Purchase'], true)): ?>
                                    <?= number_format((float) $r['gold_weight'], 3) ?> g
                                <?php elseif ($rtype === 'Exchange'): ?>
                                    <?= number_format((float) $r['received_weight'], 3) ?> g
                                <?php else: ?>&mdash;<?php endif; ?>
                            </td>
                            <td class="num font-bold <?= $rtype === 'Received' ? 'text-emerald-600' : ($rtype === 'Payment' ? 'text-rose-600' : 'text-slate-800') ?>">
                                <?php if (in_array($rtype, ['Booking', 'Sale', 'Purchase'], true)): ?>
                                    &#8377;<?= number_format((float) $r['gold_amount'], 0) ?>
                                <?php elseif ($rtype === 'Exchange'): ?>
                                    &#8377;<?= number_format((float) $r['amount'], 0) ?>
                                <?php else: ?>
                                    &#8377;<?= number_format((float) $r['payment_amount'], 0) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rtype === 'Exchange'):
                                    $ex_fine = (float) $r['fine_weight'];
                                    $ex_xf = (float) ($r['fine_transferred'] ?? 0);
                                    $ex_pending = max(0, $ex_fine - $ex_xf);
                                ?>
                                <div class="flex items-center gap-1">
                                    <input type="number" step="0.001" min="0" class="ex-fine-input <?= $ex_pending <= 0.0005 ? 'is-transferred' : '' ?>"
                                        value="<?= number_format($ex_fine, 3, '.', '') ?>"
                                        onchange="saveExchangeFine(<?= (int) $r['id'] ?>, this)">
                                    <button type="button" class="ex-transfer-btn" <?= $ex_pending <= 0.0005 ? 'disabled' : '' ?>
                                        onclick="transferExchangeFine(<?= (int) $r['id'] ?>, this.previousElementSibling)">
                                        <i class="fas fa-coins text-[8px]"></i>
                                    </button>
                                </div>
                                <?php if ($ex_xf > 0.0005): ?><div class="ex-xferred-tag"><?= number_format($ex_xf, 3) ?> g xfer</div><?php endif; ?>
                                <?php else: ?>&mdash;<?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Party balances sidebar (Transactions style) -->
        <div class="dash-card overflow-hidden">
            <div class="flex items-center justify-between px-3 pt-3 pb-2 border-b border-slate-100">
                <h3 class="dash-card-title flex items-center gap-2"><i class="fas fa-users text-violet-500"></i> Party Balances</h3>
                <span class="text-[10px] font-bold text-slate-400"><?= count($party_list) ?> parties</span>
            </div>
            <div class="rtable-scroll" style="max-height: 28rem;">
                <?php if (empty($party_list)): ?>
                <div class="text-center py-10 text-slate-400 text-xs">No parties yet.</div>
                <?php else: foreach ($party_list as $p):
                    $cb = (float) $p['cash_balance'];
                    $bb = (float) $p['bank_balance'];
                    $net = $cb + $bb;
                    $gb = (float) $p['gold_balance'];
                    $initials = strtoupper(substr(preg_replace('/\s+/', '', $p['party_name']), 0, 2));
                    $amtClass = $net > 0.0005 ? 'text-rose-600' : ($net < -0.0005 ? 'text-emerald-600' : 'text-slate-400');
                ?>
                <div class="party-txn-item">
                    <div class="party-txn-avatar"><?= htmlspecialchars($initials ?: 'P') ?></div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[12px] font-bold text-slate-800 truncate"><?= htmlspecialchars($p['party_name']) ?></div>
                        <div class="text-[10px] text-slate-400 truncate">
                            <?php if ($p['gstin'] && $p['gstin'] !== 'N/A'): ?>GST <?= htmlspecialchars($p['gstin']) ?><?php elseif ($p['address']): ?><?= htmlspecialchars(strlen($p['address']) > 28 ? substr($p['address'], 0, 28) . '…' : $p['address']) ?><?php else: ?>—<?php endif; ?>
                            <?php if (abs($gb) > 0.0005): ?> · <?= number_format($gb, 2) ?>g<?php endif; ?>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-[12px] font-extrabold <?= $amtClass ?>"><?= abs($net) > 0.0005 ? '₹' . number_format(abs($net)) : '—' ?></div>
                        <div class="text-[9px] text-slate-400 font-semibold"><?= $net > 0.0005 ? 'Due' : ($net < -0.0005 ? 'Credit' : 'Clear') ?></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- ============ LEDGERS GRID: Cash Ledger + Stock Ledger ============ -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <!-- ---- Cash Ledger card ---- -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="flex items-center gap-2 px-3 pt-3 pb-2 border-b border-slate-100">
                <h3 class="text-xs font-black text-slate-800 uppercase tracking-tight flex items-center gap-2 mr-1"><i class="fas fa-book-open text-blue-500"></i> Cash Ledger</h3>
                <button type="button" class="ledger-subtab-btn active" id="cashledger-btn-Cash" onclick="showLedgerAccount('Cash')"><i class="fas fa-wallet"></i> Cash</button>
                <button type="button" class="ledger-subtab-btn" id="cashledger-btn-Bank" onclick="showLedgerAccount('Bank')"><i class="fas fa-university"></i> Bank</button>
                <span class="alltime-note ml-auto"><i class="fas fa-infinity"></i> Full history</span>
            </div>
            <?php foreach (['Cash' => $cash_ledger, 'Bank' => $bank_ledger] as $acct_name => $ledger): ?>
            <div id="cashledger-panel-<?= $acct_name ?>" class="overflow-x-auto rtable-scroll <?= $acct_name === 'Bank' ? 'hidden' : '' ?>">
                <table class="rtable">
                    <thead><tr><th>Date</th><th>Type</th><th>Party</th><th>Narration</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
                    <tbody>
                        <tr class="bg-slate-50 font-bold">
                            <td colspan="6">Opening Balance</td>
                            <td class="num">&#8377;<?= number_format($ledger['opening'], 2) ?></td>
                        </tr>
                        <?php if (empty($ledger['rows'])): ?>
                        <tr><td colspan="7" class="text-center py-10 text-slate-400"><i class="fas fa-book-open text-2xl mb-2 block"></i>No <?= strtolower($acct_name) ?> transactions recorded yet.</td></tr>
                        <?php else: foreach ($ledger['rows'] as $r):
                            $isCredit = $r['delta'] >= 0;
                        ?>
                        <tr>
                            <td class="text-slate-500"><?= date('d M Y', strtotime($r['date'])) ?></td>
                            <td><span class="font-bold <?= $isCredit ? 'text-emerald-600' : 'text-rose-600' ?>"><?= htmlspecialchars($r['label']) ?></span><div class="text-[9px] text-slate-400">#<?= htmlspecialchars($r['receipt_id']) ?></div></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="text-slate-500 max-w-[14rem] truncate" title="<?= htmlspecialchars((string) $r['narration']) ?>"><?= htmlspecialchars($r['narration'] ?: '—') ?></td>
                            <td class="num text-rose-600 font-semibold"><?= !$isCredit ? '&#8377;' . number_format(abs($r['delta']), 2) : '&mdash;' ?></td>
                            <td class="num text-emerald-600 font-semibold"><?= $isCredit ? '&#8377;' . number_format($r['delta'], 2) : '&mdash;' ?></td>
                            <td class="num font-bold text-slate-800">&#8377;<?= number_format($r['balance'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-slate-50 font-black">
                            <td colspan="4">TOTAL</td>
                            <td class="num text-rose-700">&#8377;<?= number_format($ledger['total_out'], 2) ?></td>
                            <td class="num text-emerald-700">&#8377;<?= number_format($ledger['total_in'], 2) ?></td>
                            <td class="num">&#8377;<?= number_format($ledger['closing'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ---- Stock Ledger card ---- -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200/50 overflow-hidden">
            <?php if (empty($gold_stocks)): ?>
            <h3 class="text-xs font-black text-slate-800 uppercase tracking-tight flex items-center gap-2 px-3 pt-3"><i class="fas fa-scale-balanced text-blue-500"></i> Stock Ledger</h3>
            <div class="text-center py-10 text-slate-400"><i class="fas fa-scale-balanced text-2xl mb-2 block"></i>No stock recorded yet.</div>
            <?php else: ?>
            <div class="flex flex-wrap items-center gap-2 px-3 pt-3 pb-2 border-b border-slate-100">
                <h3 class="text-xs font-black text-slate-800 uppercase tracking-tight flex items-center gap-2 mr-1"><i class="fas fa-scale-balanced text-blue-500"></i> Stock Ledger</h3>
                <select id="stockLedgerSelect" class="bg-slate-50 border border-slate-200 rounded px-2 py-1.5 text-[11px] font-bold text-slate-700" onchange="showStockLedger(this.value)">
                    <?php foreach ($gold_stocks as $sk): ?>
                    <option value="<?= (int) $sk['id'] ?>"><?= htmlspecialchars($sk['stock_name']) ?> &middot; <?= number_format((float) $sk['purity'], 2) ?>% &middot; <?= $sk['mode'] ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="alltime-note ml-auto"><i class="fas fa-infinity"></i> Full history</span>
            </div>
            <?php foreach ($gold_stocks as $idx => $sk):
                $sid = (int) $sk['id'];
                $ledger = $stock_ledgers[$sid];
            ?>
            <div id="stockledger-panel-<?= $sid ?>" class="overflow-x-auto rtable-scroll <?= $idx === 0 ? '' : 'hidden' ?>">
                <table class="rtable">
                    <thead><tr><th>Date</th><th>Type</th><th>Party</th><th>Narration</th><th class="num">In (g)</th><th class="num">Out (g)</th><th class="num">Balance (g)</th></tr></thead>
                    <tbody>
                        <tr class="bg-slate-50 font-bold">
                            <td colspan="6">Opening Balance</td>
                            <td class="num"><?= number_format($ledger['opening'], 3) ?> g</td>
                        </tr>
                        <?php if (empty($ledger['rows'])): ?>
                        <tr><td colspan="7" class="text-center py-10 text-slate-400"><i class="fas fa-scale-balanced text-2xl mb-2 block"></i>No stock movement recorded yet.</td></tr>
                        <?php else: foreach ($ledger['rows'] as $r):
                            $isReset = !empty($r['reset']);
                            $isIn = !$isReset && $r['delta'] >= 0;
                        ?>
                        <tr>
                            <td class="text-slate-500"><?= date('d M Y', strtotime($r['date'])) ?></td>
                            <td><span class="font-bold <?= $isReset ? 'text-blue-600' : ($isIn ? 'text-emerald-600' : 'text-rose-600') ?>"><?= htmlspecialchars($r['label']) ?></span><div class="text-[9px] text-slate-400">#<?= htmlspecialchars($r['receipt_id']) ?></div></td>
                            <td class="font-semibold text-slate-800"><?= htmlspecialchars($r['party_name'] ?? '—') ?></td>
                            <td class="text-slate-500 max-w-[14rem] truncate" title="<?= htmlspecialchars((string) $r['narration']) ?>"><?= htmlspecialchars($r['narration'] ?: '—') ?></td>
                            <td class="num text-emerald-600 font-semibold"><?= $isIn ? number_format($r['delta'], 3) . ' g' : '&mdash;' ?></td>
                            <td class="num text-rose-600 font-semibold"><?= (!$isIn && !$isReset) ? number_format(abs($r['delta']), 3) . ' g' : ($isReset ? '<span class="text-blue-600">reset&rarr;0</span>' : '&mdash;') ?></td>
                            <td class="num font-bold text-slate-800"><?= number_format($r['balance'], 3) ?> g</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-slate-50 font-black">
                            <td colspan="4">TOTAL</td>
                            <td class="num text-emerald-700"><?= number_format($ledger['total_in'], 3) ?> g</td>
                            <td class="num text-rose-700"><?= number_format($ledger['total_out'], 3) ?> g</td>
                            <td class="num"><?= number_format($ledger['closing'], 3) ?> g</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ PARTY LIST ============ -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200/50 overflow-hidden mb-4">
        <div class="flex items-center px-3 pt-3 pb-2 border-b border-slate-100">
            <h3 class="text-xs font-black text-slate-800 uppercase tracking-tight flex items-center gap-2"><i class="fas fa-address-book text-slate-500"></i> Party List</h3>
            <span class="alltime-note ml-auto"><i class="fas fa-infinity"></i> Current balances (not affected by date filter)</span>
        </div>
        <div class="overflow-x-auto rtable-scroll">
            <table class="rtable">
                <thead><tr><th>Party</th><th>Address</th><th>GSTIN</th><th class="num">Cash Balance</th><th class="num">Bank Balance</th><th class="num">Gold Balance</th><th class="num">Silver Balance</th></tr></thead>
                <tbody>
                    <?php if (empty($party_list)): ?>
                    <tr><td colspan="7" class="text-center py-10 text-slate-400"><i class="fas fa-address-book text-2xl mb-2 block"></i>No parties added yet.</td></tr>
                    <?php else: foreach ($party_list as $p):
                        $addr_parts = array_filter([$p['address'] ?: null, $p['city'] ?: null, $p['state'] ?: null]);
                        $addr_text = implode(', ', $addr_parts);
                        $cb = (float) $p['cash_balance'];
                        $bb = (float) $p['bank_balance'];
                        $gb = (float) $p['gold_balance'];
                        $sb = (float) $p['silver_balance'];
                    ?>
                    <tr>
                        <td>
                            <div class="font-bold text-slate-800"><?= htmlspecialchars($p['party_name']) ?></div>
                            <?php if ($p['contact_no']): ?><div class="text-[9px] text-slate-400"><?= htmlspecialchars($p['contact_no']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-slate-500 max-w-[14rem] truncate" title="<?= htmlspecialchars($addr_text) ?>"><?= $addr_text !== '' ? htmlspecialchars($addr_text) : '&mdash;' ?></td>
                        <td class="text-slate-500"><?= $p['gstin'] ? htmlspecialchars($p['gstin']) : '&mdash;' ?></td>
                        <td class="num font-semibold <?= $cb > 0 ? 'text-rose-600' : ($cb < 0 ? 'text-emerald-600' : 'text-slate-400') ?>"><?= abs($cb) > 0.0005 ? '&#8377;' . number_format(abs($cb)) : '&mdash;' ?></td>
                        <td class="num font-semibold <?= $bb > 0 ? 'text-rose-600' : ($bb < 0 ? 'text-emerald-600' : 'text-slate-400') ?>"><?= abs($bb) > 0.0005 ? '&#8377;' . number_format(abs($bb)) : '&mdash;' ?></td>
                        <td class="num font-semibold <?= abs($gb) > 0.0005 ? 'text-amber-700' : 'text-slate-400' ?>"><?= abs($gb) > 0.0005 ? number_format($gb, 3) . ' g' : '&mdash;' ?></td>
                        <td class="num font-semibold <?= abs($sb) > 0.0005 ? 'text-slate-600' : 'text-slate-400' ?>"><?= abs($sb) > 0.0005 ? number_format($sb, 3) . ' g' : '&mdash;' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="bg-slate-50 font-black">
                        <td colspan="3">TOTAL (<?= count($party_list) ?> parties)</td>
                        <td class="num"><?= abs($party_list_totals['cash']) > 0.0005 ? '&#8377;' . number_format(abs($party_list_totals['cash'])) : '&mdash;' ?></td>
                        <td class="num"><?= abs($party_list_totals['bank']) > 0.0005 ? '&#8377;' . number_format(abs($party_list_totals['bank'])) : '&mdash;' ?></td>
                        <td class="num"><?= abs($party_list_totals['gold']) > 0.0005 ? number_format($party_list_totals['gold'], 3) . ' g' : '&mdash;' ?></td>
                        <td class="num"><?= abs($party_list_totals['silver']) > 0.0005 ? number_format($party_list_totals['silver'], 3) . ' g' : '&mdash;' ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
/* ============================================================
   Recent Transactions — client-side type filter tabs
   ============================================================ */
function filterRecentTransactions(type, btn) {
    document.querySelectorAll('#recentTransactionsBody tr[data-txn-type]').forEach(row => {
        row.classList.toggle('hidden', type !== 'all' && row.dataset.txnType !== type);
    });
    document.querySelectorAll('[data-rtxn-filter]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const bulkBar = document.getElementById('bulkFineTransferBar');
    if (bulkBar) {
        bulkBar.classList.toggle('hidden', type !== 'Exchange');
    }
}

/* ============================================================
   Dashboard charts (Chart.js)
   ============================================================ */
function initStockBarChart() {
    const canvas = document.getElementById('stockBarChart');
    if (!canvas) return;
    const labels = <?= json_encode($stock_chart_labels) ?>;
    const values = <?= json_encode($stock_chart_values) ?>;
    const colors = <?= json_encode($stock_chart_colors) ?>;
    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Current Stock (g)',
                data: values,
                backgroundColor: colors,
                borderRadius: 4,
                maxBarThickness: 46
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ctx.parsed.y.toLocaleString('en-IN', { maximumFractionDigits: 3 }) + ' g'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: (v) => v.toLocaleString('en-IN') + ' g' }
                },
                x: {
                    ticks: { autoSkip: false, maxRotation: 30, minRotation: 0, font: { size: 10 } }
                }
            }
        }
    });
}

function initSalesPurchaseTrendChart() {
    const canvas = document.getElementById('salesPurchaseTrendChart');
    if (!canvas) return;
    const labels = <?= json_encode($trend_labels) ?>;
    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Sale (₹)',
                    data: <?= json_encode($trend_sale_values) ?>,
                    backgroundColor: '#3b82f6',
                    borderRadius: 4,
                    maxBarThickness: 16
                },
                {
                    label: 'Purchase (₹)',
                    data: <?= json_encode($trend_purchase_values) ?>,
                    backgroundColor: '#f97316',
                    borderRadius: 4,
                    maxBarThickness: 16
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: true, position: 'top', labels: { boxWidth: 10, font: { size: 10 } } },
                tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ₹' + ctx.parsed.y.toLocaleString('en-IN') } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: (v) => '₹' + v.toLocaleString('en-IN') } },
                x: { ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 } } }
            }
        }
    });
}

function initPaymentTrendChart() {
    const canvas = document.getElementById('paymentTrendChart');
    if (!canvas) return;
    const labels = <?= json_encode($payment_trend_labels) ?>;
    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Payment In (₹)',
                    data: <?= json_encode($payment_in_values) ?>,
                    backgroundColor: '#059669',
                    borderRadius: 3,
                    maxBarThickness: 18
                },
                {
                    label: 'Payment Out (₹)',
                    data: <?= json_encode($payment_out_values) ?>,
                    backgroundColor: '#e11d48',
                    borderRadius: 3,
                    maxBarThickness: 18
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: true, position: 'top', labels: { boxWidth: 10, font: { size: 10 } } },
                tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ₹' + ctx.parsed.y.toLocaleString('en-IN') } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: (v) => '₹' + v.toLocaleString('en-IN') } },
                x: { ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 } } }
            }
        }
    });
}

initStockBarChart();
initSalesPurchaseTrendChart();
initPaymentTrendChart();

/* ============================================================
   Cash Ledger — Cash / Bank sub-toggle
   ============================================================ */
function showLedgerAccount(acct) {
    ['Cash', 'Bank'].forEach(a => {
        document.getElementById('cashledger-panel-' + a).classList.toggle('hidden', a !== acct);
        document.getElementById('cashledger-btn-' + a).classList.toggle('active', a === acct);
    });
}

/* ============================================================
   Stock Ledger — stock picker
   ============================================================ */
function showStockLedger(stockId) {
    document.querySelectorAll('[id^="stockledger-panel-"]').forEach(p => {
        p.classList.toggle('hidden', p.id !== 'stockledger-panel-' + stockId);
    });
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
function openAddStockModalPrefilled(category, stockName) {
    showAddStockSwal({ newCategory: category, newStockName: stockName });
}
function showAddStockSwal(prefill) {
    const isExisting = !!prefill.stockId;
    const stockLabel = isExisting ? `${prefill.stockName} · ${prefill.purity}%` : '';

    function clearAddStockFields() {
        const w = document.getElementById('swalWeight');
        const n = document.getElementById('swalNotes');
        if (w) w.value = '';
        if (n) n.value = '';
        if (!isExisting) {
            const sn = document.getElementById('swalStockName');
            const pu = document.getElementById('swalPurity');
            const cat = document.getElementById('swalCategory');
            const mode = document.getElementById('swalMode');
            if (sn) sn.value = '';
            if (pu) pu.value = '';
            if (cat) cat.value = 'Gold';
            if (mode) mode.value = 'Cash';
        }
        if (w) w.focus();
    }

    Swal.fire({
        title: isExisting ? 'Add / Update Weight' : 'Add New Stock',
        html: `
            <div class="rpt-stock-form">
                ${isExisting ? `
                <div class="rpt-stock-badge">
                    <div class="rpt-stock-badge-icon"><i class="fas fa-coins"></i></div>
                    <div class="min-w-0">
                        <div class="rpt-stock-badge-name">${String(prefill.stockName).replace(/</g, '&lt;')}</div>
                        <div class="rpt-stock-badge-sub">${prefill.purity}% &middot; ${prefill.mode === 'Cash' ? 'Cash (Kachha)' : 'Bank (Pakka)'}</div>
                    </div>
                </div>` : ''}
                <div class="rpt-stock-grid">
                ${isExisting ? '' : `
                    <div class="rpt-field rpt-col-6">
                        <label>Category</label>
                        <select id="swalCategory">
                            <option value="Gold">Gold</option>
                            <option value="Silver">Silver</option>
                        </select>
                    </div>
                    <div class="rpt-field rpt-col-6">
                        <label>Mode</label>
                        <select id="swalMode">
                            <option value="Cash">Cash (Kachha)</option>
                            <option value="Bank">Bank (Pakka)</option>
                        </select>
                    </div>
                    <div class="rpt-field rpt-col-8">
                        <label>Stock name</label>
                        <input id="swalStockName" type="text" placeholder="Fine Gold, 22K">
                    </div>
                    <div class="rpt-field rpt-col-4">
                        <label>Purity %</label>
                        <input id="swalPurity" type="number" step="0.01" placeholder="0.00">
                    </div>
                `}
                    <div class="rpt-field rpt-col-6">
                        <label>Weight (g)</label>
                        <input id="swalWeight" type="number" step="0.001" placeholder="0.000">
                    </div>
                    <div class="rpt-field rpt-col-6">
                        <label>Notes</label>
                        <input id="swalNotes" type="text" placeholder="Optional">
                    </div>
                </div>
            </div>
        `,
        customClass: { popup: 'rpt-stock-modal' },
        focusConfirm: false,
        showCancelButton: true,
        showDenyButton: isExisting,
        denyButtonText: isExisting ? '<i class="fas fa-rotate-left mr-1"></i> Reset' : undefined,
        denyButtonColor: '#dc2626',
        confirmButtonText: isExisting ? '<i class="fas fa-plus mr-1"></i> Add Weight' : '<i class="fas fa-plus mr-1"></i> Add Stock',
        confirmButtonColor: '#d97706',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
        footer: isExisting ? '' : '<button type="button" class="rpt-stock-clear-btn" id="swalClearStockForm"><i class="fas fa-undo"></i>Clear form</button>',
        didOpen: () => {
            if (!isExisting) {
                document.getElementById('swalClearStockForm')?.addEventListener('click', clearAddStockFields);
                if (prefill.newCategory) document.getElementById('swalCategory').value = prefill.newCategory;
                if (prefill.newStockName) document.getElementById('swalStockName').value = prefill.newStockName;
            }
            document.getElementById('swalWeight')?.focus();
        },
        preConfirm: () => {
            const weight = parseFloat(document.getElementById('swalWeight').value) || 0;
            if (weight <= 0) { Swal.showValidationMessage('Enter a valid weight'); return false; }
            const data = { amount: weight, notes: (document.getElementById('swalNotes').value || '').trim() };
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
        if (result.isConfirmed) {
            postStockCashAction('add_stock', result.value);
        } else if (result.isDenied && isExisting) {
            openResetStockModal(prefill.stockId, prefill.stockName, prefill.purity);
        }
    });
}

function openResetStockModal(stockId, stockName, purity) {
    Swal.fire({
        title: 'Reset Stock?',
        html: `
            <div class="rpt-stock-form">
                <div class="rpt-stock-badge">
                    <div class="rpt-stock-badge-icon"><i class="fas fa-coins"></i></div>
                    <div class="min-w-0">
                        <div class="rpt-stock-badge-name">${String(stockName).replace(/</g, '&lt;')}</div>
                        <div class="rpt-stock-badge-sub">${purity}% purity</div>
                    </div>
                </div>
                <div class="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-[11px] text-red-700 mb-3 leading-snug">
                    <i class="fas fa-triangle-exclamation mr-1"></i> This sets the stock weight to <b>0 g</b>. The action is logged in history and cannot be undone.
                </div>
                <div class="rpt-field">
                    <label>Reason (optional)</label>
                    <input id="swalResetNotes" type="text" placeholder="e.g. Stock correction">
                </div>
            </div>
        `,
        customClass: { popup: 'rpt-stock-modal' },
        showCancelButton: true,
        reverseButtons: true,
        confirmButtonText: '<i class="fas fa-rotate-left mr-1"></i> Reset Stock',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        focusConfirm: false,
        preConfirm: () => ({ stock_id: stockId, purity: purity, notes: (document.getElementById('swalResetNotes').value || '').trim() })
    }).then(result => {
        if (result.isConfirmed) postStockCashAction('reset_stock', result.value);
    });
}

/* ============================================================
   Add / Reset Cash & Bank
   ============================================================ */
function showAmountNotesSwal({ title, confirmText, confirmColor, badgeIcon, badgeBg, badgeIconColor, badgeLabel }) {
    return Swal.fire({
        title,
        html: `
            <div class="rpt-stock-form">
                <div class="rpt-stock-badge" style="background:${badgeBg.bg};border-color:${badgeBg.border};">
                    <div class="rpt-stock-badge-icon" style="background:${badgeBg.iconBg};"><i class="fas ${badgeIcon}" style="color:${badgeIconColor};"></i></div>
                    <div class="min-w-0">
                        <div class="rpt-stock-badge-name" style="color:${badgeBg.text};">${badgeLabel}</div>
                        <div class="rpt-stock-badge-sub" style="color:${badgeBg.subtext};">Enter amount below</div>
                    </div>
                </div>
                <div class="rpt-stock-grid">
                    <div class="rpt-field rpt-col-12">
                        <label>Amount (&#8377;)</label>
                        <input id="swalAmount" type="number" step="0.01" placeholder="0.00">
                    </div>
                    <div class="rpt-field rpt-col-12">
                        <label>Notes</label>
                        <input id="swalNotes2" type="text" placeholder="Optional">
                    </div>
                </div>
            </div>
        `,
        customClass: { popup: 'rpt-stock-modal' },
        focusConfirm: false,
        showCancelButton: true,
        reverseButtons: true,
        confirmButtonText: confirmText,
        confirmButtonColor: confirmColor,
        cancelButtonColor: '#64748b',
        didOpen: () => { document.getElementById('swalAmount')?.focus(); },
        preConfirm: () => {
            const amount = parseFloat(document.getElementById('swalAmount').value) || 0;
            if (amount <= 0) { Swal.showValidationMessage('Enter a valid amount'); return false; }
            return { amount, notes: (document.getElementById('swalNotes2').value || '').trim() };
        }
    });
}

function openAddCashModal() {
    showAmountNotesSwal({
        title: 'Add Cash',
        confirmText: '<i class="fas fa-plus mr-1"></i> Add Cash',
        confirmColor: '#059669',
        badgeIcon: 'fa-wallet',
        badgeIconColor: '#047857',
        badgeLabel: 'Cash In Hand',
        badgeBg: { bg: '#ecfdf5', border: '#a7f3d0', iconBg: '#a7f3d0', text: '#065f46', subtext: '#0d9488' }
    }).then(result => { if (result.isConfirmed) postStockCashAction('add_cash', result.value); });
}
function openAddBankModal() {
    showAmountNotesSwal({
        title: 'Add Bank Amount',
        confirmText: '<i class="fas fa-plus mr-1"></i> Add Bank',
        confirmColor: '#0284c7',
        badgeIcon: 'fa-university',
        badgeIconColor: '#0369a1',
        badgeLabel: 'Bank Balance',
        badgeBg: { bg: '#f0f9ff', border: '#bae6fd', iconBg: '#bae6fd', text: '#075985', subtext: '#0284c7' }
    }).then(result => { if (result.isConfirmed) postStockCashAction('add_bank', result.value); });
}

function showResetBalanceSwal({ title, actionLabel, badgeIcon, badgeBg, badgeIconColor, badgeLabel }) {
    return Swal.fire({
        title,
        html: `
            <div class="rpt-stock-form">
                <div class="rpt-stock-badge" style="background:${badgeBg.bg};border-color:${badgeBg.border};">
                    <div class="rpt-stock-badge-icon" style="background:${badgeBg.iconBg};"><i class="fas ${badgeIcon}" style="color:${badgeIconColor};"></i></div>
                    <div class="min-w-0">
                        <div class="rpt-stock-badge-name" style="color:${badgeBg.text};">${badgeLabel}</div>
                    </div>
                </div>
                <div class="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-[11px] text-red-700 mb-3 leading-snug">
                    <i class="fas fa-triangle-exclamation mr-1"></i> This sets the balance to <b>&#8377;0</b>. The action is logged in history and cannot be undone.
                </div>
                <div class="rpt-field">
                    <label>Reason (optional)</label>
                    <input id="swalResetNotes2" type="text" placeholder="Optional">
                </div>
            </div>
        `,
        customClass: { popup: 'rpt-stock-modal' },
        showCancelButton: true,
        reverseButtons: true,
        confirmButtonText: `<i class="fas fa-rotate-left mr-1"></i> ${actionLabel}`,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        focusConfirm: false,
        preConfirm: () => ({ notes: (document.getElementById('swalResetNotes2').value || '').trim() })
    });
}
function openResetCashModal() {
    showResetBalanceSwal({
        title: 'Reset Cash Balance?',
        actionLabel: 'Reset Cash',
        badgeIcon: 'fa-wallet',
        badgeIconColor: '#047857',
        badgeLabel: 'Cash In Hand',
        badgeBg: { bg: '#ecfdf5', border: '#a7f3d0', iconBg: '#a7f3d0', text: '#065f46' }
    }).then(result => { if (result.isConfirmed) postStockCashAction('reset_cash', result.value); });
}
function openResetBankModal() {
    showResetBalanceSwal({
        title: 'Reset Bank Balance?',
        actionLabel: 'Reset Bank',
        badgeIcon: 'fa-university',
        badgeIconColor: '#0369a1',
        badgeLabel: 'Bank Balance',
        badgeBg: { bg: '#f0f9ff', border: '#bae6fd', iconBg: '#bae6fd', text: '#075985' }
    }).then(result => { if (result.isConfirmed) postStockCashAction('reset_bank', result.value); });
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

/* ---- Per-row fine weight edit (live update, no page reload) ---- */
function saveExchangeFine(transactionId, inputEl) {
    const fine = Math.max(0, parseFloat(inputEl.value) || 0);
    inputEl.value = fine.toFixed(3);
    postExchangeAction('update_exchange_fine', { transaction_id: transactionId, fine_weight: fine }).then(data => {
        if (!data.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to update fine weight' });
            return;
        }
        const row = inputEl.closest('tr');
        if (!row) return;
        const amtCell = row.querySelector('td.num.font-bold');
        if (amtCell) amtCell.innerHTML = '&#8377;' + Math.round(data.amount).toLocaleString('en-IN');
    }).catch(err => Swal.fire({ icon: 'error', title: 'Network Error', text: err.message }));
}

/* ---- Per-row "Transfer" button — moves that exchange's pending fine to fine stock ---- */
function transferExchangeFine(transactionId, inputEl) {
    const fine = parseFloat(inputEl.value) || 0;
    if (fine <= 0) {
        Swal.fire({ icon: 'warning', title: 'Enter fine weight', text: 'Set the fine weight to transfer.' });
        return;
    }
    Swal.fire({
        title: 'Transfer ' + fine.toFixed(3) + ' g to fine stock?',
        html: '<p class="text-xs text-slate-600">Moves the pending fine for this exchange from MIX stock to fine stock.</p>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Transfer',
        confirmButtonColor: '#d97706',
        cancelButtonColor: '#64748b'
    }).then(result => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Transferring…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        postExchangeAction('transfer_exchange_fine', { transaction_id: transactionId, fine_weight: fine }).then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Transferred', text: data.message, timer: 1600, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Transfer failed' });
            }
        }).catch(err => Swal.fire({ icon: 'error', title: 'Network Error', text: err.message }));
    });
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
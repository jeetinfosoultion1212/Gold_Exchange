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

// Fetch Party-wise Summary
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

// Fetch Financial Stats (Cash/Bank In Hand - based on all time or filtered? User said "Stock" which implies current state, but report is daily. Let's show Current Balance for Cash/Bank and Stock)
// Actually, for "Daily Report", showing "Today's In/Out" is more relevant, but "Stock" is cumulative.
// Let's show:
// 1. Cash In Hand (Total Cash In - Total Cash Out)
// 2. Bank Balance (Total Bank In - Total Bank Out)
// 3. Gold Stock (Grouped by Purity)

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

// Total Received Weight from Exchange Transactions
$received_weight_sql = "SELECT COALESCE(SUM(received_weight), 0) as total_received_weight 
    FROM transactions 
    WHERE company_id = $company_id 
    AND transaction_type = 'Exchange' 
    AND DATE(date_of_transaction) BETWEEN '$start_date' AND '$end_date'";
$received_weight_result = $conn->query($received_weight_sql);
$received_weight_data = $received_weight_result->fetch_assoc();

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

$page_title = "Daily Report";
ob_start();
?>

<div class="w-full">
<div class="w-full">
    <!-- Compact Stats Grid -->
    <!-- Ultra-Compact Stats Grid (Full Width) -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-2 mb-4">
        <!-- Cash Balance -->
        <div class="rounded-lg border border-emerald-200/90 bg-gradient-to-br from-emerald-50 via-white to-white p-2 shadow-sm">
            <div class="flex flex-col">
                <div class="flex items-center justify-between gap-1">
                    <p class="text-[8px] font-medium text-emerald-800/80 uppercase tracking-wide">Cash In Hand</p>
                    <div class="relative">
                        <button onclick="toggleDropdown('cash-dropdown')" class="text-emerald-400 hover:text-emerald-600">
                            <i class="fas fa-ellipsis-v text-[8px]"></i>
                        </button>
                        <div id="cash-dropdown" class="hidden absolute right-0 mt-1 w-32 bg-white rounded shadow-lg z-10 border border-gray-100 py-1">
                            <button onclick="openAddCashModal()" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-gray-700 hover:bg-gray-50">Add Cash</button>
                            <button onclick="openTransactionsModal('cash')" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-gray-700 hover:bg-gray-50">History</button>
                            <button onclick="openResetCashModal()" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-red-600 hover:bg-red-50">Reset</button>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between mt-1">
                    <p class="text-xs font-semibold text-emerald-900 tabular-nums">₹<?= number_format($balance_data['cash_balance'] ?? 0) ?></p>
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-emerald-100 text-emerald-700">
                        <i class="fas fa-money-bill-wave text-[11px]"></i>
                    </span>
                </div>
            </div>
        </div>

        <!-- Bank Balance -->
        <div class="rounded-lg border border-sky-200/90 bg-gradient-to-br from-sky-50 via-white to-white p-2 shadow-sm">
            <div class="flex flex-col">
                <div class="flex items-center justify-between gap-1">
                    <p class="text-[8px] font-medium text-sky-800/80 uppercase tracking-wide">Bank Balance</p>
                    <div class="relative">
                        <button onclick="toggleDropdown('bank-dropdown')" class="text-sky-400 hover:text-sky-600">
                            <i class="fas fa-ellipsis-v text-[8px]"></i>
                        </button>
                        <div id="bank-dropdown" class="hidden absolute right-0 mt-1 w-32 bg-white rounded shadow-lg z-10 border border-gray-100 py-1">
                            <button onclick="openAddBankModal()" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-gray-700 hover:bg-gray-50">Add Bank</button>
                            <button onclick="openTransactionsModal('bank')" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-gray-700 hover:bg-gray-50">History</button>
                            <button onclick="openResetBankModal()" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-red-600 hover:bg-red-50">Reset</button>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between mt-1">
                    <p class="text-xs font-semibold text-sky-900 tabular-nums">₹<?= number_format($balance_data['bank_balance'] ?? 0) ?></p>
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-sky-100 text-sky-700">
                        <i class="fas fa-university text-[11px]"></i>
                    </span>
                </div>
            </div>
        </div>

        <!-- Stock Cards (Dynamic) -->
        <?php if (!empty($gold_stocks)): ?>
            <?php foreach ($gold_stocks as $stock): ?>
                <?php 
                    $is_gold = $stock['category'] === 'Gold';
                    $is_cash = $stock['mode'] === 'Cash';
                    $is_positive = $stock['current_stock'] >= 0;
                    $is_mix_stock = (stripos($stock['stock_name'], 'mix') !== false);
                    
                    $metal_color = $is_gold ? 'text-amber-700/90' : 'text-slate-600';
                    $metal_bg = $is_gold ? 'bg-amber-100/60' : 'bg-slate-100/80';
                    $mode_color = $is_cash ? 'text-emerald-700/80' : 'text-sky-700/80';
                    $mode_label = $is_cash ? 'K' : 'P';
                    $status_color = $is_positive ? 'text-emerald-700' : 'text-red-600';
                ?>
                <div class="bg-white rounded-lg border border-slate-200/70 p-2 shadow-sm relative">
                    <div class="flex flex-col">
                        <div class="flex justify-between items-center gap-1">
                            <div class="flex items-center gap-1 min-w-0 flex-1">
                                <span class="text-[7px] px-1 py-0 rounded font-medium uppercase tracking-wide <?= $metal_bg ?> <?= $metal_color ?>"><?= substr($stock['category'], 0, 1) ?></span>
                                <span class="text-[7px] px-1 py-0 rounded font-medium uppercase tracking-wide bg-slate-100 <?= $mode_color ?>"><?= $mode_label ?></span>
                                <?php if ($is_mix_stock): ?>
                                <p class="text-[8px] font-bold <?= $is_gold ? 'text-amber-800' : 'text-slate-700' ?> uppercase truncate" title="<?= $is_gold ? 'Mix stock gold' : 'Mix stock silver' ?>"><?= $is_gold ? 'Mix gold' : 'Mix silver' ?></p>
                                <?php else: ?>
                                <p class="text-[8px] font-medium text-slate-600 uppercase truncate max-w-[50px]"><?= htmlspecialchars($stock['stock_name']) ?></p>
                                <?php endif; ?>
                            </div>
                            <button onclick="toggleDropdown('stock-dropdown-<?= $stock['id'] ?>')" class="text-gray-300 hover:text-gray-500 shrink-0" type="button" aria-label="Stock actions">
                                <i class="fas fa-ellipsis-v text-[8px]"></i>
                            </button>
                            <div id="stock-dropdown-<?= $stock['id'] ?>" class="hidden absolute right-0 mt-1 w-32 bg-white rounded shadow-lg z-10 border border-gray-100 p-1">
                                <button onclick="openAddStockModal(<?= $stock['id'] ?>, '<?= htmlspecialchars($stock['stock_name']) ?>', <?= $stock['purity'] ?>)" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-gray-700 hover:bg-gray-50">Add</button>
                                <button onclick="openTransactionsModal('stock', <?= $stock['id'] ?>)" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-gray-700 hover:bg-gray-50">History</button>
                                <button onclick="openResetStockModal(<?= $stock['id'] ?>, '<?= htmlspecialchars($stock['stock_name']) ?>', <?= $stock['purity'] ?>)" class="block w-full text-left px-2 py-1 text-[9px] font-bold text-red-600 hover:bg-red-50">Reset</button>
                            </div>
                        </div>
                        <div class="flex items-end justify-between gap-2 mt-1">
                            <?php if ($is_mix_stock): ?>
                            <?php
                                $mix_side_gold = $is_gold;
                                $mix_line_rcv = $mix_side_gold ? $mix_rcv_gold : $mix_rcv_silver;
                                $mix_line_fn = $mix_side_gold ? $mix_fine_gold : $mix_fine_silver;
                            ?>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold text-emerald-700 tabular-nums leading-none">
                                    <span class="text-[9px] font-medium text-slate-500 normal-case mr-1">Rcv</span><?= number_format($mix_line_rcv, 3) ?><span class="text-[9px] font-normal text-slate-500 ml-0.5">g</span>
                                </p>
                                <p class="text-xs font-semibold text-emerald-700 tabular-nums leading-tight mt-1">
                                    <span class="text-[9px] font-medium text-slate-500 normal-case mr-1">Fine</span><?= number_format($mix_line_fn, 3) ?><span class="text-[9px] font-normal text-slate-500 ml-0.5">g</span>
                                </p>
                            </div>
                            <span class="text-[8px] text-slate-400 font-medium shrink-0 self-end tabular-nums min-w-[2rem] text-right" title="Purity not used for mix (period exchange received)">—</span>
                            <?php else: ?>
                            <p class="text-xs font-semibold <?= $status_color ?> tabular-nums">
                                <?= number_format(abs($stock['current_stock']), 3) ?><span class="text-[9px] font-normal text-slate-500 ml-0.5">g</span>
                            </p>
                            <span class="text-[8px] text-slate-400 font-medium shrink-0"><?= number_format($stock['purity'], 1) ?>%</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($show_synth_mix_gold): ?>
        <div class="bg-white rounded-lg border border-dashed border-amber-200/80 p-2 shadow-sm relative">
            <div class="flex flex-col">
                <div class="flex justify-between items-center gap-1">
                    <div class="flex items-center gap-1 min-w-0 flex-1">
                        <span class="text-[7px] px-1 py-0 rounded font-medium uppercase tracking-wide bg-amber-100/60 text-amber-700/90">G</span>
                        <span class="text-[7px] px-1 py-0 rounded font-medium uppercase tracking-wide bg-slate-100 text-emerald-700/80">K</span>
                        <p class="text-[8px] font-bold text-amber-800 uppercase truncate">Mix gold</p>
                    </div>
                    <span class="inline-block w-3 shrink-0" aria-hidden="true"></span>
                </div>
                <div class="flex items-end justify-between gap-2 mt-1">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold text-emerald-700 tabular-nums leading-none">
                            <span class="text-[9px] font-medium text-slate-500 normal-case mr-1">Rcv</span><?= number_format($mix_rcv_gold, 3) ?><span class="text-[9px] font-normal text-slate-500 ml-0.5">g</span>
                        </p>
                        <p class="text-xs font-semibold text-emerald-700 tabular-nums leading-tight mt-1">
                            <span class="text-[9px] font-medium text-slate-500 normal-case mr-1">Fine</span><?= number_format($mix_fine_gold, 3) ?><span class="text-[9px] font-normal text-slate-500 ml-0.5">g</span>
                        </p>
                    </div>
                    <span class="text-[8px] text-slate-400 font-medium shrink-0 self-end tabular-nums min-w-[2rem] text-right" title="Placeholder card">—</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($show_synth_mix_silver): ?>
        <div class="bg-white rounded-lg border border-dashed border-slate-300/80 p-2 shadow-sm relative">
            <div class="flex flex-col">
                <div class="flex justify-between items-center gap-1">
                    <div class="flex items-center gap-1 min-w-0 flex-1">
                        <span class="text-[7px] px-1 py-0 rounded font-medium uppercase tracking-wide bg-slate-100/80 text-slate-600">S</span>
                        <span class="text-[7px] px-1 py-0 rounded font-medium uppercase tracking-wide bg-slate-100 text-emerald-700/80">K</span>
                        <p class="text-[8px] font-bold text-slate-700 uppercase truncate">Mix silver</p>
                    </div>
                    <span class="inline-block w-3 shrink-0" aria-hidden="true"></span>
                </div>
                <div class="flex items-end justify-between gap-2 mt-1">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold text-emerald-700 tabular-nums leading-none">
                            <span class="text-[9px] font-medium text-slate-500 normal-case mr-1">Rcv</span><?= number_format($mix_rcv_silver, 3) ?><span class="text-[9px] font-normal text-slate-500 ml-0.5">g</span>
                        </p>
                        <p class="text-xs font-semibold text-emerald-700 tabular-nums leading-tight mt-1">
                            <span class="text-[9px] font-medium text-slate-500 normal-case mr-1">Fine</span><?= number_format($mix_fine_silver, 3) ?><span class="text-[9px] font-normal text-slate-500 ml-0.5">g</span>
                        </p>
                    </div>
                    <span class="text-[8px] text-slate-400 font-medium shrink-0 self-end tabular-nums min-w-[2rem] text-right" title="Purity not used for mix">—</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
        
        <!-- Received Weight Stat -->
        <!-- Legacy Received Weight Stat Removed - Now tracked in Gold Stock -->
    </div>

    <!-- Header & Filters (Compact) -->
    <div class="bg-white rounded shadow-sm px-3 py-2 mb-4 border-b border-slate-100">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-tighter">Daily Report</h2>
                <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Transaction Summary</p>
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
                    <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-[10px] font-bold uppercase rounded shadow-sm hover:bg-red-700 tracking-tighter">
                        <i class="fas fa-filter mr-1"></i>Filter
                    </button>
                    <a href="export_report_pdf.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" target="_blank" class="px-3 py-1.5 bg-white border border-slate-200 text-slate-600 text-[10px] font-bold uppercase rounded hover:bg-slate-50 tracking-tighter shadow-sm">
                        <i class="fas fa-file-pdf mr-1 text-red-500"></i>PDF
                    </a>
                </form>
            </div>
        </div>
    </div>

        <!-- Report Table -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Party Name</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Booking (g)</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Sale (g)</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Purchase (g)</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Issue (g)</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Received Wt (g)</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Gold Rcv (g)</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-l border-gray-200">
                                Payment Received (In)
                                <div class="grid grid-cols-2 gap-2 mt-1 border-t border-gray-200 pt-1">
                                    <span>Cash</span>
                                    <span>Bank</span>
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-l border-gray-200">
                                Payment Paid (Out)
                                <div class="grid grid-cols-2 gap-2 mt-1 border-t border-gray-200 pt-1">
                                    <span>Cash</span>
                                    <span>Bank</span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-10 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-3 text-gray-300"></i>
                                <p>No transactions found for the selected period.</p>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php 
                            $total_booking = 0;
                            $total_sale = 0;
                            $total_purchase = 0;
                            $total_issue = 0;
                            $total_exchange_rcv = 0;
                            $total_gold_rcv = 0;
                            $total_cash_in = 0;
                            $total_bank_in = 0;
                            $total_cash_out = 0;
                            $total_bank_out = 0;
                            ?>
                            <?php foreach ($reports as $row): ?>
                                <?php 
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
                                ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($row['party_name']) ?>
                                        <?php if($row['contact_no']): ?>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($row['contact_no']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">
                                        <?= $row['booking_weight'] > 0 ? number_format($row['booking_weight'], 2) : '-' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">
                                        <?= $row['sale_weight'] > 0 ? number_format($row['sale_weight'], 2) : '-' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">
                                        <?= $row['purchase_weight'] > 0 ? number_format($row['purchase_weight'], 2) : '-' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">
                                        <?= $row['issue_weight'] > 0 ? number_format($row['issue_weight'], 2) : '-' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">
                                        <?= $row['gold_received_weight'] > 0 ? number_format($row['gold_received_weight'], 2) : '-' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">
                                        <?= $row['exchange_received_weight'] > 0 ? number_format($row['exchange_received_weight'], 2) : '-' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right border-l border-gray-200">
                                        <div class="grid grid-cols-2 gap-4">
                                            <span class="<?= $row['cash_in'] > 0 ? 'text-green-600 font-medium' : 'text-gray-400' ?>">
                                                <?= $row['cash_in'] > 0 ? '₹'.number_format($row['cash_in']) : '-' ?>
                                            </span>
                                            <span class="<?= $row['bank_in'] > 0 ? 'text-blue-600 font-medium' : 'text-gray-400' ?>">
                                                <?= $row['bank_in'] > 0 ? '₹'.number_format($row['bank_in']) : '-' ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right border-l border-gray-200">
                                        <div class="grid grid-cols-2 gap-4">
                                            <span class="<?= $row['cash_out'] > 0 ? 'text-red-600 font-medium' : 'text-gray-400' ?>">
                                                <?= $row['cash_out'] > 0 ? '₹'.number_format($row['cash_out']) : '-' ?>
                                            </span>
                                            <span class="<?= $row['bank_out'] > 0 ? 'text-orange-600 font-medium' : 'text-gray-400' ?>">
                                                <?= $row['bank_out'] > 0 ? '₹'.number_format($row['bank_out']) : '-' ?>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <!-- Total Row -->
                            <tr class="bg-gray-100 font-bold">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">TOTAL</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900"><?= number_format($total_booking, 2) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900"><?= number_format($total_sale, 2) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900"><?= number_format($total_purchase, 2) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900"><?= number_format($total_issue, 2) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900"><?= number_format($total_gold_rcv, 2) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900"><?= number_format($total_exchange_rcv, 2) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right border-l border-gray-200">
                                    <div class="grid grid-cols-2 gap-4">
                                        <span class="text-green-700">₹<?= number_format($total_cash_in) ?></span>
                                        <span class="text-blue-700">₹<?= number_format($total_bank_in) ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right border-l border-gray-200">
                                    <div class="grid grid-cols-2 gap-4">
                                        <span class="text-red-700">₹<?= number_format($total_cash_out) ?></span>
                                        <span class="text-orange-700">₹<?= number_format($total_bank_out) ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Stock Modal -->
<div id="addStockModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Add Gold Stock</h3>
            <button onclick="closeModal('addStockModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="addStockForm" onsubmit="submitStockAction(event, 'add_stock')">
            <div class="mb-3">
                <label class="block text-[11px] font-bold text-gray-700 mb-1 uppercase tracking-tighter">Category <span class="text-red-500">*</span></label>
                <select name="category" required class="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-amber-500">
                    <option value="Gold">Gold</option>
                    <option value="Silver">Silver</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-[11px] font-bold text-gray-700 mb-1 uppercase tracking-tighter">Stock Name <span class="text-red-500">*</span></label>
                <input type="text" name="stock_name" required placeholder="e.g., Fine, 22K, 18K" class="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-amber-500">
            </div>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-1 uppercase tracking-tighter">Purity (%) <span class="text-red-500">*</span></label>
                    <input type="number" step="0.01" name="purity" id="addStockPurityInput" required min="0" max="100" placeholder="0.00" class="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-700 mb-1 uppercase tracking-tighter">Weight (g) <span class="text-red-500">*</span></label>
                    <input type="number" step="0.001" name="amount" required placeholder="0.000" class="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-amber-500">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-[11px] font-bold text-gray-700 mb-1 uppercase tracking-tighter">Mode <span class="text-red-500">*</span></label>
                <select name="stock_type" required class="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-amber-500">
                    <option value="Cash">Cash (Kachha)</option>
                    <option value="Bank">Bank (Pakka)</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-[11px] font-bold text-gray-700 mb-1 uppercase tracking-tighter">Notes</label>
                <textarea name="notes" rows="2" placeholder="Optional details..." class="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-amber-500"></textarea>
            </div>
            <input type="hidden" name="stock_id" id="addStockId">
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-amber-600 text-white text-[10px] font-bold uppercase py-2 rounded shadow hover:bg-amber-700 transition tracking-tighter">
                    <i class="fas fa-plus mr-1"></i>Add Stock
                </button>
                <button type="button" onclick="closeModal('addStockModal')" class="flex-1 bg-gray-100 text-gray-600 text-[10px] font-bold uppercase py-2 rounded hover:bg-gray-200 transition tracking-tighter">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Stock Modal -->
<div id="resetStockModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Reset Gold Stock</h3>
            <button onclick="closeModal('resetStockModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="resetStockForm" onsubmit="submitStockAction(event, 'reset_stock')">
            <input type="hidden" id="resetStockId" name="stock_id">
            <input type="hidden" id="resetStockPurity" name="purity">
            <div class="mb-4">
                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-3">
                    <p class="text-sm text-yellow-800">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        This will reset the stock to 0 grams. This action will be logged.
                    </p>
                </div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Purity</label>
                <input type="text" id="resetStockPurityDisplay" readonly class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Reason for Reset</label>
                <textarea name="notes" rows="2" placeholder="Enter reason for resetting stock..." class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                    <i class="fas fa-redo mr-2"></i>Reset Stock
                </button>
                <button type="button" onclick="closeModal('resetStockModal')" class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Cash Modal -->
<div id="addCashModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Add Cash</h3>
            <button onclick="closeModal('addCashModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="addCashForm" onsubmit="submitCashBankAction(event, 'add_cash')">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Amount (₹) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" name="amount" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                    <i class="fas fa-plus mr-2"></i>Add Cash
                </button>
                <button type="button" onclick="closeModal('addCashModal')" class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Cash Modal -->
<div id="resetCashModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Reset Cash Balance</h3>
            <button onclick="closeModal('resetCashModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="resetCashForm" onsubmit="submitCashBankAction(event, 'reset_cash')">
            <div class="mb-4">
                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-3">
                    <p class="text-sm text-yellow-800">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        This will reset the cash balance to ₹0. This action will be logged.
                    </p>
                </div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Reason for Reset</label>
                <textarea name="notes" rows="2" placeholder="Enter reason for resetting cash..." class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                    <i class="fas fa-redo mr-2"></i>Reset Cash
                </button>
                <button type="button" onclick="closeModal('resetCashModal')" class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Bank Modal -->
<div id="addBankModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Add Bank Amount</h3>
            <button onclick="closeModal('addBankModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="addBankForm" onsubmit="submitCashBankAction(event, 'add_bank')">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Amount (₹) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" name="amount" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                    <i class="fas fa-plus mr-2"></i>Add Bank
                </button>
                <button type="button" onclick="closeModal('addBankModal')" class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Bank Modal -->
<div id="resetBankModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Reset Bank Balance</h3>
            <button onclick="closeModal('resetBankModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="resetBankForm" onsubmit="submitCashBankAction(event, 'reset_bank')">
            <div class="mb-4">
                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-3">
                    <p class="text-sm text-yellow-800">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        This will reset the bank balance to ₹0. This action will be logged.
                    </p>
                </div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Reason for Reset</label>
                <textarea name="notes" rows="2" placeholder="Enter reason for resetting bank balance..." class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                    <i class="fas fa-redo mr-2"></i>Reset Bank
                </button>
                <button type="button" onclick="closeModal('resetBankModal')" class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transaction History Modal -->
<div id="transactionsModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900" id="transactionsModalTitle">Transaction History</h3>
            <button onclick="closeModal('transactionsModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="transactionsLoading" class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="text-gray-500 mt-2">Loading transactions...</p>
        </div>
        
        <div id="transactionsContent" class="hidden">
            <div class="overflow-x-auto max-h-96">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody" class="bg-white divide-y divide-gray-200">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
            
            <div id="noTransactions" class="hidden text-center py-8">
                <i class="fas fa-inbox text-4xl text-gray-300"></i>
                <p class="text-gray-500 mt-2">No transactions found</p>
            </div>
        </div>
        
        <div class="mt-4 flex justify-end">
            <button onclick="closeModal('transactionsModal')" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                Close
            </button>
        </div>
    </div>
</div>

<script>
// Toggle dropdown menu
function toggleDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    const allDropdowns = document.querySelectorAll('[id$="-dropdown"]');
    
    // Close all other dropdowns
    allDropdowns.forEach(d => {
        if (d.id !== dropdownId) {
            d.classList.add('hidden');
        }
    });
    
    dropdown.classList.toggle('hidden');
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('button')) {
        const allDropdowns = document.querySelectorAll('[id$="-dropdown"]');
        allDropdowns.forEach(d => d.classList.add('hidden'));
    }
});

// Modal functions
function openAddStockModalFree() {
    // Clear the form
    document.getElementById('addStockPurityInput').value = '';
    document.getElementById('addStockModal').classList.remove('hidden');
}

function openAddStockModal(stockId, stockName, purity) {
    // Pre-fill fields when adding to existing stock
    document.getElementById('addStockId').value = stockId;
    document.getElementById('addStockPurityInput').value = purity;
    // You might want to pre-fill stock name too if you had an input for it that wasn't readonly
    // But typically add stock to existing implies keeping same name/purity
    document.getElementById('addStockModal').classList.remove('hidden');
    toggleDropdown('stock-dropdown-' + stockId);
}

function openResetStockModal(stockId, stockName, purity) {
    document.getElementById('resetStockId').value = stockId;
    document.getElementById('resetStockPurity').value = purity;
    document.getElementById('resetStockPurityDisplay').value = stockName + ' (' + purity + '%)';
    document.getElementById('resetStockModal').classList.remove('hidden');
    toggleDropdown('stock-dropdown-' + stockId);
}

function openAddCashModal() {
    document.getElementById('addCashModal').classList.remove('hidden');
    toggleDropdown('cash-dropdown');
}

function openResetCashModal() {
    document.getElementById('resetCashModal').classList.remove('hidden');
    toggleDropdown('cash-dropdown');
}

function openAddBankModal() {
    document.getElementById('addBankModal').classList.remove('hidden');
    toggleDropdown('bank-dropdown');
}

function openResetBankModal() {
    document.getElementById('resetBankModal').classList.remove('hidden');
    toggleDropdown('bank-dropdown');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

// Submit stock actions
function submitStockAction(event, action) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', action);
    
    fetch('handlers/manage_stock_cash.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

// Submit cash/bank actions
function submitCashBankAction(event, action) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', action);
    
    fetch('handlers/manage_stock_cash.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

// Open transactions modal and load data
function openTransactionsModal(type, id = null) {
    const modal = document.getElementById('transactionsModal');
    const title = document.getElementById('transactionsModalTitle');
    const loading = document.getElementById('transactionsLoading');
    const content = document.getElementById('transactionsContent');
    
    // Set title based on type
    if (type === 'cash') {
        title.textContent = 'Cash Transaction History';
    } else if (type === 'bank') {
        title.textContent = 'Bank Transaction History';
    } else if (type === 'stock') {
        title.textContent = `Gold Stock Transaction History`;
    }
    
    // Show modal and loading state
    modal.classList.remove('hidden');
    loading.classList.remove('hidden');
    content.classList.add('hidden');
    
    // Close any open dropdowns
    const allDropdowns = document.querySelectorAll('[id$="-dropdown"]');
    allDropdowns.forEach(d => d.classList.add('hidden'));
    
    // Fetch transactions
    const formData = new FormData();
    formData.append('action', 'get_transactions');
    formData.append('type', type);
    if (id) {
         formData.append('stock_id', id);
    }
    /* purity removed as it was undefined and not needed */
    
    fetch('handlers/manage_stock_cash.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
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
        alert('Error loading transactions: ' + error.message);
        closeModal('transactionsModal');
    });
}

// Display transactions in table
function displayTransactions(transactions, type) {
    const tbody = document.getElementById('transactionsTableBody');
    tbody.innerHTML = '';
    
    transactions.forEach(transaction => {
        const row = document.createElement('tr');
        row.className = 'hover:bg-gray-50';
        
        // Format date
        const date = new Date(transaction.date_of_transaction);
        const dateStr = date.toLocaleDateString('en-IN') + ' ' + date.toLocaleTimeString('en-IN', {hour: '2-digit', minute: '2-digit'});
        
        // Determine amount and type display
        let amount, typeDisplay, typeColor;
        if (type === 'stock') {
            // For Exchange, we issue Fine Gold (fine_weight), so use that if available and type is Exchange
            if (transaction.transaction_type === 'Exchange' && transaction.fine_weight > 0) {
                 amount = parseFloat(transaction.fine_weight).toFixed(3) + ' g';
            } else {
                 amount = parseFloat(transaction.gold_weight).toFixed(3) + ' g';
            }
            typeDisplay = transaction.transaction_type.replace('_', ' ');
            
            // Color logic: Additions (Green), Deductions (Red)
            if (['Stock_Addition', 'Purchase', 'Exchange_Received', 'Received'].includes(transaction.transaction_type)) {
                typeColor = 'text-green-600';
            } else if (['Sale', 'Stock_Deduction', 'Issue', 'Exchange'].includes(transaction.transaction_type)) {
                typeColor = 'text-red-600';
            } else if (transaction.transaction_type === 'Stock_Reset') {
                 // Reset could be either, but usually implies setting a value. Let's keep it neutral or specific
                 typeColor = 'text-blue-600';
            } else {
                 // Default for unknown like 'Exchange' if not specific
                 typeColor = 'text-gray-600';
            }
        } else {
            amount = '₹' + parseFloat(transaction.payment_amount).toLocaleString('en-IN');
            
            if (transaction.payment_type === 'Payment_In') {
                typeDisplay = 'Payment In';
                typeColor = 'text-green-600';
            } else if (transaction.payment_type === 'Payment_Out') {
                typeDisplay = 'Payment Out';
                typeColor = 'text-red-600';
            } else {
                typeDisplay = transaction.transaction_type;
                typeColor = 'text-gray-600';
            }
        }
        
        // Extract narration (remove system-generated prefixes)
        let notes = transaction.narration || '-';
        notes = notes.replace(/^(Cash Addition:|Bank Addition:|Stock Addition \(.*?\):)\s*/, '');
        if (notes.trim() === '') notes = '-';
        
        row.innerHTML = `
            <td class="px-4 py-3 text-sm text-gray-700">${dateStr}</td>
            <td class="px-4 py-3 text-sm">
                <span class="font-medium ${typeColor}">${typeDisplay}</span>
                ${transaction.payment_method ? '<br><span class="text-xs text-gray-500">' + transaction.payment_method + '</span>' : ''}
            </td>
            <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">${amount}</td>
            <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate" title="${notes}">${notes}</td>
            <td class="px-4 py-3 text-center">
                <button onclick="deleteTransaction(${transaction.id})" class="text-red-600 hover:text-red-800 text-sm">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        
        tbody.appendChild(row);
    });
}

// Delete transaction with confirmation
function deleteTransaction(transactionId) {
    if (!confirm('Are you sure you want to delete this transaction? This action will be logged for audit purposes.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete_transaction');
    formData.append('transaction_id', transactionId);
    
    fetch('handlers/manage_stock_cash.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}
</script>

<?php
$content = ob_get_clean();
include 'components/layout.php';
?>

<?php
/**
 * GOLD RECEIPT PAGE
 * 
 * PURPOSE: This page is used to record when GOLD IS RECEIVED from parties/customers.
 * 
 * What it does:
 * - Records gold received from a party (opposite of issuing gold)
 * - Updates party's gold balance (increases it)
 * - Tracks whether gold was received via Cash or Bank
 * - Optionally adjusts cash/bank balance when gold is received
 * - Generates a receipt for the gold received transaction
 * 
 * Use case: When a customer/party returns gold to you, use this page to record it.
 * 
 * Example: A party returns 50g of gold at ₹5000/g rate via Cash method.
 * This will increase their gold balance by 50g and optionally adjust their cash balance.
 */
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Load database configuration
require_once __DIR__ . '/config/database.php';

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error . '. Please run setup_database.php first.');
}

// Get user and company info
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];
$user_name = $_SESSION['full_name'];

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_gold_receipt':
                $conn->begin_transaction();
                try {
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                    $party_id = intval($_POST['party_id']);
                    $gold_weight = floatval($_POST['gold_weight']);
                    $gold_rate = floatval($_POST['gold_rate']);
                    $gold_amount = $gold_weight * $gold_rate;
                    $receipt_method = $conn->real_escape_string($_POST['receipt_method']);
                    $adjust_balance = isset($_POST['adjust_balance']) ? true : false;
                    $adjustment_amount = floatval($_POST['adjustment_amount'] ?? 0);
                    $narration = $conn->real_escape_string($_POST['narration'] ?? '');

                    // Insert transaction
                    $insert_sql = "INSERT INTO transactions (
                        receipt_id, date_of_transaction, party_id, company_id, user_id,
                        transaction_type, gold_weight, rate, gold_amount, receipt_method,
                        narration
                    ) VALUES (
                        '$receipt_id', '$date_of_transaction', $party_id, $company_id, $user_id,
                        'Gold_Received', $gold_weight, $gold_rate, $gold_amount, '$receipt_method',
                        '$narration'
                    )";

                    if (!$conn->query($insert_sql)) {
                        throw new Exception("Failed to insert transaction: " . $conn->error);
                    }

                    // Update party gold balances
                    if ($receipt_method === 'Cash') {
                        $update_sql = "UPDATE parties SET 
                            current_gold_balance = current_gold_balance + $gold_weight,
                            cash_gold_balance = cash_gold_balance + $gold_weight
                            WHERE id = $party_id AND company_id = $company_id";
                    } else {
                        $update_sql = "UPDATE parties SET 
                            current_gold_balance = current_gold_balance + $gold_weight,
                            bank_gold_balance = bank_gold_balance + $gold_weight
                            WHERE id = $party_id AND company_id = $company_id";
                    }

                    if (!$conn->query($update_sql)) {
                        throw new Exception("Failed to update party gold balance: " . $conn->error);
                    }

                    // Optional balance adjustment
                    if ($adjust_balance && $adjustment_amount != 0) {
                        if ($receipt_method === 'Cash') {
                            $balance_sql = "UPDATE parties SET 
                                current_balance = current_balance + $adjustment_amount,
                                cash_balance = cash_balance + $adjustment_amount
                                WHERE id = $party_id AND company_id = $company_id";
                        } else {
                            $balance_sql = "UPDATE parties SET 
                                current_balance = current_balance + $adjustment_amount,
                                bank_balance = bank_balance + $adjustment_amount
                                WHERE id = $party_id AND company_id = $company_id";
                        }

                        if (!$conn->query($balance_sql)) {
                            throw new Exception("Failed to update party balance: " . $conn->error);
                        }
                    }

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Gold receipt saved successfully']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;

            case 'search_parties':
                $search = $conn->real_escape_string($_POST['term'] ?? '');
                $sql = "SELECT id, party_name, address, contact_no FROM parties 
                        WHERE company_id = $company_id 
                        AND party_name LIKE '%$search%' 
                        ORDER BY party_name 
                        LIMIT 10";
                $result = $conn->query($sql);
                $parties = [];
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $parties[] = $row;
                    }
                }
                echo json_encode($parties);
                exit;

            case 'get_stats':
                // Get today's gold receipt statistics
                $today = date('Y-m-d');
                $stats_sql = "SELECT 
                    SUM(CASE WHEN receipt_method = 'Cash' THEN gold_weight ELSE 0 END) as total_cash_gold_issued,
                    SUM(CASE WHEN receipt_method = 'Bank' THEN gold_weight ELSE 0 END) as total_bank_gold_issued,
                    SUM(gold_weight) as total_gold_issued,
                    SUM(gold_amount) as total_value,
                    COUNT(*) as total_receipts,
                    AVG(rate) as average_rate
                    FROM transactions 
                    WHERE transaction_type = 'Gold_Received' 
                    AND company_id = $company_id 
                    AND DATE(date_of_transaction) = '$today'";
                
                $stats_result = $conn->query($stats_sql);
                $stats = $stats_result->fetch_assoc();
                echo json_encode($stats);
                exit;

            case 'get_recent_receipts':
                $sql = "SELECT t.*, p.party_name 
                        FROM transactions t 
                        JOIN parties p ON t.party_id = p.id 
                        WHERE t.transaction_type = 'Gold_Received' 
                        AND t.company_id = $company_id 
                        ORDER BY t.date_of_transaction DESC 
                        LIMIT 10";
                $result = $conn->query($sql);
                $receipts = [];
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $receipts[] = $row;
                    }
                }
                echo json_encode(['receipts' => $receipts]);
                exit;
        }
    }
}

// Get today's statistics
$today = date('Y-m-d');
$stats_sql = "SELECT 
    SUM(CASE WHEN receipt_method = 'Cash' THEN gold_weight ELSE 0 END) as total_cash_gold_issued,
    SUM(CASE WHEN receipt_method = 'Bank' THEN gold_weight ELSE 0 END) as total_bank_gold_issued,
    SUM(gold_weight) as total_gold_issued,
    SUM(gold_amount) as total_value,
    COUNT(*) as total_receipts,
    AVG(rate) as average_rate
    FROM transactions 
    WHERE transaction_type = 'Gold_Received' 
    AND company_id = $company_id 
    AND DATE(date_of_transaction) = '$today'";

$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get recent receipts
$receipts_sql = "SELECT t.*, p.party_name 
                FROM transactions t 
                JOIN parties p ON t.party_id = p.id 
                WHERE t.transaction_type = 'Gold_Received' 
                AND t.company_id = $company_id 
                ORDER BY t.date_of_transaction DESC 
                LIMIT 10";
$receipts_result = $conn->query($receipts_sql);

// Set page title
$page_title = 'Gold Receipt Management';

// Start output buffering to capture content
ob_start();
?>

<style>
/* Soft gradient backgrounds */
.soft-gradient-blue { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05)); }
.soft-gradient-green { background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05)); }
.soft-gradient-yellow { background: linear-gradient(135deg, rgba(251, 191, 36, 0.1), rgba(251, 191, 36, 0.05)); }
.soft-gradient-orange { background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(249, 115, 22, 0.05)); }
.soft-gradient-purple { background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.05)); }
.soft-gradient-red { background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05)); }

.responsive-table {
    font-size: 0.75rem;
}
.responsive-table th,
.responsive-table td {
    padding: 0.375rem 0.25rem;
}
@media (max-width: 768px) {
    .responsive-table {
        font-size: 0.7rem;
    }
    .responsive-table th,
    .responsive-table td {
        font-size: 0.75rem !important;
        padding: 0.375rem 0.25rem !important;
    }
}

/* Validation error styles */
.validation-error {
    display: block;
    min-height: 1.25rem;
    line-height: 1.25rem;
}

.validation-error.hidden {
    display: none;
}

input.border-red-500,
select.border-red-500,
textarea.border-red-500 {
    border-color: #ef4444 !important;
    box-shadow: 0 0 0 1px #ef4444;
}

input.border-red-500:focus,
select.border-red-500:focus,
textarea.border-red-500:focus {
    border-color: #ef4444 !important;
    box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
}

/* Ensure readonly fields are still focusable for keyboard navigation */
input[readonly]:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

/* Smooth scrolling for focus */
html {
    scroll-behavior: smooth;
}
</style>

<!-- Colorful Statistics with Icons -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
    <!-- Cash Gold Issued -->
    <div class="soft-gradient-orange rounded-xl p-4 shadow-sm h-full">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-orange-700 mb-1">Cash Gold Issued</p>
                <p class="text-lg font-bold text-orange-800 mb-0"><?= number_format($stats['total_cash_gold_issued'] ?? 0, 3) ?>g</p>
                <p class="text-xs text-orange-600 mb-0">Cash Method</p>
            </div>
            <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-wallet text-white text-sm"></i>
            </div>
        </div>
    </div>
    
    <!-- Bank Gold Issued -->
    <div class="soft-gradient-blue rounded-xl p-4 shadow-sm h-full">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-blue-700 mb-1">Bank Gold Issued</p>
                <p class="text-lg font-bold text-blue-800 mb-0"><?= number_format($stats['total_bank_gold_issued'] ?? 0, 3) ?>g</p>
                <p class="text-xs text-blue-600 mb-0">Bank Method</p>
            </div>
            <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-university text-white text-sm"></i>
            </div>
        </div>
    </div>
    
    <!-- Total Gold Issued -->
    <div class="soft-gradient-yellow rounded-xl p-4 shadow-sm h-full">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-yellow-700 mb-1">Total Gold Issued</p>
                <p class="text-lg font-bold text-yellow-800 mb-0"><?= number_format($stats['total_gold_issued'] ?? 0, 3) ?>g</p>
                <p class="text-xs text-yellow-600 mb-0">Today's Total</p>
            </div>
            <div class="w-10 h-10 bg-yellow-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-coins text-white text-sm"></i>
            </div>
        </div>
    </div>
    
    <!-- Total Value -->
    <div class="soft-gradient-green rounded-xl p-4 shadow-sm h-full">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-green-700 mb-1">Total Value</p>
                <p class="text-lg font-bold text-green-800 mb-0">₹<?= number_format($stats['total_value'] ?? 0, 0) ?></p>
                <p class="text-xs text-green-600 mb-0">Gold Value</p>
            </div>
            <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-rupee-sign text-white text-sm"></i>
            </div>
        </div>
    </div>
    
    <!-- Total Receipts -->
    <div class="soft-gradient-purple rounded-xl p-4 shadow-sm h-full">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-purple-700 mb-1">Total Receipts</p>
                <p class="text-lg font-bold text-purple-800 mb-0"><?= number_format($stats['total_receipts'] ?? 0, 0) ?></p>
                <p class="text-xs text-purple-600 mb-0">Receipt Count</p>
            </div>
            <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-receipt text-white text-sm"></i>
            </div>
        </div>
    </div>
    
    <!-- Average Rate -->
    <div class="soft-gradient-red rounded-xl p-4 shadow-sm h-full">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-red-700 mb-1">Average Rate</p>
                <p class="text-lg font-bold text-red-800 mb-0">₹<?= number_format($stats['average_rate'] ?? 0, 0) ?></p>
                <p class="text-xs text-red-600 mb-0">Per Gram</p>
            </div>
            <div class="w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-percentage text-white text-sm"></i>
            </div>
        </div>
    </div>
</div>

<!-- Main Form and List Layout -->
<div class="flex flex-col lg:flex-row gap-6">
    <!-- Left Side - Gold Receipt Form -->
    <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
        <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 px-4 py-2 rounded-t-lg">
            <h2 class="text-base font-semibold text-white flex items-center">
                <i class="fas fa-coins mr-2"></i>
                Gold Receipt
            </h2>
        </div>
        <form id="goldReceiptForm" class="p-4 space-y-4">
            <!-- Receipt ID and Date -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receipt ID</label>
                    <input type="text" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-gray-50" 
                           id="receiptId" name="receipt_id" value="GR<?= str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT) ?>" readonly tabindex="0">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date & Time</label>
                    <input type="text" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-gray-50" 
                           id="dateTime" value="<?= date('m/d/Y h:i A') ?>" readonly>
                    <input type="hidden" id="dateTimeActual" name="date_of_transaction" value="<?= date('Y-m-d H:i:s') ?>">
                </div>
            </div>

            <!-- Party Selection -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Party <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input type="text" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500" id="partyNameInput" name="party_name" placeholder="Type party name to search..." autocomplete="off">
                    <div id="partyList" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto z-50 hidden mt-1" style="width: 100%;"></div>
                </div>
                <input type="hidden" id="partyId" name="party_id">
                <div id="selectedPartyDisplay" class="mt-1 text-xs text-green-600 font-medium hidden">
                    <i class="fas fa-check-circle mr-1"></i><span id="selectedPartyName"></span>
                </div>
                <div class="validation-error hidden text-red-500 text-xs mt-1" id="partyNameInput-error"></div>
            </div>

            <!-- Gold Details -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gold Weight (g)</label>
                    <input type="number" step="0.001" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500" id="goldWeight" name="gold_weight" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rate (₹/g)</label>
                    <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500" id="goldRate" name="gold_rate" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total Value (₹)</label>
                    <input type="text" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-gray-50" id="totalValue" readonly>
                </div>
            </div>

            <!-- Receipt Method -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Method</label>
                <select class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500" id="receiptMethod" name="receipt_method" required>
                    <option value="">Select Method</option>
                    <option value="Cash">💵 Cash</option>
                    <option value="Bank">🏦 Bank</option>
                </select>
            </div>

            <!-- Optional Balance Adjustment -->
            <div>
                <label class="flex items-center">
                    <input type="checkbox" id="adjustBalance" class="rounded border-gray-300 text-yellow-600 focus:ring-yellow-500">
                    <span class="ml-2 text-sm text-gray-700">Adjust cash/bank balance by gold value</span>
                </label>
                <div id="adjustmentField" class="mt-2 hidden">
                    <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500" id="adjustmentAmount" name="adjustment_amount" placeholder="Adjustment amount">
                </div>
            </div>

            <!-- Narration -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Narration (Optional)</label>
                <textarea class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500" rows="3" name="narration" placeholder="Enter any additional notes..."></textarea>
            </div>

            <!-- Submit Buttons -->
            <div class="grid grid-cols-2 gap-3">
                <button type="submit" class="px-4 py-2 bg-yellow-600 text-white text-sm font-medium rounded-lg hover:bg-yellow-700 focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 flex items-center justify-center">
                    <i class="fas fa-coins mr-2"></i>Issue Gold Receipt
                </button>
                <button type="button" id="resetFormBtn" class="px-4 py-2 bg-gray-500 text-white text-sm font-medium rounded-lg hover:bg-gray-600 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 flex items-center justify-center">
                    <i class="fas fa-undo mr-2"></i>Reset
                </button>
            </div>
        </form>
    </div>

    <!-- Right Side - Recent Gold Receipts List -->
    <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 45%;">
        <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 px-4 py-2 rounded-t-lg">
            <h2 class="text-base font-semibold text-white flex items-center">
                <i class="fas fa-list mr-2"></i>
                Recent Gold Receipts
            </h2>
        </div>
        <div class="p-4">
            <div class="overflow-x-auto max-w-full">
                <table class="w-full text-sm responsive-table" style="table-layout: fixed; width: 100%; max-width: 100%;">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Receipt & Date</th>
                            <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Party</th>
                            <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Weight & Method</th>
                            <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 15%;">Value</th>
                            <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($receipts_result && $receipts_result->num_rows > 0): 
                        foreach ($receipts_result as $receipt): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="py-2 px-1">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($receipt['receipt_id']) ?></div>
                                    <div class="text-xs text-gray-500"><?= date('m/d/Y', strtotime($receipt['date_of_transaction'])) ?></div>
                                </td>
                                <td class="py-2 px-1">
                                    <div class="text-gray-900"><?= htmlspecialchars($receipt['party_name']) ?></div>
                                </td>
                                <td class="py-2 px-1">
                                    <div class="text-gray-900"><?= number_format($receipt['gold_weight'], 3) ?>g</div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($receipt['receipt_method']) ?></div>
                                </td>
                                <td class="py-2 px-1">
                                    <div class="text-gray-900">₹<?= number_format($receipt['gold_amount'], 0) ?></div>
                                </td>
                                <td class="py-2 px-1">
                                    <div class="flex space-x-1">
                                        <button class="text-blue-600 hover:text-blue-800" title="Print">
                                            <i class="fas fa-print text-xs"></i>
                                        </button>
                                        <button class="text-red-600 hover:text-red-800" title="Delete">
                                            <i class="fas fa-trash text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="py-8 text-center text-gray-500">
                                    <i class="fas fa-file-alt text-2xl mb-2"></i>
                                    <div>No gold receipts yet</div>
                                </td>
                            </tr>
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
$(document).ready(function() {
    // Initialize keyboard navigation for gold receipt form
    if (typeof KeyboardNavigationGeneric !== 'undefined') {
        KeyboardNavigationGeneric.init({
            formId: 'goldReceiptForm',
            fieldOrder: [
                'receiptId',            // 1. Receipt ID (readonly)
                'dateTime',             // 2. Date (readonly, skip)
                'partyNameInput',       // 3. Party Name
                'goldWeight',           // 4. Gold Weight
                'goldRate',             // 5. Rate
                'totalValue',           // 6. Total Value (readonly, skip)
                'receiptMethod',        // 7. Receipt Method
                'adjustBalance',        // 8. Adjust Balance (checkbox)
                'adjustmentAmount',     // 9. Adjustment Amount (conditional)
                'narration'             // 10. Narration
            ],
            skipFields: ['dateTime', 'totalValue'],
            submitButtonId: '', // Will find submit button in form automatically
            formName: 'gold_receipt'
        });
        window.KeyboardNavigation = KeyboardNavigationGeneric; // Make globally available
    }

    // Auto-calculate total value
    function calculateTotal() {
        const weight = parseFloat($('#goldWeight').val()) || 0;
        const rate = parseFloat($('#goldRate').val()) || 0;
        const total = weight * rate;
        if (total > 0) {
            $('#totalValue').val('₹' + total.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        } else {
            $('#totalValue').val('');
        }
    }
    
    $('#goldWeight, #goldRate').on('input change keyup', function() {
        calculateTotal();
    });
    
    // Also calculate on page load if values exist
    calculateTotal();

    // Show/hide adjustment field
    $('#adjustBalance').change(function() {
        if ($(this).is(':checked')) {
            $('#adjustmentField').removeClass('hidden');
            // Auto-fill with total gold value
            const totalValue = $('#totalValue').val().replace(/[₹,]/g, '');
            $('#adjustmentAmount').val(totalValue || '');
            $('#adjustmentAmount').focus();
        } else {
            $('#adjustmentField').addClass('hidden');
            $('#adjustmentAmount').val('');
        }
    });
    
    // Update adjustment amount when total value changes
    $('#goldWeight, #goldRate').on('input change keyup', function() {
        if ($('#adjustBalance').is(':checked')) {
            const totalValue = $('#totalValue').val().replace(/[₹,]/g, '');
            $('#adjustmentAmount').val(totalValue || '');
        }
    });

    // Party search
    let partyListVisible = false;
    let currentPartyIndex = -1;
    let selectedPartyName = '';
    
    // Function to update party selection status
    function updatePartySelectionStatus(isSelected) {
        if (isSelected) {
            $('#partyNameInput').addClass('border-green-500');
        } else {
            $('#partyNameInput').removeClass('border-green-500');
        }
    }
    
    $('#partyNameInput').on('input', function() {
        const term = $(this).val();
        
        // Reset selection if user clears or modifies the selected party name
        if (term !== selectedPartyName) {
            selectedPartyName = '';
            $('#partyId').val('');
            updatePartySelectionStatus(false);
            $('#selectedPartyDisplay').addClass('hidden');
        }
        
        if (term.length >= 1) {
            $.post('', {
                action: 'search_parties',
                term: term
            }, function(parties) {
                const partyList = $('#partyList');
                partyList.empty();
                currentPartyIndex = -1; // Reset index when new results load
                
                if (parties && parties.length > 0) {
                    parties.forEach((party, index) => {
                        const partyItem = document.createElement('div');
                        partyItem.className = 'px-2 py-1.5 hover:bg-yellow-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors duration-150 party-option';
                        partyItem.setAttribute('data-index', index);
                        partyItem.setAttribute('data-id', party.id || '');
                        partyItem.setAttribute('data-name', party.party_name || '');
                        
                        partyItem.innerHTML = `
                            <div class="flex items-center">
                                <div class="w-6 h-6 bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-full flex items-center justify-center text-white text-xs font-semibold mr-2 shadow-sm">
                                    ${(party.party_name || 'U').charAt(0).toUpperCase()}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 truncate">${party.party_name || 'Unknown Party'}</div>
                                    ${party.address ? `<div class="text-xs text-gray-500 truncate">${party.address}</div>` : ''}
                                </div>
                                <div class="text-xs text-gray-400 ml-2">
                                    <i class="fas fa-chevron-right"></i>
                                </div>
                            </div>
                        `;
                        
                        // Add click handler
                        partyItem.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const id = partyItem.getAttribute('data-id');
                            const name = partyItem.getAttribute('data-name');
                            selectParty(id, name);
                        });
                        
                        partyList[0].appendChild(partyItem);
                    });
                    partyList.removeClass('hidden');
                    partyListVisible = true;
                    currentPartyIndex = -1;
                } else {
                    partyList.html('<div class="px-3 py-2 text-gray-500 text-sm text-center">No parties found</div>').removeClass('hidden');
                    partyListVisible = false;
                }
            }, 'json').fail(function(xhr, status, error) {
                console.error('Party search error:', error);
                $('#partyList').html('<div class="px-3 py-2 text-red-500 text-sm text-center">Error searching parties</div>').removeClass('hidden');
            });
        } else {
            $('#partyList').addClass('hidden');
            partyListVisible = false;
            // Reset when input is completely cleared
            selectedPartyName = '';
            $('#partyId').val('');
            updatePartySelectionStatus(false);
            $('#selectedPartyDisplay').addClass('hidden');
        }
    });

    // Keyboard navigation for party list
    $('#partyNameInput').on('keydown', function(e) {
        const partyItems = document.querySelectorAll('#partyList .party-option');
        
        if (e.key === 'ArrowDown' && partyListVisible && partyItems.length > 0) {
            e.preventDefault();
            e.stopPropagation();
            if (currentPartyIndex < 0) {
                currentPartyIndex = 0; // Start from first item
            } else {
                currentPartyIndex = Math.min(currentPartyIndex + 1, partyItems.length - 1);
            }
            updatePartyHighlight();
        } else if (e.key === 'ArrowUp' && partyListVisible && partyItems.length > 0) {
            e.preventDefault();
            e.stopPropagation();
            if (currentPartyIndex <= 0) {
                currentPartyIndex = -1; // Deselect all
            } else {
                currentPartyIndex = Math.max(currentPartyIndex - 1, 0);
            }
            updatePartyHighlight();
        } else if (e.key === 'Enter' && partyListVisible && currentPartyIndex >= 0) {
            e.preventDefault();
            e.stopPropagation();
            const selectedItem = partyItems[currentPartyIndex];
            if (selectedItem) {
                selectedItem.click();
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            $('#partyList').addClass('hidden');
            partyListVisible = false;
            currentPartyIndex = -1;
        }
    });
    
    // Function to update party selection highlighting
    function updatePartyHighlight() {
        const partyItems = document.querySelectorAll('#partyList .party-option');
        
        partyItems.forEach((item, index) => {
            if (index === currentPartyIndex && currentPartyIndex >= 0) {
                item.classList.add('bg-yellow-100', 'border-l-4', 'border-yellow-500');
                item.classList.remove('hover:bg-yellow-50');
            } else {
                item.classList.remove('bg-yellow-100', 'border-l-4', 'border-yellow-500');
                item.classList.add('hover:bg-yellow-50');
            }
        });
        
        // Scroll into view
        if (currentPartyIndex >= 0 && currentPartyIndex < partyItems.length) {
            const currentItem = partyItems[currentPartyIndex];
            currentItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    // Party selection
    function selectParty(id, name) {
        $('#partyId').val(id);
        $('#partyNameInput').val(name);
        selectedPartyName = name;
        $('#partyList').addClass('hidden');
        partyListVisible = false;
        currentPartyIndex = -1;
        
        // Show selected party indicator
        $('#selectedPartyName').text(name);
        $('#selectedPartyDisplay').removeClass('hidden');
        updatePartySelectionStatus(true);
        
        // Clear validation errors
        $('#partyNameInput-error').addClass('hidden');
        $('#partyNameInput').removeClass('border-red-500');
        
        if (window.KeyboardNavigation) {
            window.KeyboardNavigation.clearValidationError('partyNameInput');
        }
        
        // Move to next field
        setTimeout(() => {
            const goldWeight = document.getElementById('goldWeight');
            if (goldWeight) {
                goldWeight.focus();
                goldWeight.select();
            }
        }, 100);
    }
    

    // Hide party list when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#partyNameInput, #partyList').length) {
            $('#partyList').addClass('hidden');
            partyListVisible = false;
            currentPartyIndex = -1;
        }
    });
    
    // Prevent party list from closing when clicking inside it
    $(document).on('click', '#partyList', function(e) {
        e.stopPropagation();
    });

    // Form submission
    $('#goldReceiptForm').on('submit', function(e) {
        e.preventDefault();
        
        // Validate party is selected
        if (!$('#partyId').val() || $('#partyId').val() === '') {
            $('#partyNameInput').addClass('border-red-500');
            $('#partyNameInput-error').text('Please select a party').removeClass('hidden');
            $('#partyNameInput').focus();
            $('#partyNameInput').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return false;
        } else {
            $('#partyNameInput').removeClass('border-red-500');
            $('#partyNameInput-error').addClass('hidden');
        }
        
        // Validate using keyboard navigation if available
        if (window.KeyboardNavigation && window.KeyboardNavigation.validateAllFields) {
            if (!window.KeyboardNavigation.validateAllFields()) {
                const firstInvalid = window.KeyboardNavigation.getFirstInvalidField();
                if (firstInvalid) {
                    firstInvalid.focus();
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                return false;
            }
        }
        
        // Prepare form data
        const formData = new FormData(this);
        formData.append('action', 'save_gold_receipt');
        
        // Convert FormData to URL-encoded string for jQuery.post
        const formDataObj = {};
        formData.forEach((value, key) => {
            formDataObj[key] = value;
        });

        $.post('', formDataObj, function(response) {
            if (response.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message
                });
            }
        }, 'json').fail(function(xhr, status, error) {
            console.error('Form submission error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to submit form. Please check console for details.'
            });
        });
    });

    // Reset form
    $('#resetFormBtn').on('click', function() {
        $('#goldReceiptForm')[0].reset();
        $('#partyId').val('');
        $('#totalValue').val('');
        $('#adjustmentField').addClass('hidden');
        $('#adjustBalance').prop('checked', false);
        $('#selectedPartyDisplay').addClass('hidden');
        $('#partyNameInput').removeClass('border-green-500 border-red-500');
        $('#partyList').addClass('hidden');
        $('#partyNameInput-error').addClass('hidden');
        partyListVisible = false;
        currentPartyIndex = -1;
    });
});
</script>

<?php
// Capture the content
$content = ob_get_clean();

// Include the layout
include 'components/layout.php';
?>

<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'u176143338_jewellery_baza');
define('DB_USER', 'u176143338_jewellery_baza');
define('DB_PASS', 'Suniprosen2511@#');
// Database Connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
// Fetch Financial and Gold Statistics
$query = $pdo->query("
    SELECT 
        -- Financial Data
        SUM(amount) AS total_transaction_amount,
        SUM(CASE WHEN amount_type = 'cash_in' THEN payment_amount ELSE 0 END) AS total_Sale_amount,
         SUM(CASE WHEN amount_type = 'cash_in' AND payment_status = 'Paid' or Particial THEN payment_amount ELSE 0 END) AS total_received_amount,
        SUM(CASE WHEN amount_type = 'cash_out' THEN payment_amount ELSE 0 END) AS total_paid,
        SUM(CASE WHEN amount_type = 'cash_in' AND payment_status = 'due' THEN due_amount ELSE 0 END) AS total_user_due,
        SUM(CASE WHEN amount_type = 'cash_out' AND payment_status = 'due' THEN due_amount ELSE 0 END) AS total_liability,

        -- Payment Breakdown
        SUM(CASE WHEN payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS cash_payments,
        SUM(CASE WHEN payment_method = 'Bank' THEN payment_amount ELSE 0 END) AS bank_payments,
        SUM(CASE WHEN payment_method = 'UPI' THEN payment_amount ELSE 0 END) AS upi_payments,
        SUM(CASE WHEN payment_method = 'Card' THEN payment_amount ELSE 0 END) AS card_payments,

        -- Gold Statistics
        SUM(CASE WHEN transaction_type = 'Booking' THEN booking_weight ELSE 0 END) AS booking_gold_weight,
        SUM(CASE WHEN transaction_type = 'Refine' THEN received_weight ELSE 0 END) AS refine_gold_weight,
        SUM(CASE WHEN transaction_type = 'Purchase' THEN received_weight ELSE 0 END) AS purchase_gold_weight,
        SUM(CASE WHEN transaction_type IN ('Refine', 'Booking', 'Sale') THEN delivered_weight ELSE 0 END) AS total_delivered_gold,
        SUM(CASE WHEN transaction_type = 'Refine' THEN fine_weight ELSE 0 END) AS total_refine_fine_weight
    FROM book_gold_transactions
");
$stats = $query->fetch(PDO::FETCH_ASSOC);
?> 
<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$page_title = "Dashboard";
include 'components/layout.php';
?>
        <!-- Header Section -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Financial Dashboard</h1>
            <p class="text-gray-600">Overview of transactions and gold statistics</p>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Transactions Card -->
            <div class="bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Total Transactions</p>
                            <p class="text-2xl font-semibold text-gray-800">₹<?php echo number_format($stats['total_transaction_amount']); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Received Card -->
            <div class="bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Total Received</p>
                            <p class="text-2xl font-semibold text-gray-800">₹<?php echo number_format($stats['total_received']); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gold Weight Card -->
            <div class="bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Total Gold Weight</p>
                            <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['booking_gold_weight'], 2); ?> g</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Due Amount Card -->
            <div class="bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Total Due</p>
                            <p class="text-2xl font-semibold text-gray-800">₹<?php echo number_format($stats['total_user_due']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Payment Methods Chart -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4">Payment Methods Distribution</h2>
                <canvas id="paymentMethodsChart" class="h-64"></canvas>
            </div>

            <!-- Gold Statistics Chart -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4">Gold Weight Distribution</h2>
                <canvas id="goldWeightChart" class="h-64"></canvas>
            </div>
        </div>

        <!-- Detailed Stats Table -->
        <div class="bg-white rounded-lg shadow-sm">
            <div class="p-6">
                <h2 class="text-xl font-semibold mb-4">Detailed Statistics</h2>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Category</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-t border-gray-200 hover:bg-gray-50">
                                <td class="px-4 py-3">Total Liability</td>
                                <td class="px-4 py-3 text-right">₹<?php echo number_format($stats['total_liability']); ?></td>
                            </tr>
                            <tr class="border-t border-gray-200 hover:bg-gray-50">
                                <td class="px-4 py-3">Refine Gold Weight</td>
                                <td class="px-4 py-3 text-right"><?php echo number_format($stats['refine_gold_weight'], 2); ?> g</td>
                            </tr>
                            <tr class="border-t border-gray-200 hover:bg-gray-50">
                                <td class="px-4 py-3">Total Delivered Gold</td>
                                <td class="px-4 py-3 text-right"><?php echo number_format($stats['total_delivered_gold'], 2); ?> g</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- Chart.js CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
    
    <script>
        // Payment Methods Chart
        const paymentMethodsCtx = document.getElementById('paymentMethodsChart').getContext('2d');
        new Chart(paymentMethodsCtx, {
            type: 'doughnut',
            data: {
                labels: ['Cash', 'Bank', 'UPI', 'Card'],
                datasets: [{
                    data: [
                        <?php echo $stats['cash_payments']; ?>,
                        <?php echo $stats['bank_payments']; ?>,
                        <?php echo $stats['upi_payments']; ?>,
                        <?php echo $stats['card_payments']; ?>
                    ],
                    backgroundColor: [
                        '#10B981',
                        '#3B82F6',
                        '#F59E0B',
                        '#EF4444'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });

        // Gold Weight Chart
        const goldWeightCtx = document.getElementById('goldWeightChart').getContext('2d');
        new Chart(goldWeightCtx, {
            type: 'bar',
            data: {
                labels: ['Booking', 'Refine', 'Purchase', 'Delivered'],
                datasets: [{
                    label: 'Gold Weight (g)',
                    data: [
                        <?php echo $stats['booking_gold_weight']; ?>,
                        <?php echo $stats['refine_gold_weight']; ?>,
                        <?php echo $stats['purchase_gold_weight']; ?>,
                        <?php echo $stats['total_delivered_gold']; ?>
                    ],
                    backgroundColor: '#F59E0B',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: '#E2E8F0'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    </script>
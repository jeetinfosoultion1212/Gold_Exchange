<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'u176143338_jewellery_baza');
define('DB_USER', 'u176143338_jewellery_baza');
define('DB_PASS', 'Suniprosen2511@#');

class GoldReportManager {
    private $conn;

    public function __construct() {
        try {
            $this->conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public function getOverallStatistics($startDate = null, $endDate = null) {
        $startDate = $startDate ?? date('Y-m-d');
        $endDate = $endDate ?? date('Y-m-d');
        
        $stats = [];

        // Comprehensive transaction analysis
        $query = "SELECT 
            -- Basic transaction counts
            COUNT(*) as total_transactions,
            
            -- Gold weight calculations by transaction type
            SUM(CASE WHEN transaction_type = 'Booking' THEN booking_weight ELSE 0 END) as booking_gold_weight,
            SUM(CASE WHEN transaction_type = 'Refine' THEN received_weight ELSE 0 END) as refine_gold_weight,
            SUM(CASE WHEN transaction_type = 'Purchase' THEN received_weight ELSE 0 END) as purchase_gold_weight,
            
            -- Total delivered gold across all types
            SUM(CASE 
                WHEN transaction_type IN ('Refine', 'Booking', 'Sale') 
                THEN delivered_weight 
                ELSE 0 
            END) as total_delivered_gold,
            
            -- Fine weight for Refine transactions
            SUM(CASE WHEN transaction_type = 'Refine' THEN fine_weight ELSE 0 END) as total_refine_fine_weight,
            
            -- Financial calculations
            SUM(amount) as total_amount,
            SUM(payment_amount) as total_paid_amount,
            SUM(CASE WHEN amount_type = 'cash_in' THEN amount ELSE 0 END) AS sale,
            SUM(CASE WHEN amount_type = 'cash_out' THEN amount ELSE 0 END) AS total_expenses,
            SUM(CASE WHEN amount_type = 'cash_out' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END) as total_paid,
            SUM(CASE WHEN amount_type = 'cash_in' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END) as total_Recived,
            
            -- Payment method analysis for cash in hand
            SUM(CASE WHEN payment_method = 'Cash' AND amount_type = 'cash_in' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END) - 
           (SUM(CASE WHEN payment_method = 'Cash' AND amount_type = 'cash_out' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END)) as net_cash_balance,
           
    SUM(CASE WHEN payment_method IN ('Bank Transfer ', 'UPI') AND amount_type = 'cash_in' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END) - 
    SUM(CASE WHEN payment_method IN ('Bank Transfer', 'UPI') AND amount_type = 'cash_out' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END) as net_bank_upi_balance,
    (SUM(CASE WHEN payment_method = 'Cash' AND amount_type = 'cash_in' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END) - 
     SUM(CASE WHEN payment_method = 'Cash' AND amount_type = 'cash_out' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END)) + 
    (SUM(CASE WHEN payment_method IN ('Bank Transfer', 'UPI') AND amount_type = 'cash_in' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END) - 
     SUM(CASE WHEN payment_method IN ('Bank Transfer', 'UPI') AND amount_type = 'cash_out' AND payment_status IN ('Paid', 'Partial') THEN payment_amount ELSE 0 END)) as total_available_funds,
     
     
 SUM(CASE WHEN payment_status = 'Partial' THEN (amount - payment_amount) WHEN payment_status = 'Due' THEN amount ELSE 0 END) AS total_due_amount,

           
    
            SUM(CASE WHEN payment_method != 'Cash' THEN payment_amount ELSE 0 END) as other_payments,
            
            -- Transaction type counts
            SUM(CASE WHEN transaction_type = 'Refine' THEN 1 ELSE 0 END) as refine_count,
            SUM(CASE WHEN transaction_type = 'Purchase' THEN 1 ELSE 0 END) as purchase_count,
            SUM(CASE WHEN transaction_type = 'Sale' THEN 1 ELSE 0 END) as sale_count,
            SUM(CASE WHEN transaction_type = 'Booking' THEN 1 ELSE 0 END) as booking_count,
             SUM(CASE WHEN delivery_status = 'Pending' THEN 1 ELSE 0 END) as pending_delivery_count
            
        FROM book_gold_transactions
        WHERE DATE(date_of_transaction) BETWEEN :start_date AND :end_date";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();
        $stats['transactions'] = $stmt->fetch(PDO::FETCH_ASSOC);
        $pendingDeliveries= $stats['pending_delivery_count'] ?? 0;
        // Payment Status Analysis
        $query = "SELECT 
            payment_status, 
            COUNT(*) as count,
            SUM(amount) as total_amount
        FROM book_gold_transactions 
        WHERE DATE(date_of_transaction) BETWEEN :start_date AND :end_date
        GROUP BY payment_status";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();
        $stats['payment_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $query = "SELECT 
            payment_status, 
            COUNT(*) as count,
            SUM(amount) as due_amount
          FROM book_gold_transactions 
          WHERE payment_status = 'Due'
          AND DATE(date_of_transaction) BETWEEN :start_date AND :end_date
          GROUP BY payment_status";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();
        $stats['payment_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // Stock Overview
        $query = "SELECT 
            stock_name, 
            remain_stock, 
            purity, 
            last_updated
        FROM book_gold_stock
        ORDER BY remain_stock DESC";
        $stmt = $this->conn->query($query);
        $stats['stock'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Party Balance Summary
        $query = "SELECT 
            COUNT(*) as total_parties,
            MAX(contact_no) as max_contact,
            MIN(contact_no) as min_contact
        FROM party_balances";
        $stmt = $this->conn->query($query);
        $stats['party_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

        return $stats;
    }

    public function getRecentTransactions($startDate = null, $endDate = null, $limit = 10) {
        $startDate = $startDate ?? date('Y-m-d');
        $endDate = $endDate ?? date('Y-m-d');
        
        $query = "SELECT 
    * FROM 
    book_gold_transactions
    WHERE DATE(date_of_transaction) BETWEEN :start_date AND :end_date
    ORDER BY 
    date_of_transaction DESC
    LIMIT :limit";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTopParties($limit = 5) {
        $query = "SELECT 
            party_name, 
            COUNT(*) as transaction_count,
            SUM(amount) as total_transaction_value
        FROM book_gold_transactions
        GROUP BY party_name
        ORDER BY total_transaction_value DESC
        LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStockStatistics() {
        $query = "SELECT 
            s.*,
            (issue_stock - remain_stock) as used_stock,
            (remain_stock / issue_stock * 100) as stock_percentage,
            TIMESTAMPDIFF(HOUR, last_updated, NOW()) as hours_since_update
        FROM book_gold_stock s
        ORDER BY remain_stock DESC";
        
        $stmt = $this->conn->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Add this method to your GoldReportManager class
    public function updateStockLevel($stockId, $addedStock) {
        try {
            $this->conn->beginTransaction();

            // Get current stock details
            $query = "SELECT stock_name, remain_stock, purity FROM book_gold_stock WHERE id = :stock_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':stock_id', $stockId, PDO::PARAM_INT);
            $stmt->execute();
            $currentStock = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentStock) {
                throw new Exception("Stock not found");
            }

            // Update stock level
            $query = "UPDATE book_gold_stock 
                     SET remain_stock = remain_stock + :added_stock,
                         last_updated = NOW()
                     WHERE id = :stock_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':added_stock', $addedStock, PDO::PARAM_STR);
            $stmt->bindParam(':stock_id', $stockId, PDO::PARAM_INT);
            $stmt->execute();

            // Log the inventory change
            $query = "INSERT INTO inventory_stock_log 
                     (stock_id, stock_name, action_type, quantity, 
                      previous_stock, new_stock, purity, 
                      transaction_date, user_id, notes)
                     VALUES 
                     (:stock_id, :stock_name, 'ADD', :quantity,
                      :previous_stock, :new_stock, :purity,
                      NOW(), :user_id, :notes)";

            $newStock = $currentStock['remain_stock'] + $addedStock;
            $userId = $_SESSION['user_id'] ?? 1; // Replace with actual user ID if available
            $notes = "Manual stock addition";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':stock_id', $stockId, PDO::PARAM_INT);
            $stmt->bindParam(':stock_name', $currentStock['stock_name'], PDO::PARAM_STR);
            $stmt->bindParam(':quantity', $addedStock, PDO::PARAM_STR);
            $stmt->bindParam(':previous_stock', $currentStock['remain_stock'], PDO::PARAM_STR);
            $stmt->bindParam(':new_stock', $newStock, PDO::PARAM_STR);
            $stmt->bindParam(':purity', $currentStock['purity'], PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
            $stmt->execute();

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Stock update failed: " . $e->getMessage());
            return false;
        }
    }
}

// Get date range from URL parameters or use current date
$startDate = $_GET['startDate'] ?? date('Y-m-d');
$endDate = $_GET['endDate'] ?? date('Y-m-d');

// Initialize Report Manager with date range
$reportManager = new GoldReportManager();
$overallStats = $reportManager->getOverallStatistics($startDate, $endDate);
$recentTransactions = $reportManager->getRecentTransactions($startDate, $endDate);
$topParties = $reportManager->getTopParties();
$stockStats = $reportManager->getStockStatistics();

function getTypeClass($type) {
    return match($type) {
        'Refine' => 'warning',
        'Booking' => 'primary',
        'Sale' => 'success',
        'Purchase' => 'info',
        default => 'secondary'
    };
}

function getTypeIcon($type) {
    return match($type) {
        'Refine' => '<i class="fas fa-recycle"></i>',
        'Booking' => '<i class="fas fa-calendar-check"></i>',
        'Sale' => '<i class="fas fa-shopping-cart"></i>',
        'Purchase' => '<i class="fas fa-shopping-basket"></i>',
        default => '<i class="fas fa-exchange-alt"></i>'
    };
}

function getStockTypeClass($type) {
    return match($type) {
        'Gold Bar' => 'warning',
        'Gold Coin' => 'info',
        'Gold Ornament' => 'success',
        'fine gold' => 'primary',
        default => 'secondary'
    };
}

function getStockTypeIcon($type) {
    return match($type) {
        'Gold Bar' => 'cube',
        'Gold Coin' => 'coins',
        'Gold Ornament' => 'gem',
        'fine gold' => 'flask',
        default => 'box'
    };
}

function getProgressColor($percentage) {
    if ($percentage > 70) return 'success';
    if ($percentage > 30) return 'warning';
    return 'danger';
}

// Add this near the top of the file with other POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    try {
        if ($_POST['action'] === 'updateStock') {
            $stockId = intval($_POST['stockId']);
            $addedStock = floatval($_POST['addedStock']);
            
            // Validate input
            if ($stockId <= 0 || $addedStock <= 0) {
                throw new Exception("Invalid input parameters");
            }
            
            $reportManager = new GoldReportManager();
            $success = $reportManager->updateStockLevel($stockId, $addedStock);
            
            if ($success) {
                $response['success'] = true;
                $response['message'] = 'Stock updated successfully';
            } else {
                throw new Exception("Failed to update stock");
            }
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

?>


 <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Gold Refinery Management</title>
        
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.18/dist/sweetalert2.min.css">
     
        
 </head>
 <link rel="stylesheet" href="css/reports.css">
    <body>
        <div class="app-container">
            
    </div>
</div>
<!-- Date Range Filter -->
<div class="date-range-filter">
    <form id="dateRangeForm" class="row g-2 align-items-center">
        <div class="col-auto">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                <input type="date" class="form-control" id="startDate" name="startDate" 
                       value="<?= isset($_GET['startDate']) ? $_GET['startDate'] : date('Y-m-d') ?>">
            </div>
        </div>
        <div class="col-auto">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                <input type="date" class="form-control" id="endDate" name="endDate" 
                       value="<?= isset($_GET['endDate']) ? $_GET['endDate'] : date('Y-m-d') ?>">
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-filter me-1"></i>Apply
            </button>
        </div>
    </form>
    <div class="quick-filters">
        <button class="quick-filter-btn" data-days="7">Last 7 days</button>
        <button class="quick-filter-btn" data-days="30">Last 30 days</button>
        <button class="quick-filter-btn" data-days="90">Last 3 months</button>
        <button class="quick-filter-btn" data-period="month">This Month</button>
        <button class="quick-filter-btn" data-period="year">This Year</button>
    </div>
</div>
            
              <div class="stats-container">
            <!-- Gold Weight Statistics -->
            <div class="stat-card bg-warning-soft">
                <div class="stat-icon text-warning">
                    <i class="fas fa-recycle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Recived Weight(REFINE)</div>
                    <div class="stat-value"><?= number_format($overallStats['transactions']['refine_gold_weight'], 2) ?> gm</div>
                    <small class="text-muted"><?= $overallStats['transactions']['refine_count'] ?> Transactions</small>
                </div>
            </div>

            <div class="stat-card bg-info-soft">
                <div class="stat-icon text-info">
                    <i class="fas fa-shopping-basket"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Purchase Weight</div>
                    <div class="stat-value"><?= number_format($overallStats['transactions']['purchase_gold_weight'], 2) ?> gm</div>
                    <small class="text-muted"><?= $overallStats['transactions']['purchase_count'] ?> Transactions</small>
                </div>
            </div>

            <div class="stat-card bg-success-soft">
                <div class="stat-icon text-success">
                    <i class="fas fa-weight"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Delivered Gold</div>
                    <div class="stat-value"><?= number_format($overallStats['transactions']['total_delivered_gold'], 2) ?> gm</div>
                    <small class="text-muted">All Transactions</small>
                </div>
            </div>

            <div class="stat-card bg-primary-soft">
                <div class="stat-icon text-primary">
                    <i class="fas fa-flask"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Fine Weight (Refine)</div>
                    <div class="stat-value"><?= number_format($overallStats['transactions']['total_refine_fine_weight'], 2) ?> gm</div>
                </div>
            </div>
             <div class="stat-card bg-primary-soft">
                <div class="stat-icon text-primary">
                    <i class="fas fa-flask"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Booking Weight</div>
                    <div class="stat-value"><?= number_format($overallStats['transactions']['booking_gold_weight'], 2) ?> gm</div>
                </div>
            </div>

           <!-- Financial Statistics -->

<div class="stat-card bg-success-soft">
    <div class="stat-icon text-success">
        <i class="fas fa-money-bill-wave"></i>
    </div>
    <div class="stat-content">
        <div class="stat-label">Total Sales Amount</div>
        <div class="stat-value">₹<?= number_format($overallStats['transactions']['sale'], 2) ?></div>
    </div>
</div>

<div class="stat-card bg-primary-soft">
    <div class="stat-icon text-primary">
        <i class="fas fa-wallet"></i>
    </div>
    <div class="stat-content">
        <div class="stat-label">Total Received Amount</div>
        <div class="stat-value">₹<?= number_format($overallStats['transactions']['total_Recived'], 2) ?></div>
    </div>
</div>

<div class="stat-card bg-danger-soft">
    <div class="stat-icon text-danger">
        <i class="fas fa-exclamation-circle"></i>
    </div>
    <div class="stat-content">
        <div class="stat-label">Total Due Amount</div>
        <div class="stat-value">₹<?= number_format($overallStats['transactions']['total_due_amount'], 2) ?></div>
    </div>
</div>

<div class="stat-card bg-warning-soft">
    <div class="stat-icon text-warning">
        <i class="fas fa-hand-holding-usd"></i>
    </div>
    <div class="stat-content">
        <div class="stat-label">Cash in Hand</div>
        <div class="stat-value">₹<?= number_format($overallStats['transactions']['net_cash_balance'], 2) ?></div>
    </div>
</div>

<div class="stat-card bg-info-soft">
    <div class="stat-icon text-info">
        <i class="fas fa-credit-card"></i>
    </div>
    <div class="stat-content">
        <div class="stat-label">Bank Balance</div>
        <div class="stat-value">₹<?= number_format($overallStats['transactions']['net_bank_upi_balance'], 2) ?></div>
    </div>
</div>

<div class="stat-card bg-secondary-soft">
    <div class="stat-icon text-secondary">
        <i class="fas fa-mobile-alt"></i>
    </div>
    <div class="stat-content">
        <div class="stat-label">Paid Amount</div>
        <div class="stat-value">₹<?= number_format($overallStats['transactions']['total_paid'], 2) ?></div>
    </div>
</div>

<div class="stat-card bg-dark-soft">
    <div class="stat-icon text-dark">
        <i class="fas fa-coins"></i>
    </div>
    <div class="stat-content">
        <div class="stat-label">Total Avilable Funds</div>
        <div class="stat-value">₹<?= number_format($overallStats['transactions']['total_available_funds'], 2) ?></div>
    </div>
</div>

            <!-- Transaction Count Summary -->
            <div class="stat-card bg-secondary-soft">
                <div class="stat-icon text-secondary">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Transaction Summary</div>
                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-warning">Refine: <?= $overallStats['transactions']['refine_count'] ?></small>
                        <small class="text-info">Purchase: <?= $overallStats['transactions']['purchase_count'] ?></small>
                        <small class="text-success">Sale: <?= $overallStats['transactions']['sale_count'] ?></small>
                        <small class="text-primary">Booking: <?= $overallStats['transactions']['booking_count'] ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="row g-4 mt-2">
            <!-- Recent Transactions -->
            <div class="col-lg-8">
                <!-- Add these statistics cards above the transaction list -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex gap-3 overflow-auto pb-2">
                            <div class="stat-pill bg-primary-gradient">
                                <i class="fas fa-chart-line"></i>
                                <span>Today's Transactions: <?= $todayStats['count'] ?></span>
                            </div>
                            <div class="stat-pill bg-success-gradient">
                                <i class="fas fa-arrow-trend-up"></i>
                                <span>Highest Transaction: ₹<?= number_format($highestTransaction, 0) ?></span>
                            </div>
                            <div class="stat-pill bg-warning-gradient">
                                <i class="fas fa-clock"></i>
                                <span>Pending Deliveries: <?= $pendingDeliveries ?></span>
                            </div>
                            <div class="stat-pill bg-info-gradient">
                                <i class="fas fa-users"></i>
                                <span>Active Parties: <?= $activeParties ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Transaction List with Compact Design -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <div class="d-flex justify-content-between align-items-center py-2">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-list-ul text-primary me-2"></i>
                                <h6 class="mb-0">Recent Transactions</h6>
                                <span class="badge bg-primary-soft text-primary ms-2"><?= count($recentTransactions) ?></span>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <div class="search-box">
                                    <input type="text" id="searchInput" class="form-control form-control-sm" 
                                        placeholder="Search transactions..." style="height: 32px;">
                                    <i class="fas fa-search search-icon"></i>
                                </div>
                                <button class="btn btn-sm btn-outline-primary" style="height: 32px;">
                                    <i class="fas fa-file-excel"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Add transaction type filters -->
                        <div class="transaction-filters mt-2">
                            <button class="btn btn-outline-secondary btn-sm active" data-filter="all">
                                All Types
                            </button>
                            <button class="btn btn-outline-warning btn-sm" data-filter="Refine">
                                <i class="fas fa-recycle me-1"></i>Refine
                            </button>
                            <button class="btn btn-outline-primary btn-sm" data-filter="Booking">
                                <i class="fas fa-calendar-check me-1"></i>Booking
                            </button>
                            <button class="btn btn-outline-success btn-sm" data-filter="Sale">
                                <i class="fas fa-shopping-cart me-1"></i>Sale
                            </button>
                            <button class="btn btn-outline-info btn-sm" data-filter="Purchase">
                                <i class="fas fa-shopping-basket me-1"></i>Purchase
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 compact-table">
                            <thead class="bg-light">
                                <tr>
                                    <th class="py-2" style="width: 50px;">S.No</th>
                                    <th class="py-2">Receipt/Party</th>
                                    <th class="py-2" style="width: 100px;">Date</th>
                                    <th class="py-2 text-end" style="width: 180px;">Weights</th>
                                    <th class="py-2 text-end" style="width: 120px;">Rate</th>
                                    <th class="py-2 text-end" style="width: 120px;">Amount</th>
                                    <th class="py-2 text-center" style="width: 100px;">Status</th>
                                    <th class="py-2 text-center" style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTransactions as $index => $t): ?>
                                <tr data-type="<?= $t['transaction_type'] ?>">
                                    <td class="py-2">
                                        <div class="serial-number"><?= $index + 1 ?></div>
                                    </td>
                                    <td class="py-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="transaction-icon-sm bg-<?= getTypeClass($t['transaction_type']) ?>-soft">
                                                <?= getTypeIcon($t['transaction_type']) ?>
                                            </div>
                                            <div>
                                                <div class="party-name text-truncate" style="max-width: 200px;">
                                                    <?= htmlspecialchars($t['party_name']) ?>
                                                </div>
                                                <small class="text-muted"><?= $t['receipt_id'] ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2">
                                        <div class="small">
                                            <?= date('d M Y', strtotime($t['date_of_transaction'])) ?>
                                            <div class="text-muted" style="font-size: 11px;">
                                                <?= date('h:i A', strtotime($t['date_of_transaction'])) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2 text-end">
                                        <div class="weights-compact">
                                            <?php if($t['received_weight'] > 0): ?>
                                                <div class="weight-line">
                                                    <small class="text-muted">Rcv:</small>
                                                    <span><?= number_format($t['received_weight'], 2) ?></span>
                                                    <small class="purity-badge"><?= $t['purity'] ?>%</small>
                                                </div>
                                            <?php endif; ?>
                                            <?php if($t['delivered_weight'] > 0): ?>
                                                <div class="weight-line text-danger">
                                                    <small class="text-muted">Del:</small>
                                                    <span><?= number_format($t['delivered_weight'], 2) ?></span>
                                                </div>
                                            <?php endif; ?>
                                             <?php if($t['booking_weight'] > 0): ?>
                                                <div class="weight-line text-warning">
                                                    <small class=" text-muted ">Booking:</small>
                                                    <span><?= number_format($t['booking_weight'], 2) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if($t['fine_weight'] > 0): ?>
                                                <div class="weight-line text-warning">
                                                    <small>Fine:</small>
                                                    <span><?= number_format($t['fine_weight'], 2) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-2 text-end">
                                        <div class="rate-value">
                                            ₹<?= number_format($t['rate'], 2) ?>/g
                                        </div>
                                    </td>
                                    <td class="py-2 text-end">
                                        <div class="amount-compact">
                                            <div>₹<?= number_format($t['amount']) ?></div>
                                            <?php if($t['due_amount'] > 0): ?>
                                                <small class="text-danger">Due: ₹<?= number_format($t['due_amount']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <td class="py-2 text-center">
                                        <span class="status-pill status-<?= strtolower($t['payment_status']) ?>">
                                            <?= $t['payment_status'] ?>
                                        </span>
                                    </td>
                                    <td class="py-2 text-center">
                                        <div class="actions-compact">
                                            <button class="btn btn-icon" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-icon" title="Print">
                                                <i class="fas fa-print"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar with Additional Info -->
            <div class="col-lg-4">
                <!-- Payment Status Analysis -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-chart-pie text-primary me-2"></i>Payment Analysis</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach($paymentStatusAnalysis as $status): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted"><?= $status['payment_status'] ?></span>
                                    <span class="fw-medium"><?= $status['count'] ?> transactions</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-<?= match($status['payment_status']) {
                                        'Paid' => 'success',
                                        'Partial' => 'warning',
                                        'Due' => 'danger',
                                        default => 'secondary'
                                    } ?>" style="width: <?= ($status['count'] / array_sum(array_column($paymentStatusAnalysis, 'count'))) * 100 ?>%"></div>
                                </div>
                                <small class="text-muted">₹<?= number_format($status['avg_amount'], 2) ?> avg</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="payment-chart-card">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-chart-pie text-primary me-2"></i>Payment Methods Distribution</h6>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary active" data-view="amount">Amount</button>
                <button class="btn btn-outline-secondary" data-view="count">Count</button>
            </div>
        </div>
        <div class="payment-chart-container">
            <canvas id="paymentMethodChart"></canvas>
        </div>
    </div>

                <!-- Top Parties -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-users text-primary me-2"></i>Top Parties</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach($topParties as $party): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($party['party_name']) ?></h6>
                                            <small class="text-muted">
                                                <?= $party['transaction_count'] ?> transactions
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-medium">₹<?= number_format($party['total_transaction_value']) ?></div>
                                            <small class="text-<?= $party['transaction_count'] > 5 ? 'success' : 'warning' ?>">
                                                <?= $party['transaction_count'] > 5 ? 'Active' : 'Occasional' ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Stock Statistics -->
                <div class="card mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-boxes text-primary me-2"></i>
                            <h5 class="mb-0">Gold Stock Overview</h5>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" style="height: 32px;">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="stock-grid">
                            <?php foreach($stockStats as $stock): ?>
                            <div class="stock-item" 
                                data-stock-id="<?= $stock['id'] ?>"
                                data-remain-stock="<?= $stock['remain_stock'] ?>"
                                data-purity="<?= $stock['purity'] ?>">
                                <div class="stock-icon-lg bg-<?= getStockTypeClass($stock['stock_name']) ?>-soft">
                                    <i class="fas fa-<?= getStockTypeIcon($stock['stock_name']) ?>"></i>
                                </div>
                                <div class="stock-details">
                                    <div class="stock-name"><?= htmlspecialchars($stock['stock_name']) ?></div>
                                    <div class="stock-metrics">
                                        <div class="metric">
                                            <small>Remaining</small>
                                            <span><?= number_format($stock['remain_stock'], 2) ?>g</span>
                                        </div>
                                        <div class="metric">
                                            <small>Purity</small>
                                            <span><?= number_format($stock['purity'], 2) ?>%</span>
                                        </div>
                                    </div>
                                    <div class="progress" style="height: 4px;">
                                        <div class="progress-bar bg-<?= getProgressColor($stock['stock_percentage']) ?>" 
                                             style="width: <?= $stock['stock_percentage'] ?>%">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Range Indicator -->
    <div class="mb-3">
        <small class="text-muted">
            Showing data for: 
            <strong>
                <?= date('d M Y', strtotime($startDate)) ?> 
                <?= $startDate !== $endDate ? ' to ' . date('d M Y', strtotime($endDate)) : '' ?>
            </strong>
            <?php if ($startDate !== date('Y-m-d') || $endDate !== date('Y-m-d')): ?>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="ms-2 text-primary">
                    <i class="fas fa-sync-alt"></i> Reset to today
                </a>
            <?php endif; ?>
        </small>
    </div>
<div class="modal fade" id="stockUpdateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header border-0 bg-light">
                <h5 class="modal-title">
                    <i class="fas fa-box-open text-primary me-2"></i>
                    Update Stock Level
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="stockUpdateForm">
                    <input type="hidden" id="stockId" name="stockId">
                    
                    <!-- Stock Info Section -->
                    <div class="stock-info-card mb-4 p-3 bg-light rounded-3">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stock-modal-icon me-3">
                                <i class="fas fa-cube fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h6 class="mb-1" id="stockName"></h6>
                                <span class="badge bg-primary-soft text-primary" id="stockPurity"></span>
                            </div>
                        </div>
                        
                        <div class="current-stock-display text-center p-3 bg-white rounded-3 mb-3">
                            <small class="text-muted d-block mb-1">Current Stock</small>
                            <h3 class="mb-0" id="currentStock"></h3>
                            <small class="text-muted">grams</small>
                        </div>
                    </div>

                    <!-- Add Stock Input -->
                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-plus-circle text-success me-2"></i>
                            Add Stock Amount
                        </label>
                        <div class="input-group">
                            <input type="number" 
                                   class="form-control form-control-lg" 
                                   id="addedStock" 
                                   name="addedStock" 
                                   step="0.01" 
                                   required 
                                   min="0.01"
                                   placeholder="Enter amount in grams">
                            <span class="input-group-text">grams</span>
                        </div>
                    </div>

                    <!-- Preview Section -->
                    <div class="new-total-preview p-3 bg-success-soft rounded-3 text-center" 
                         style="display: none;">
                        <small class="text-muted d-block mb-1">New Total After Update</small>
                        <h4 class="mb-0 text-success" id="newTotalPreview">0.00 grams</h4>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="updateStockBtn">
                    <i class="fas fa-check me-2"></i>Update Stock
                </button>
            </div>
        </div>
    </div>
</div>

    <!-- Add payment method chart after stats container -->
  
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.getElementById('dateRangeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            // Validate dates
            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }
            
            if (startDate > endDate) {
                alert('Start date cannot be later than end date');
                return;
            }
            
            // Redirect with date parameters
            window.location.href = `${window.location.pathname}?startDate=${startDate}&endDate=${endDate}`;
        });

        // Set default dates if not already set
        window.addEventListener('load', function() {
            if (!document.getElementById('startDate').value) {
                document.getElementById('startDate').value = new Date().toISOString().split('T')[0];
            }
            if (!document.getElementById('endDate').value) {
                document.getElementById('endDate').value = new Date().toISOString().split('T')[0];
            }
        });

        // Quick date filter functionality
        document.querySelectorAll('.quick-filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const days = this.dataset.days;
                const period = this.dataset.period;
                const endDate = new Date();
                let startDate = new Date();

                if (days) {
                    startDate.setDate(startDate.getDate() - parseInt(days));
                } else if (period === 'month') {
                    startDate.setDate(1);
                } else if (period === 'year') {
                    startDate = new Date(startDate.getFullYear(), 0, 1);
                }

                document.getElementById('startDate').value = startDate.toISOString().split('T')[0];
                document.getElementById('endDate').value = endDate.toISOString().split('T')[0];
                document.getElementById('dateRangeForm').submit();
            });
        });

        // Payment method chart
        const ctx = document.getElementById('paymentMethodChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Cash', 'Bank Transfer', 'UPI', 'Other'],
                datasets: [{
                    data: [
                        <?= $overallStats['transactions']['net_cash_balance'] ?>,
                        <?= $overallStats['transactions']['other_payments'] * 0.4 ?>,
                        <?= $overallStats['transactions']['other_payments'] * 0.5 ?>,
                        <?= $overallStats['transactions']['other_payments'] * 0.1 ?>
                    ],
                    backgroundColor: [
                        'rgba(52, 211, 153, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(99, 102, 241, 0.8)',
                        'rgba(156, 163, 175, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
// Stock update functionality
document.getElementById('updateStockBtn').addEventListener('click', async function() {
    const form = document.getElementById('stockUpdateForm');
    const modal = bootstrap.Modal.getInstance(document.getElementById('stockUpdateModal'));
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    try {
        const stockId = document.getElementById('stockId').value;
        const addedStock = parseFloat(document.getElementById('addedStock').value);
        const stockItem = document.querySelector(`.stock-item[data-stock-id="${stockId}"]`);
        
        const formData = new FormData();
        formData.append('action', 'updateStock');
        formData.append('stockId', stockId);
        formData.append('addedStock', addedStock);

        // Disable the update button and show loading state
        const updateBtn = document.getElementById('updateStockBtn');
        const originalBtnText = updateBtn.innerHTML;
        updateBtn.disabled = true;
        updateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        
        if (result.success) {
            // Close the modal
            modal.hide();
            
            // Update the UI with new stock value
            const currentStock = parseFloat(stockItem.dataset.remainStock);
            const newStock = currentStock + addedStock;
            
            // Update the data attribute
            stockItem.dataset.remainStock = newStock.toString();
            
            // Update the displayed stock value
            const stockValueElement = stockItem.querySelector('.metric span');
            stockValueElement.textContent = `${newStock.toFixed(2)}g`;
            
            // Update the progress bar if it exists
            const progressBar = stockItem.querySelector('.progress-bar');
            if (progressBar) {
                const newPercentage = (newStock / (newStock * 1.2)) * 100; // Adjust calculation as needed
                progressBar.style.width = `${newPercentage}%`;
            }

            // Show success message using SweetAlert2
            Swal.fire({
                icon: 'success',
                title: 'Stock Updated Successfully',
                text: `New stock level: ${newStock.toFixed(2)}g`,
                showConfirmButton: false,
                timer: 2000,
                position: 'top-end',
                toast: true
            });

            // Highlight the updated stock item
            stockItem.style.transition = 'background-color 0.5s';
            stockItem.style.backgroundColor = '#d4edda';
            setTimeout(() => {
                stockItem.style.backgroundColor = '';
            }, 2000);

        } else {
            throw new Error(result.message || 'Failed to update stock');
        }

    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'Failed to update stock. Please try again.',
            position: 'top-end',
            toast: true,
            timer: 3000,
            showConfirmButton: false
        });
    } finally {
        // Reset the update button
        const updateBtn = document.getElementById('updateStockBtn');
        updateBtn.disabled = false;
        updateBtn.innerHTML = '<i class="fas fa-check me-2"></i>Update Stock';
    }
});

// Add input validation for stock amount
document.getElementById('addedStock').addEventListener('input', function(e) {
    const currentStock = parseFloat(document.getElementById('currentStock').textContent);
    const addedStock = parseFloat(e.target.value) || 0;
    const newTotal = currentStock + addedStock;
    
    const previewElement = document.querySelector('.new-total-preview');
    const updateBtn = document.getElementById('updateStockBtn');
    
    if (addedStock > 0) {
        document.getElementById('newTotalPreview').textContent = 
            `${newTotal.toFixed(2)} grams`;
        previewElement.style.display = 'block';
        updateBtn.disabled = false;
    } else {
        previewElement.style.display = 'none';
        updateBtn.disabled = true;
    }
});

// Reset form when modal is closed
document.getElementById('stockUpdateModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('stockUpdateForm').reset();
    document.querySelector('.new-total-preview').style.display = 'none';
    const updateBtn = document.getElementById('updateStockBtn');
    updateBtn.disabled = false;
    updateBtn.innerHTML = '<i class="fas fa-check me-2"></i>Update Stock';
});
        // Transaction filtering
        document.querySelectorAll('.transaction-filters .btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Remove active class from all buttons
                document.querySelectorAll('.transaction-filters .btn').forEach(b => 
                    b.classList.remove('active'));
                
                // Add active class to clicked button
                this.classList.add('active');
                
                const filterValue = this.dataset.filter;
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    if (filterValue === 'all' || row.dataset.type === filterValue) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Update serial numbers for visible rows
                let visibleIndex = 1;
                rows.forEach(row => {
                    if (row.style.display !== 'none') {
                        row.querySelector('.serial-number').textContent = visibleIndex++;
                    }
                });
            });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            let visibleIndex = 1;
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                    row.querySelector('.serial-number').textContent = visibleIndex++;
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>

    <!-- Add this modal structure before the closing body tag -->
    <div class="modal fade" id="stockUpdateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-header border-0 bg-light">
                    <h5 class="modal-title">
                        <i class="fas fa-box-open text-primary me-2"></i>
                        Update Stock Level
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="stockUpdateForm">
                        <input type="hidden" id="stockId" name="stockId">
                        
                        <!-- Stock Info Section -->
                        <div class="stock-info-card mb-4 p-3 bg-light rounded-3">
                            <div class="d-flex align-items-center mb-3">
                                <div class="stock-modal-icon me-3">
                                    <i class="fas fa-cube fa-2x text-primary"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1" id="stockName"></h6>
                                    <span class="badge bg-primary-soft text-primary" id="stockPurity"></span>
                                </div>
                            </div>
                            
                            <div class="current-stock-display text-center p-3 bg-white rounded-3 mb-3">
                                <small class="text-muted d-block mb-1">Current Stock</small>
                                <h3 class="mb-0" id="currentStock"></h3>
                                <small class="text-muted">grams</small>
                            </div>
                        </div>

                        <!-- Add Stock Input -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-plus-circle text-success me-2"></i>
                                Add Stock Amount
                            </label>
                            <div class="input-group">
                                <input type="number" 
                                       class="form-control form-control-lg" 
                                       id="addedStock" 
                                       name="addedStock" 
                                       step="0.01" 
                                       required 
                                       min="0.01"
                                       placeholder="Enter amount in grams">
                                <span class="input-group-text">grams</span>
                            </div>
                        </div>

                        <!-- Preview Section -->
                        <div class="new-total-preview p-3 bg-success-soft rounded-3 text-center" 
                             style="display: none;">
                            <small class="text-muted d-block mb-1">New Total After Update</small>
                            <h4 class="mb-0 text-success" id="newTotalPreview">0.00 grams</h4>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary" id="updateStockBtn">
                        <i class="fas fa-check me-2"></i>Update Stock
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add these styles to your existing CSS -->
    <style>
    .stock-modal-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: rgba(var(--bs-primary-rgb), 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .stock-info-card {
        border: 1px solid rgba(0,0,0,0.05);
    }

    .current-stock-display {
        border: 1px dashed rgba(0,0,0,0.1);
    }

    .current-stock-display h3 {
        color: var(--bs-primary);
        font-weight: 600;
    }

    .new-total-preview {
        transition: all 0.3s ease;
    }

    .modal-content {
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }

    .form-control-lg {
        height: 48px;
        font-size: 1.1rem;
    }

    .form-control:focus {
        border-color: var(--bs-primary);
        box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
    }

    .bg-success-soft {
        background-color: rgba(25, 135, 84, 0.1);
    }

    .bg-primary-soft {
        background-color: rgba(var(--bs-primary-rgb), 0.1);
    }
    </style>

    <script>
    // Update the click handler for stock items
    document.querySelectorAll('.stock-item').forEach(item => {
        item.style.cursor = 'pointer';
        
        item.addEventListener('click', function() {
            const stockData = {
                id: this.dataset.stockId,
                name: this.querySelector('.stock-name').textContent,
                currentStock: parseFloat(this.dataset.remainStock),
                purity: parseFloat(this.dataset.purity)
            };
            
            // Populate modal with stock data
            document.getElementById('stockId').value = stockData.id;
            document.getElementById('stockName').textContent = stockData.name;
            document.getElementById('currentStock').textContent = stockData.currentStock.toFixed(2);
            document.getElementById('stockPurity').textContent = `${stockData.purity.toFixed(2)}% Purity`;
            document.getElementById('addedStock').value = '';
            
            // Reset preview
            document.querySelector('.new-total-preview').style.display = 'none';
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('stockUpdateModal'));
            modal.show();
        });
    });

    // ... rest of your existing JavaScript code ...
    </script>
 </body>
    </html>





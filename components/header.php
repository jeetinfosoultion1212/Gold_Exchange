<?php
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user and company info
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];
$user_name = $_SESSION['full_name'];

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Professional Dark Header -->
<header class="bg-gradient-to-r from-slate-900 via-blue-900 to-slate-900 shadow-xl border-b border-blue-800 sticky top-0 z-50 ml-16">
    <div class="max-w-full mx-auto px-2 md:px-4">
        <div class="flex justify-between items-center h-16">
            <!-- Left Side - Logo Only -->
            <div class="flex items-center space-x-3 flex-shrink-0">
                <!-- Logo -->
                <div class="flex items-center space-x-2 md:space-x-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-yellow-400 to-yellow-600 rounded-lg flex items-center justify-center shadow-lg">
                        <i class="fas fa-gem text-white text-sm"></i>
                    </div>
                    <h1 class="text-lg md:text-xl font-bold text-white tracking-tight" style="font-family: 'Poppins', sans-serif;"><?= htmlspecialchars($company_name) ?></h1>
                </div>
            </div>

            <!-- Professional Navigation Menu -->
            <nav class="flex items-center space-x-1 md:space-x-2 overflow-x-auto scrollbar-hide">
                <style>
                nav a {
                    text-decoration: none !important;
                }
                nav a:hover {
                    text-decoration: none !important;
                }
                nav a:focus {
                    text-decoration: none !important;
                }
                nav a:visited {
                    text-decoration: none !important;
                }
                /* Hide scrollbar for Chrome, Safari and Opera */
                .scrollbar-hide::-webkit-scrollbar {
                    display: none;
                }
                /* Hide scrollbar for IE, Edge and Firefox */
                .scrollbar-hide {
                    -ms-overflow-style: none;  /* IE and Edge */
                    scrollbar-width: none;  /* Firefox */
                }
                </style>
                <a href="book.php" 
                   class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'book.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Book Gold (F1)">
                    <i class="fas fa-book w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">BOOK</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F1</kbd>
                </a>

                <a href="gold_exchange.php" 
                   class="bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'gold_exchange.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Gold Exchange (F12)">
                    <i class="fas fa-exchange-alt w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">EXCHANGE</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F12</kbd>
                </a>

                <a href="sell_gold.php" 
                   class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'sell_gold.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Sell Gold (F2)">
                    <i class="fas fa-shopping-cart w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">SELL</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F2</kbd>
                </a>

                <a href="purchase.php" 
                   class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'purchase.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Purchase Gold (F3)">
                    <i class="fas fa-shopping-basket w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">PURCHASE</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F3</kbd>
                </a>

                <a href="payment_receipt.php" 
                   class="bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'payment_receipt.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Receive Payment (F4)">
                    <i class="fas fa-money-bill-wave w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">RECEIVE</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F4</kbd>
                </a>

                <a href="gold_receipt.php" 
                   class="bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'gold_receipt.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Gold Receipt (F8)">
                    <i class="fas fa-coins w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">GOLD</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F8</kbd>
                </a>

                <a href="payment_send.php" 
                   class="bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'payment_send.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Send Payment (F11)">
                    <i class="fas fa-paper-plane w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">SEND</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F11</kbd>
                </a>

                <a href="party_ledger.php" 
                   class="bg-gradient-to-r from-pink-500 to-pink-600 hover:from-pink-600 hover:to-pink-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'party_ledger.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Party Ledger (F6)">
                    <i class="fas fa-users w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">LEDGER</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F6</kbd>
                </a>

                <a href="report.php" 
                   class="bg-gradient-to-r from-teal-500 to-teal-600 hover:from-teal-600 hover:to-teal-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'report.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Daily Report (F9)">
                    <i class="fas fa-chart-line w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">REPORT</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F9</kbd>
                </a>

                <a href="settings.php" 
                   class="bg-gradient-to-r from-slate-500 to-slate-600 hover:from-slate-600 hover:to-slate-700 text-white px-2 md:px-3 py-2 rounded-lg flex items-center space-x-1 md:space-x-2 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 <?= $current_page === 'settings.php' ? 'ring-2 ring-yellow-400 shadow-yellow-400/50' : '' ?>"
                   title="Settings (F7)">
                    <i class="fas fa-cog w-4 h-4"></i>
                    <span class="text-xs font-semibold tracking-wide hidden sm:inline">SETTINGS</span>
                    <kbd class="ml-1 px-1.5 py-0.5 bg-white/30 border border-white/50 rounded-md text-xs font-bold font-mono shadow-sm hidden lg:inline">F7</kbd>
                </a>
            </nav>

            <!-- Right Side - Professional User Profile -->
            <div class="flex items-center space-x-2 md:space-x-4">
                <!-- User Profile with Dropdown -->
                <div class="relative group">
                    <button id="userProfileBtn" class="flex items-center space-x-2 md:space-x-3 hover:opacity-80 transition-opacity cursor-pointer">
                    <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-yellow-400 to-yellow-600 rounded-full flex items-center justify-center shadow-lg">
                        <span class="text-white font-bold text-xs md:text-sm tracking-tight"><?= strtoupper(substr($user_name, 0, 2)) ?></span>
                    </div>
                        <div class="text-right hidden lg:block">
                        <p class="text-sm font-semibold text-white tracking-tight"><?= htmlspecialchars($user_name) ?></p>
                        <p class="text-xs text-gray-300 font-medium">Administrator</p>
                        </div>
                        <i class="fas fa-chevron-down text-white text-xs hidden lg:block"></i>
                    </button>
                    
                    <!-- Dropdown Menu -->
                    <div id="userDropdown" class="absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-200 py-2 hidden z-50">
                        <div class="px-4 py-3 border-b border-gray-200">
                            <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($user_name) ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($company_name) ?></p>
                        </div>
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-user mr-2 text-gray-400"></i>Profile
                        </a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-cog mr-2 text-gray-400"></i>Settings
                        </a>
                        <div class="border-t border-gray-200 my-1"></div>
                        <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors font-medium">
                            <i class="fas fa-sign-out-alt mr-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
// User Profile Dropdown Toggle
const userProfileBtn = document.getElementById('userProfileBtn');
const userDropdown = document.getElementById('userDropdown');

if (userProfileBtn && userDropdown) {
    // Toggle dropdown on click
    userProfileBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        userDropdown.classList.toggle('hidden');
    });
    
    // Close dropdown on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !userDropdown.classList.contains('hidden')) {
            userDropdown.classList.add('hidden');
        }
    });
}

// Close user dropdown when clicking outside
document.addEventListener('click', function(event) {
    // Close user dropdown when clicking outside
    if (userDropdown && userProfileBtn && !userProfileBtn.contains(event.target) && !userDropdown.contains(event.target)) {
        userDropdown.classList.add('hidden');
    }
});

// Keyboard shortcuts for navigation (F1-F12)
document.addEventListener('keydown', function(event) {
    // Check both event.key and event.code for function keys (browser compatibility)
    const key = event.key || event.code;
    
    // Handle function keys (F1-F12)
    if (key === 'F1' || event.code === 'F1') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'book.php';
        return;
    }
    if (key === 'F2' || event.code === 'F2') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'sell_gold.php';
        return;
    }
    if (key === 'F3' || event.code === 'F3') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'purchase.php';
        return;
    }
    if (key === 'F4' || event.code === 'F4') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'payment_receipt.php';
        return;
    }
    if (key === 'F6' || event.code === 'F6') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'party_ledger.php';
        return;
    }
    if (key === 'F7' || event.code === 'F7') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'settings.php';
        return;
    }
    if (key === 'F8' || event.code === 'F8') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'gold_receipt.php';
        return;
    }
    if (key === 'F9' || event.code === 'F9') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'report.php';
        return;
    }
    if (key === 'F11' || event.code === 'F11') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'payment_send.php';
        return;
    }
    if (key === 'F12' || event.code === 'F12') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'gold_exchange.php';
        return;
    }
}, true); // Use capture phase to catch events earlier
</script>

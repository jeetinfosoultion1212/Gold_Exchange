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

<!-- Professional Dark Header (Compact) -->
<header class="bg-gradient-to-r from-slate-900 via-blue-900 to-slate-900 shadow-xl border-b border-blue-800 sticky top-0 z-50 ml-16">
    <div class="max-w-full mx-auto px-2">
        <div class="flex justify-between items-center h-14"> <!-- Reduced height from 16 to 14 -->
            <!-- Left Side - Logo Only -->
            <div class="flex items-center space-x-2 flex-shrink-0 mr-2">
                <!-- Logo -->
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 bg-gradient-to-br from-yellow-400 to-yellow-600 rounded-lg flex items-center justify-center shadow-lg">
                        <i class="fas fa-gem text-white text-xs"></i>
                    </div>
                    <h1 class="text-base md:text-lg font-bold text-white tracking-tight hidden md:block" style="font-family: 'Poppins', sans-serif;"><?= htmlspecialchars($company_name) ?></h1>
                </div>
            </div>

            <!-- Professional Navigation Menu -->
            <nav class="flex items-center space-x-1 overflow-x-auto scrollbar-hide flex-grow justify-center">
                <style>
                nav a { text-decoration: none !important; }
                .scrollbar-hide::-webkit-scrollbar { display: none; }
                .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
                </style>
                
                <!-- Buttons with tighter padding and smaller text -->
                <?php
                $nav_items = [
                    ['book.php', 'blue', 'book', 'BOOK', 'F1'],
                    ['gold_exchange.php', 'orange', 'exchange-alt', 'EXCH', 'F12'],
                    ['sell_gold.php', 'green', 'shopping-cart', 'SELL', 'F2'],
                    ['purchase.php', 'purple', 'shopping-basket', 'PUR', 'F3'],
                    ['payment_receipt.php', 'green', 'money-bill-wave', 'RCV', 'F4'], // Shortened Text
                    ['gold_receipt.php', 'yellow', 'coins', 'GOLD', 'F8'],
                    ['payment_send.php', 'red', 'paper-plane', 'SEND', 'F11'],
                    ['party_ledger.php', 'pink', 'users', 'LEDGER', 'F6'],
                    ['report.php', 'teal', 'chart-line', 'RPT', 'F9'],
                    ['settings.php', 'slate', 'cog', 'SET', 'F7']
                ];

                foreach ($nav_items as $item) {
                    $link = $item[0];
                    $color = $item[1];
                    $icon = $item[2];
                    $label = $item[3];
                    $key = $item[4];
                    $isActive = $current_page === $link;
                    $activeClass = $isActive ? 'ring-1 ring-yellow-400 shadow-yellow-400/50' : '';
                    
                    // Specific color classes to avoid complex string concatenation
                    $bgClass = "from-{$color}-500 to-{$color}-600 hover:from-{$color}-600 hover:to-{$color}-700";
                    if ($color === 'slate') $bgClass = "from-slate-500 to-slate-600 hover:from-slate-600 hover:to-slate-700";
                    
                    echo "
                    <a href=\"$link\" 
                       class=\"bg-gradient-to-r $bgClass text-white px-1.5 py-1.5 rounded-md flex items-center space-x-1 transition-all duration-300 shadow hover:shadow-md transform hover:scale-105 $activeClass\"
                       title=\"$label ($key)\">
                        <i class=\"fas fa-$icon w-3 h-3\"></i>
                        <span class=\"text-[10px] md:text-xs font-bold tracking-wide hidden sm:inline\">$label</span>
                        <kbd class=\"ml-1 px-1 py-0.5 bg-white/30 border border-white/50 rounded-sm text-[9px] font-mono shadow-sm hidden xl:inline\">$key</kbd>
                    </a>";
                }
                ?>
            </nav>

            <!-- Right Side - Professional User Profile -->
            <div class="flex items-center space-x-2 ml-2 flex-shrink-0">
                <div class="relative group">
                    <button id="userProfileBtn" class="flex items-center space-x-2 hover:opacity-80 transition-opacity cursor-pointer">
                        <div class="w-8 h-8 bg-gradient-to-br from-yellow-400 to-yellow-600 rounded-full flex items-center justify-center shadow-lg">
                            <span class="text-white font-bold text-xs tracking-tight"><?= strtoupper(substr($user_name, 0, 2)) ?></span>
                        </div>
                        <div class="text-right hidden xl:block"> <!-- Only show name on XL screens -->
                            <p class="text-xs font-semibold text-white tracking-tight leading-tight"><?= htmlspecialchars(substr($user_name, 0, 10)) . (strlen($user_name)>10?'..':'') ?></p>
                            <p class="text-[9px] text-gray-300 font-medium">Admin</p>
                        </div>
                        <i class="fas fa-chevron-down text-white text-[10px] hidden lg:block"></i>
                    </button>
                    
                    <!-- Dropdown Menu -->
                     <div id="userDropdown" class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl border border-gray-200 py-2 hidden z-50">
                        <div class="px-4 py-2 border-b border-gray-200">
                            <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($user_name) ?></p>
                        </div>
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2 text-gray-400"></i>Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-cog mr-2 text-gray-400"></i>Settings</a>
                        <div class="border-t border-gray-200 my-1"></div>
                        <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
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

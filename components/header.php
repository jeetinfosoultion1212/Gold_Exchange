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

<!-- Professional Dark Header (Enhanced & Responsive) -->
<style>
    /* Sidebar is fixed w-16; main area must not exceed viewport (avoids horizontal page scroll + gap) */
    html {
        overflow-x: clip;
    }
    .main-with-sidebar {
        margin-left: 4rem;
        width: calc(100% - 4rem);
        max-width: calc(100vw - 4rem);
        min-width: 0;
        box-sizing: border-box;
    }
</style>
<header class="bg-gradient-to-r from-slate-900 via-blue-900 to-slate-900 shadow-2xl border-b-2 border-blue-700/50 sticky top-0 z-50 main-with-sidebar">
    <div class="max-w-full mx-auto px-2">
        <div class="flex justify-between items-center h-14"> <!-- Reduced height -->
            <!-- Left Side - Logo -->
            <div class="flex items-center space-x-2 flex-shrink-0 mr-1">
                <div class="flex items-center space-x-1.5">
                    <div class="w-8 h-8 bg-gradient-to-br from-yellow-400 via-yellow-500 to-yellow-600 rounded-lg flex items-center justify-center shadow-xl ring-2 ring-yellow-400/30">
                        <i class="fas fa-gem text-white text-[12px]"></i>
                    </div>
                    <h1 class="text-sm md:text-base font-bold text-white tracking-tight hidden lg:block" style="font-family: 'Poppins', sans-serif;"><?= htmlspecialchars($company_name) ?></h1>
                </div>
            </div>

            <!-- Professional Navigation Menu -->
            <nav class="flex items-center gap-1 overflow-x-auto scrollbar-hide flex-grow justify-center px-1">
                <style>
                nav a { text-decoration: none !important; }
                .scrollbar-hide::-webkit-scrollbar { display: none; }
                .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
                .nav-btn {
                    min-width: fit-content;
                    white-space: nowrap;
                    padding: 0.4rem 0.5rem;
                }
                @media (min-width: 1536px) {
                    .nav-btn { padding: 0.5rem 0.75rem; }
                }
                </style>
                
                <!-- Enhanced Navigation Buttons -->
                <?php
                $nav_items = [
                    ['book.php', 'blue', 'book', 'BOOK', 'F1'],
                    ['exchange.php', 'orange', 'exchange-alt', 'EXCH', 'F12'],
                    ['sales.php', 'green', 'shopping-cart', 'SALES', 'F2'],
                    ['purchase.php', 'purple', 'shopping-basket', 'PURCH', 'F3'],
                    ['payment_receipt.php', 'emerald', 'money-bill-wave', 'RECEIVE', 'F4'],
                    ['personal_expense.php', 'yellow', 'wallet', 'EXPENSE', 'F8'],
                    ['payment_send.php', 'red', 'paper-plane', 'SEND', 'F11'],
                    ['party_ledger.php', 'pink', 'users', 'LEDGER', 'F6'],
                    ['logo_marking.php', 'indigo', 'stamp', 'LOGO', 'F10'],
                    ['report.php', 'teal', 'chart-line', 'REPORT', 'F9'],
                    ['settings.php', 'slate', 'cog', 'SETTINGS', 'F7']
                ];

                foreach ($nav_items as $item) {
                    $link = $item[0];
                    $color = $item[1];
                    $icon = $item[2];
                    $label = $item[3];
                    $key = $item[4];
                    $isActive = $current_page === $link;
                    $activeClass = $isActive ? 'ring-2 ring-yellow-400 shadow-lg shadow-yellow-400/30 scale-105' : '';
                    
                    // Color-specific gradients with proper Tailwind classes
                    $colorMap = [
                        'blue' => 'from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700',
                        'orange' => 'from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700',
                        'green' => 'from-green-500 to-green-600 hover:from-green-600 hover:to-green-700',
                        'purple' => 'from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700',
                        'emerald' => 'from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700',
                        'yellow' => 'from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700',
                        'red' => 'from-red-500 to-red-600 hover:from-red-600 hover:to-red-700',
                        'pink' => 'from-pink-500 to-pink-600 hover:from-pink-600 hover:to-pink-700',
                        'teal' => 'from-teal-500 to-teal-600 hover:from-teal-600 hover:to-teal-700',
                        'indigo' => 'from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700',
                        'slate' => 'from-slate-500 to-slate-600 hover:from-slate-600 hover:to-slate-700'
                    ];
                    $bgClass = $colorMap[$color] ?? $colorMap['slate'];
                    
                    echo "
                    <a href=\"$link\" 
                       class=\"nav-btn bg-gradient-to-br $bgClass text-white rounded-lg flex items-center gap-1 transition-all duration-300 shadow-md hover:shadow-xl transform hover:scale-105 $activeClass\"
                       title=\"$label ($key)\">
                        <i class=\"fas fa-$icon text-[11px]\"></i>
                        <span class=\"text-[10.5px] font-bold tracking-tight hidden md:inline\">{$label}</span>
                        <kbd class=\"px-1 py-0.5 bg-white/20 border border-white/40 rounded text-[9px] font-mono shadow-sm hidden 2xl:inline\">{$key}</kbd>
                    </a>";
                }
                ?>
            </nav>

            <!-- Right Side - Professional User Profile -->
            <div class="flex items-center space-x-1.5 ml-1 flex-shrink-0">
                <div class="relative group">
                    <button id="userProfileBtn" class="flex items-center space-x-1.5 hover:opacity-90 transition-all duration-200 cursor-pointer">
                        <div class="w-8 h-8 bg-gradient-to-br from-yellow-400 via-yellow-500 to-yellow-600 rounded-full flex items-center justify-center shadow-xl ring-2 ring-yellow-400/30">
                            <span class="text-white font-bold text-xs tracking-tight"><?= strtoupper(substr($user_name, 0, 2)) ?></span>
                        </div>
                        <div class="text-right hidden xl:block">
                            <p class="text-[12px] font-semibold text-white tracking-tight leading-tight"><?= htmlspecialchars(substr($user_name, 0, 8)) . (strlen($user_name)>8?'..':'') ?></p>
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
        window.location.href = 'sales.php';
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
        window.location.href = 'personal_expense.php';
        return;
    }
    if (key === 'F9' || event.code === 'F9') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'report.php';
        return;
    }
    if (key === 'F10' || event.code === 'F10') {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = 'logo_marking.php';
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
        window.location.href = 'exchange.php';
        return;
    }
}, true); // Use capture phase to catch events earlier
</script>

<?php
// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Enhanced Sidebar with All Menu Items -->
<aside class="w-16 bg-slate-900 flex flex-col items-center py-4 space-y-4 fixed left-0 top-0 h-full z-40 overflow-y-auto">
    <!-- Book Gold Icon (F1) -->
    <a href="book.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'book.php' ? 'bg-blue-500/20 text-blue-300' : 'text-blue-200 hover:text-blue-300 hover:bg-blue-500/10' ?>"
       title="Book Gold (F1)">
        <i class="fas fa-book text-xl"></i>
    </a>

    <!-- Exchange Icon (F12) -->
    <a href="exchange.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'exchange.php' ? 'bg-orange-500/20 text-orange-300' : 'text-orange-200 hover:text-orange-300 hover:bg-orange-500/10' ?>"
       title="Exchange (F12)">
        <i class="fas fa-exchange-alt text-xl"></i>
    </a>

    <!-- Sales Icon (F2) -->
    <a href="sales.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'sales.php' ? 'bg-green-500/20 text-green-300' : 'text-green-200 hover:text-green-300 hover:bg-green-500/10' ?>"
       title="Sales (F2)">
        <i class="fas fa-shopping-cart text-xl"></i>
    </a>

    <!-- Purchase Icon (F3) -->
    <a href="purchase.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'purchase.php' ? 'bg-purple-500/20 text-purple-300' : 'text-purple-200 hover:text-purple-300 hover:bg-purple-500/10' ?>"
       title="Purchase (F3)">
        <i class="fas fa-shopping-basket text-xl"></i>
    </a>

    <!-- Receive Payment Icon (F4) -->
    <a href="payment_receipt.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'payment_receipt.php' ? 'bg-green-500/20 text-green-300' : 'text-green-200 hover:text-green-300 hover:bg-green-500/10' ?>"
       title="Receive Payment (F4)">
        <i class="fas fa-money-bill-wave text-xl"></i>
    </a>

    <!-- Personal expense (F8) -->
    <a href="personal_expense.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'personal_expense.php' ? 'bg-yellow-500/20 text-yellow-300' : 'text-yellow-200 hover:text-yellow-300 hover:bg-yellow-500/10' ?>"
       title="Personal expense (F8)">
        <i class="fas fa-wallet text-xl"></i>
    </a>

    <!-- Send Payment Icon (F11) -->
    <a href="payment_send.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'payment_send.php' ? 'bg-red-500/20 text-red-300' : 'text-red-200 hover:text-red-300 hover:bg-red-500/10' ?>"
       title="Send Payment (F11)">
        <i class="fas fa-paper-plane text-xl"></i>
    </a>

    <!-- Party Ledger Icon (F6) -->
    <a href="party_ledger.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'party_ledger.php' ? 'bg-pink-500/20 text-pink-300' : 'text-pink-200 hover:text-pink-300 hover:bg-pink-500/10' ?>"
       title="Party Ledger (F6)">
        <i class="fas fa-users text-xl"></i>
    </a>

    <!-- Daily Report Icon (F9) -->
    <a href="report.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'report.php' ? 'bg-teal-500/20 text-teal-300' : 'text-teal-200 hover:text-teal-300 hover:bg-teal-500/10' ?>"
       title="Daily Report (F9)">
        <i class="fas fa-chart-line text-xl"></i>
    </a>

    <!-- Settings Icon (F7) -->
    <a href="settings.php" 
       class="w-12 h-12 flex items-center justify-center rounded-lg transition-all duration-200 <?= $current_page === 'settings.php' ? 'bg-slate-500/20 text-slate-300' : 'text-slate-200 hover:text-slate-300 hover:bg-slate-500/10' ?>"
       title="Settings (F7)">
        <i class="fas fa-cog text-xl"></i>
    </a>
</aside>

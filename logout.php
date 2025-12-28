<?php
/**
 * Logout Page
 * Handles user logout and session cleanup
 */

session_start();

// Get user name before destroying session (for display)
$user_name = $_SESSION['full_name'] ?? 'User';
$company_name = $_SESSION['company_name'] ?? '';

// Destroy all session data
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page after a brief delay
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out - Mormukut</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-blue-900 to-slate-900 min-h-screen flex items-center justify-center">
    <div class="text-center px-4">
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-8 max-w-md w-full border border-white/20">
            <!-- Success Icon -->
            <div class="w-20 h-20 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-6 animate-pulse">
                <i class="fas fa-check-circle text-green-400 text-4xl"></i>
            </div>
            
            <!-- Message -->
            <h2 class="text-2xl font-bold text-white mb-2">Logged Out Successfully</h2>
            <p class="text-gray-300 mb-6">
                Goodbye, <?= htmlspecialchars($user_name) ?>!<br>
                You have been logged out securely.
            </p>
            
            <!-- Loading Animation -->
            <div class="flex justify-center space-x-2 mb-6">
                <div class="w-2 h-2 bg-blue-400 rounded-full animate-bounce" style="animation-delay: 0s;"></div>
                <div class="w-2 h-2 bg-blue-400 rounded-full animate-bounce" style="animation-delay: 0.2s;"></div>
                <div class="w-2 h-2 bg-blue-400 rounded-full animate-bounce" style="animation-delay: 0.4s;"></div>
            </div>
            
            <p class="text-sm text-gray-400">Redirecting to login page...</p>
        </div>
    </div>
    
    <script>
        // Redirect to login page after 2 seconds
        setTimeout(function() {
            window.location.href = 'login.php';
        }, 2000);
    </script>
</body>
</html>


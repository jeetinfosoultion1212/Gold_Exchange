<?php
session_start();

// Check if user has a temporary session from login
if (!isset($_SESSION['temp_user'])) {
    header('Location: login.php');
    exit;
}

// Load database configuration
require_once __DIR__ . '/config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = $conn->real_escape_string($_POST['pin'] ?? '');
    $user = $_SESSION['temp_user'];
    
    // Verify PIN
    if ($user['company_pin'] === $pin) {
        // PIN is correct, set the full session variables
        $session_token = bin2hex(random_bytes(32));
        
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['company_id'] = $user['company_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['company_name'] = $user['company_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['session_token'] = $session_token;
        
        // Handle remember me if checked during initial password step
        if (isset($_SESSION['temp_remember'])) {
            $remember_token = bin2hex(random_bytes(32));
            $remember_expires = date('Y-m-d H:i:s', strtotime('+30 days'));
            
            $update_sql = "UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ssi", $remember_token, $remember_expires, $user['id']);
            $update_stmt->execute();
            
            setcookie('remember_token', $remember_token, time() + (30 * 24 * 60 * 60), '/');
        }
        
        // Update last login
        $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $user['id']);
        $update_stmt->execute();
        
        // Clear temp variables
        unset($_SESSION['temp_user']);
        unset($_SESSION['temp_remember']);
        
        header('Location: exchange.php');
        exit;
    } else {
        $error = "Invalid PIN entered. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify PIN - Gold Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --primary: #3B82F6;
            --primary-dark: #1D4ED8;
            --secondary: #1F2937;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
            --info: #06B6D4;
            --light: #F8FAFC;
            --dark: #111827;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            line-height: 1.4;
            font-size: 14px;
        }

        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            width: 100%;
            max-width: 380px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 1.5rem 1.5rem;
            text-align: center;
            color: white;
            position: relative;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.1;
        }

        .login-header h3 {
            margin: 0;
            font-weight: 600;
            font-size: 1.3rem;
            position: relative;
            z-index: 1;
        }

        .login-header p {
            margin: 0.3rem 0 0 0;
            opacity: 0.9;
            font-size: 0.8rem;
            position: relative;
            z-index: 1;
        }

        .login-body {
            padding: 1.5rem 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.3rem;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            z-index: 2;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem 0.8rem 0.7rem 2.5rem;
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            background: white;
            color: var(--gray-900);
            letter-spacing: 2px;
            font-weight: 600;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .form-control::placeholder {
            color: var(--gray-400);
            font-size: 0.8rem;
            letter-spacing: normal;
            font-weight: 400;
        }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
            border-radius: 8px;
            padding: 0.7rem 1rem;
            font-weight: 500;
            color: white;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            margin-top: 1rem;
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .register-link {
            text-align: center;
            margin-top: 1.2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }

        .register-link p {
            color: var(--gray-600);
            font-size: 0.8rem;
            margin: 0;
        }

        .register-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .register-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .alert {
            border-radius: 8px;
            border: none;
            margin-bottom: 1rem;
            padding: 0.8rem 1rem;
            font-size: 0.8rem;
        }

        .alert-danger {
            background-color: #FEF2F2;
            color: var(--danger);
            border-left: 3px solid var(--danger);
        }

        .alert i {
            margin-right: 0.4rem;
        }

        .user-greeting {
            text-align: center;
            margin-bottom: 1rem;
            color: var(--gray-700);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h3><i class="fas fa-shield-alt me-2"></i>Security Verification</h3>
            <p>Enter your PIN to continue</p>
        </div>
        
        <div class="login-body">
            <div class="user-greeting">
                <i class="fas fa-user-circle me-1"></i> Welcome, <strong><?= htmlspecialchars($_SESSION['temp_user']['full_name']) ?></strong>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="pin" class="form-label">Login PIN</label>
                    <div class="input-wrapper">
                        <i class="fas fa-key input-icon"></i>
                        <input type="password" class="form-control" id="pin" name="pin" placeholder="Enter 4-6 digit PIN" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-unlock me-2"></i>Verify & Login
                </button>
            </form>
            
            <div class="register-link">
                <p class="mb-0"><a href="logout.php"><i class="fas fa-arrow-left me-1"></i> Cancel & Back to Login</a></p>
            </div>
        </div>
    </div>

    <script>
        // Auto-focus on PIN field
        document.getElementById('pin').focus();
    </script>
</body>
</html>

<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: book_gold.php');
    exit;
}

// Load database configuration
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = $conn->real_escape_string($_POST['company_name']);
    $company_address = $conn->real_escape_string($_POST['company_address']);
    $company_contact = $conn->real_escape_string($_POST['company_contact']);
    $company_email = $conn->real_escape_string($_POST['company_email']);
    
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = $conn->real_escape_string($_POST['full_name']);
    
    // Validation - Only require minimum details
    if (empty($company_name) || empty($username) || empty($password) || empty($full_name)) {
        $error = "Please fill in all required fields";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long";
    } else {
        // Check if company name already exists
        $check_company = "SELECT id FROM companies WHERE company_name = ?";
        $stmt = $conn->prepare($check_company);
        $stmt->bind_param("s", $company_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "Company name already exists";
        } else {
            // Check if username already exists
            $check_username = "SELECT id FROM users WHERE username = ?";
            $stmt = $conn->prepare($check_username);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "Username already exists";
            } else {
                // Check if email already exists (only if email is provided)
                if (!empty($email)) {
                    $check_email = "SELECT id FROM users WHERE email = ?";
                    $stmt = $conn->prepare($check_email);
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        $error = "Email already exists";
                    } else {
                        // Start transaction
                        $conn->begin_transaction();
                        try {
                            // Insert company
                            $company_sql = "INSERT INTO companies (company_name, company_address, company_contact, company_email) 
                                           VALUES (?, ?, ?, ?)";
                            $company_stmt = $conn->prepare($company_sql);
                            $company_stmt->bind_param("ssss", $company_name, $company_address, $company_contact, $company_email);
                            $company_stmt->execute();
                            $company_id = $conn->insert_id;
                            
                            // Hash password
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            
                            // Insert admin user (email can be NULL)
                            $user_sql = "INSERT INTO users (company_id, username, email, password, full_name, role) 
                                        VALUES (?, ?, ?, ?, ?, 'Admin')";
                            $user_stmt = $conn->prepare($user_sql);
                            $user_stmt->bind_param("issss", $company_id, $username, $email, $hashed_password, $full_name);
                            $user_stmt->execute();
                            
                            // Insert initial gold stock
                            $stock_sql = "INSERT INTO gold_stock (company_id, stock_name, purity, current_stock) VALUES (?, ?, ?, ?)";
                            $stock_stmt = $conn->prepare($stock_sql);
                            
                            $stocks = [
                                ['Gold Coin', 99.90],
                                ['Gold Bar', 99.50],
                                ['Gold Ornaments', 91.60]
                            ];
                            
                            foreach ($stocks as $stock) {
                                $initial_stock = 0.000;
                                $stock_stmt->bind_param("isdd", $company_id, $stock[0], $stock[1], $initial_stock);
                                $stock_stmt->execute();
                            }
                            
                            $conn->commit();
                            header('Location: login.php?success=1');
                            exit;
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Registration failed: " . $e->getMessage();
                        }
                    }
                } else {
                    // No email provided, proceed without email check
                    // Start transaction
                    $conn->begin_transaction();
                    try {
                        // Insert company
                        $company_sql = "INSERT INTO companies (company_name, company_address, company_contact, company_email) 
                                       VALUES (?, ?, ?, ?)";
                        $company_stmt = $conn->prepare($company_sql);
                        $company_stmt->bind_param("ssss", $company_name, $company_address, $company_contact, $company_email);
                        $company_stmt->execute();
                        $company_id = $conn->insert_id;
                        
                        // Hash password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Insert admin user (email can be NULL)
                        $user_sql = "INSERT INTO users (company_id, username, email, password, full_name, role) 
                                    VALUES (?, ?, ?, ?, ?, 'Admin')";
                        $user_stmt = $conn->prepare($user_sql);
                        $user_stmt->bind_param("issss", $company_id, $username, $email, $hashed_password, $full_name);
                        $user_stmt->execute();
                        
                        // Insert initial gold stock
                        $stock_sql = "INSERT INTO gold_stock (company_id, stock_name, purity, current_stock) VALUES (?, ?, ?, ?)";
                        $stock_stmt = $conn->prepare($stock_sql);
                        
                        $stocks = [
                            ['Gold Coin', 99.90],
                            ['Gold Bar', 99.50],
                            ['Gold Ornaments', 91.60]
                        ];
                        
                        foreach ($stocks as $stock) {
                            $initial_stock = 0.000;
                            $stock_stmt->bind_param("isdd", $company_id, $stock[0], $stock[1], $initial_stock);
                            $stock_stmt->execute();
                        }
                        
                        $conn->commit();
                        header('Location: login.php?success=1');
                        exit;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = "Registration failed: " . $e->getMessage();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Gold Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            padding: 15px 0;
            line-height: 1.4;
            font-size: 14px;
        }

        .register-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .register-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 1.5rem 1.5rem;
            text-align: center;
            color: white;
            position: relative;
        }

        .register-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.1;
        }

        .register-header h3 {
            margin: 0;
            font-weight: 600;
            font-size: 1.3rem;
            position: relative;
            z-index: 1;
        }

        .register-header p {
            margin: 0.3rem 0 0 0;
            opacity: 0.9;
            font-size: 0.8rem;
            position: relative;
            z-index: 1;
        }

        .register-body {
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

        .form-label.required::after {
            content: ' *';
            color: var(--danger);
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

        .form-control, .form-select {
            width: 100%;
            padding: 0.7rem 0.8rem 0.7rem 2.5rem;
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            background: white;
            color: var(--gray-900);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .form-control::placeholder {
            color: var(--gray-400);
            font-size: 0.8rem;
        }

        textarea.form-control {
            padding-top: 0.7rem;
            resize: vertical;
            min-height: 80px;
        }

        .btn-register {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
            border-radius: 8px;
            padding: 0.7rem 1.5rem;
            font-weight: 500;
            color: white;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            cursor: pointer;
            width: 100%;
        }

        .btn-register:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
        }

        .btn-cancel {
            background: white;
            border: 1.5px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.7rem 1.5rem;
            font-weight: 500;
            color: var(--gray-700);
            transition: all 0.2s ease;
            cursor: pointer;
            width: 100%;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-size: 0.9rem;
        }

        .btn-cancel:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
            color: var(--gray-800);
            text-decoration: none;
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

        .alert-success {
            background-color: #F0FDF4;
            color: var(--success);
            border-left: 3px solid var(--success);
        }

        .alert i {
            margin-right: 0.4rem;
        }

        .section-title {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--gray-200);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .section-title i {
            color: var(--primary-dark);
        }

        .login-link {
            text-align: center;
            margin-top: 1.2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }

        .login-link p {
            color: var(--gray-600);
            font-size: 0.8rem;
            margin: 0;
        }

        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .login-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px 0;
            }
            
            .register-container {
                margin: 10px;
                border-radius: 10px;
                max-width: 95%;
            }
            
            .register-header, .register-body {
                padding: 1.2rem 1.2rem;
            }
            
            .register-header h3 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-container">
            <div class="register-header">
                <h3><i class="fas fa-gem me-2"></i>Company Registration</h3>
                <p>Create your Gold Management Account</p>
            </div>
            
            <div class="register-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="registerForm">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="section-title">
                                <i class="fas fa-building"></i>Company Information
                            </h6>
                            
                            <div class="form-group">
                                <label for="company_name" class="form-label required">Company Name</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-building input-icon"></i>
                                    <input type="text" class="form-control" id="company_name" name="company_name" placeholder="Enter company name" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="company_contact" class="form-label required">Mobile Number</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-phone input-icon"></i>
                                    <input type="tel" class="form-control" id="company_contact" name="company_contact" placeholder="Enter mobile number" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="company_address" class="form-label">Company Address (Optional)</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-map-marker-alt input-icon"></i>
                                    <textarea class="form-control" id="company_address" name="company_address" placeholder="Enter company address (optional)"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="company_email" class="form-label">Company Email (Optional)</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-envelope input-icon"></i>
                                    <input type="email" class="form-control" id="company_email" name="company_email" placeholder="Enter company email (optional)">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="section-title">
                                <i class="fas fa-user-shield"></i>Admin User Information
                            </h6>
                            
                            <div class="form-group">
                                <label for="full_name" class="form-label required">Full Name</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-user input-icon"></i>
                                    <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Enter full name" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="username" class="form-label required">Username</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-at input-icon"></i>
                                    <input type="text" class="form-control" id="username" name="username" placeholder="Enter username" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="form-label">Email Address (Optional)</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-envelope input-icon"></i>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter email address (optional)">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="password" class="form-label required">Password</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password" class="form-label required">Confirm Password</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm password" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <button type="submit" class="btn-register">
                                <i class="fas fa-user-plus me-2"></i>Register Company
                            </button>
                        </div>
                        <div class="col-md-6">
                            <a href="login.php" class="btn-cancel">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </form>
                
                <div class="login-link">
                    <p class="mb-0">Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                document.getElementById('confirm_password').focus();
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                document.getElementById('password').focus();
                return false;
            }
        });
        
        // Real-time password confirmation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Auto-focus on first field
        document.getElementById('company_name').focus();
    </script>
</body>
</html>

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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'Gold Management System' ?> - Mormukut</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts: Inter primary UI; Poppins for accent headings -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.18/dist/sweetalert2.min.css">
    
    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
        fontFamily: {
            'sans': ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
            'poppins': ['Poppins', 'Inter', 'system-ui', 'sans-serif'],
        },
                    colors: {
                        'soft-blue': '#E0F2FE',
                        'soft-green': '#F0FDF4',
                        'soft-purple': '#FAF5FF',
                        'soft-orange': '#FFF7ED',
                        'soft-pink': '#FDF2F8',
                    }
                }
            }
        }
    </script>
    
    <!-- Custom Styles -->
    <style>
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .gradient-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248,250,252,0.8) 100%);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .soft-gradient-blue {
            background: linear-gradient(135deg, #E0F2FE 0%, #BAE6FD 100%);
        }
        
        .soft-gradient-green {
            background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
        }
        
        .soft-gradient-purple {
            background: linear-gradient(135deg, #FAF5FF 0%, #F3E8FF 100%);
        }
        
        .soft-gradient-orange {
            background: linear-gradient(135deg, #FFF7ED 0%, #FED7AA 100%);
        }
        
        .soft-gradient-pink {
            background: linear-gradient(135deg, #FDF2F8 0%, #FCE7F3 100%);
        }
        
        .table-row-hover:hover {
            background: linear-gradient(135deg, rgba(59,130,246,0.05) 0%, rgba(147,197,253,0.05) 100%);
        }
        
        .btn-soft {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248,250,252,0.8) 100%);
            border: 1px solid rgba(226,232,240,0.5);
            backdrop-filter: blur(10px);
        }
        
        .btn-soft:hover {
            background: linear-gradient(135deg, rgba(59,130,246,0.1) 0%, rgba(147,197,253,0.1) 100%);
            border-color: rgba(59,130,246,0.3);
        }
        
        .status-due {
            background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
            color: #DC2626;
        }
        
        .status-clear {
            background: linear-gradient(135deg, #DCFCE7 0%, #BBF7D0 100%);
            color: #16A34A;
        }
        
        .avatar-gradient {
            background: linear-gradient(135deg, #3B82F6 0%, #1D4ED8 100%);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .gradient-card {
                margin-bottom: 1rem;
            }
            
            .soft-gradient-blue,
            .soft-gradient-green,
            .soft-gradient-purple,
            .soft-gradient-orange {
                padding: 0.75rem !important;
            }
            
            .soft-gradient-blue h3,
            .soft-gradient-green h3,
            .soft-gradient-purple h3,
            .soft-gradient-orange h3 {
                font-size: 1rem !important;
            }
            
            .soft-gradient-blue p,
            .soft-gradient-green p,
            .soft-gradient-purple p,
            .soft-gradient-orange p {
                font-size: 0.75rem !important;
            }
        }
        
        /* Professional party list styling */
        .party-item {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 400;
            letter-spacing: -0.01em;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        .party-item .text-sm {
            font-weight: 500;
            letter-spacing: -0.02em;
        }
        
        .party-item .text-xs {
            font-weight: 400;
            letter-spacing: 0;
        }
        
        /* Professional input styling */
        input, select, textarea {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 400;
            letter-spacing: -0.01em;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* Professional button styling */
        button {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 600;
            letter-spacing: -0.01em;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* Professional body styling */
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 400;
            line-height: 1.5;
            letter-spacing: -0.01em;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* Headers and titles */
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        
        /* Navigation and menu items */
        nav, nav a, nav button {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 600;
        }
        
        /* Labels */
        label {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 500;
        }
        
        /* Code and monospace elements */
        code, pre, .font-mono {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'components/sidebar.php'; ?>
    
    <!-- Include Header -->
    <?php include 'components/header.php'; ?>
    
            <!-- Main Content Area -->
            <div class="min-h-screen bg-gray-50 main-with-sidebar pt-2">
                <!-- Content will be inserted here -->
                <main class="p-2 lg:p-3">
                    <?php if (isset($content)): ?>
                        <?= $content ?>
                    <?php endif; ?>
                </main>
            </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Additional scripts will be included here -->
    <script src="js/shared-party-handler.js"></script>
    <?php if (isset($additional_scripts)): ?>
        <?= $additional_scripts ?>
    <?php endif; ?>
</body>
</html>

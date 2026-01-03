<?php
/**
 * License Verification API
 * Deploy this file to your online server (e.g., Hostinger)
 * URL: https://yourdomain.com/api/verify-license.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection (UPDATE WITH YOUR CREDENTIALS)
$db_host = 'localhost';
$db_name = 'u176143338_licenses'; // Your license database
$db_user = 'u176143338_mormukut';
$db_pass = 'Mahalaxmi1234@#';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid request data'
        ]);
        exit;
    }
    
    $license_key = $conn->real_escape_string($data['license_key'] ?? '');
    $company_id = intval($data['company_id'] ?? 0);
    $machine_id = $conn->real_escape_string($data['machine_id'] ?? '');
    $app_version = $conn->real_escape_string($data['app_version'] ?? '');
    $platform = $conn->real_escape_string($data['platform'] ?? '');
    
    // Validate required fields
    if (empty($license_key) || empty($company_id)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'License key and company ID are required'
        ]);
        exit;
    }
    
    // Check license in database
    $sql = "SELECT * FROM licenses 
            WHERE license_key = ? 
            AND company_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $license_key, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $license = $result->fetch_assoc();
        
        // Check if blocked
        if ($license['status'] === 'blocked') {
            echo json_encode([
                'status' => 'blocked',
                'message' => 'Your account has been suspended due to payment issues. Please contact support.',
                'support_email' => 'support@yourdomain.com',
                'support_phone' => '+91-XXXXXXXXXX'
            ]);
            exit;
        }
        
        // Check expiry date
        $expiry_date = strtotime($license['expiry_date']);
        $today = time();
        
        if ($expiry_date < $today) {
            echo json_encode([
                'status' => 'expired',
                'message' => 'Your license has expired. Please renew your subscription.',
                'expiry_date' => $license['expiry_date'],
                'days_expired' => floor(($today - $expiry_date) / 86400)
            ]);
            exit;
        }
        
        // License is active
        // Update last seen and machine info
        $update_sql = "UPDATE licenses 
                      SET last_seen = NOW(), 
                          machine_id = ?,
                          app_version = ?,
                          platform = ?
                      WHERE id = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sssi", $machine_id, $app_version, $platform, $license['id']);
        $update_stmt->execute();
        
        // Calculate days until expiry
        $days_remaining = floor(($expiry_date - $today) / 86400);
        
        echo json_encode([
            'status' => 'active',
            'message' => 'License verified successfully',
            'company_name' => $license['company_name'],
            'expiry_date' => $license['expiry_date'],
            'days_remaining' => $days_remaining,
            'license_type' => $license['license_type'] ?? 'standard',
            'max_users' => $license['max_users'] ?? 1
        ]);
        
    } else {
        // License not found
        echo json_encode([
            'status' => 'invalid',
            'message' => 'Invalid license key or company ID',
            'support_email' => 'support@yourdomain.com'
        ]);
    }
    
    $stmt->close();
    $conn->close();
    
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Only POST requests are allowed'
    ]);
}
?>

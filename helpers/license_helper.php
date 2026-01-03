<?php
/**
 * License Creation Helper
 * This function creates a license entry in your Hostinger database
 * when a new company registers
 */

function createLicenseOnHostinger($company_id, $company_name, $company_email, $company_contact) {
    // Hostinger database credentials
    $license_db_host = 'localhost';
    $license_db_name = 'u176143338_licenses';
    $license_db_user = 'u176143338_mormukut';
    $license_db_pass = 'Mahalaxmi1234@#';
    
    try {
        // Connect to Hostinger license database
        $license_conn = new mysqli($license_db_host, $license_db_user, $license_db_pass, $license_db_name);
        
        if ($license_conn->connect_error) {
            error_log("License DB connection failed: " . $license_conn->connect_error);
            return false;
        }
        
        // Generate unique license key
        $license_key = 'GOLD-' . date('Y') . '-' . strtoupper(substr(md5($company_name . time()), 0, 8));
        
        // Set expiry date (30 days trial by default)
        $expiry_date = date('Y-m-d', strtotime('+30 days'));
        
        // Determine license type (trial for new registrations)
        $license_type = 'trial';
        $status = 'active';
        $max_users = 1;
        
        // Insert license
        $sql = "INSERT INTO licenses 
                (license_key, company_id, company_name, contact_email, contact_phone, 
                 status, license_type, expiry_date, max_users, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $license_conn->prepare($sql);
        $stmt->bind_param(
            "sissssssi", 
            $license_key, 
            $company_id, 
            $company_name, 
            $company_email, 
            $company_contact,
            $status,
            $license_type,
            $expiry_date,
            $max_users
        );
        
        $result = $stmt->execute();
        
        if ($result) {
            error_log("License created successfully: $license_key for company: $company_name");
            
            // Optionally: Send license key via email or SMS
            // sendLicenseEmail($company_email, $license_key, $expiry_date);
            
            $stmt->close();
            $license_conn->close();
            return $license_key;
        } else {
            error_log("Failed to create license: " . $stmt->error);
            $stmt->close();
            $license_conn->close();
            return false;
        }
        
    } catch (Exception $e) {
        error_log("License creation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Optional: Send license key via email
 */
function sendLicenseEmail($email, $license_key, $expiry_date) {
    if (empty($email)) {
        return false;
    }
    
    $subject = "Your Gold Exchange License Key";
    $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #3B82F6; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .license-box { background: white; padding: 15px; border-left: 4px solid #3B82F6; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Welcome to Gold Exchange Management System</h2>
                </div>
                <div class='content'>
                    <p>Thank you for registering with us!</p>
                    <p>Your account has been created successfully. Here are your license details:</p>
                    
                    <div class='license-box'>
                        <h3>License Key:</h3>
                        <p style='font-size: 18px; font-weight: bold; color: #3B82F6;'>$license_key</p>
                        <p><strong>Expiry Date:</strong> $expiry_date</p>
                        <p><strong>License Type:</strong> Trial (30 days)</p>
                    </div>
                    
                    <p>Please keep this license key safe. You will need it to activate your desktop application.</p>
                    
                    <p>To upgrade to a premium license or extend your trial, please contact our support team.</p>
                </div>
                <div class='footer'>
                    <p>© " . date('Y') . " Gold Exchange Management System. All rights reserved.</p>
                    <p>For support, contact: support@yourdomain.com</p>
                </div>
            </div>
        </body>
        </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Gold Exchange <noreply@yourdomain.com>" . "\r\n";
    
    return mail($email, $subject, $message, $headers);
}
?>

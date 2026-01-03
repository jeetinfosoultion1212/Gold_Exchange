-- License Management Database Schema
-- Deploy this to your online server (Hostinger)

CREATE DATABASE IF NOT EXISTS u176143338_licenses;
USE u176143338_licenses;

-- Licenses Table
CREATE TABLE IF NOT EXISTS licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(255) UNIQUE NOT NULL,
    company_id INT NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    contact_email VARCHAR(255),
    contact_phone VARCHAR(50),
    status ENUM('active', 'expired', 'blocked', 'trial') DEFAULT 'active',
    license_type ENUM('standard', 'premium', 'enterprise') DEFAULT 'standard',
    expiry_date DATE NOT NULL,
    max_users INT DEFAULT 1,
    machine_id VARCHAR(255),
    app_version VARCHAR(50),
    platform VARCHAR(50),
    last_seen DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes TEXT,
    INDEX idx_license_key (license_key),
    INDEX idx_company_id (company_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment History Table
CREATE TABLE IF NOT EXISTS payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50),
    transaction_id VARCHAR(255),
    status ENUM('pending', 'completed', 'failed') DEFAULT 'completed',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    INDEX idx_license_id (license_id),
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity Log Table
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    INDEX idx_license_id (license_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert Sample Licenses
INSERT INTO licenses (license_key, company_id, company_name, contact_email, expiry_date, license_type, max_users) 
VALUES 
    ('GOLD-2024-DEMO-0001', 1, 'ABC Jewellers', 'abc@example.com', '2025-12-31', 'standard', 1),
    ('GOLD-2024-DEMO-0002', 2, 'XYZ Gold', 'xyz@example.com', '2025-12-31', 'premium', 3),
    ('GOLD-2024-TRIAL-0001', 3, 'Test Company', 'test@example.com', DATE_ADD(NOW(), INTERVAL 30 DAY), 'trial', 1);

-- Sample Payment History
INSERT INTO payment_history (license_id, amount, payment_date, payment_method, transaction_id) 
VALUES 
    (1, 5000.00, '2024-01-01', 'Bank Transfer', 'TXN001'),
    (2, 15000.00, '2024-01-01', 'UPI', 'TXN002');

-- Useful Queries

-- View all active licenses
SELECT 
    license_key,
    company_name,
    status,
    expiry_date,
    DATEDIFF(expiry_date, NOW()) as days_remaining,
    last_seen
FROM licenses
WHERE status = 'active'
ORDER BY expiry_date;

-- View licenses expiring soon (within 30 days)
SELECT 
    license_key,
    company_name,
    contact_email,
    expiry_date,
    DATEDIFF(expiry_date, NOW()) as days_remaining
FROM licenses
WHERE status = 'active'
AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
ORDER BY expiry_date;

-- Block a license (for non-payment)
-- UPDATE licenses SET status = 'blocked' WHERE company_id = 1;

-- Unblock a license (after payment)
-- UPDATE licenses SET status = 'active' WHERE company_id = 1;

-- Extend license expiry
-- UPDATE licenses SET expiry_date = DATE_ADD(expiry_date, INTERVAL 1 YEAR) WHERE company_id = 1;

-- View payment history for a company
-- SELECT * FROM payment_history WHERE license_id = 1 ORDER BY payment_date DESC;

# 🔐 Automatic License Creation System

## Overview

When a new company registers in your Gold Exchange app, a license is **automatically created** in your Hostinger database. This enables you to control access and manage subscriptions remotely.

---

## 🔄 How It Works

### **Registration Flow:**

```
1. User fills registration form
   ↓
2. Company created in local database
   ↓
3. User account created
   ↓
4. Initial gold stock added
   ↓
5. ✨ LICENSE AUTOMATICALLY CREATED IN HOSTINGER ✨
   ↓
6. User redirected to login
```

---

## 📋 What Gets Created

When a company registers, the system creates a license with:

| Field | Value | Description |
|-------|-------|-------------|
| **license_key** | `GOLD-2024-XXXXXXXX` | Unique auto-generated key |
| **company_id** | From local DB | Links to local company |
| **company_name** | From registration | Company name |
| **contact_email** | From registration | Email (if provided) |
| **contact_phone** | From registration | Phone number |
| **status** | `active` | License is active |
| **license_type** | `trial` | 30-day trial |
| **expiry_date** | +30 days | Trial expiry date |
| **max_users** | `1` | Single user license |

---

## 🔧 Configuration

### **Database Credentials**

Edit `helpers/license_helper.php` (lines 10-13):

```php
$license_db_host = 'localhost';
$license_db_name = 'u176143338_licenses';
$license_db_user = 'u176143338_mormukut';
$license_db_pass = 'Mahalaxmi1234@#';
```

**⚠️ Important:** These should match your Hostinger database credentials!

---

## 📝 License Key Format

License keys are auto-generated in this format:

```
GOLD-2024-A1B2C3D4
│    │    └─ Random 8-character hash
│    └─ Current year
└─ Prefix
```

**Example:** `GOLD-2024-F7E8D9C2`

---

## 🎯 Trial Period

- **Default:** 30 days from registration
- **Type:** Trial license
- **Status:** Active
- **Max Users:** 1

After 30 days, the license will expire and the user will need to upgrade.

---

## 💰 Managing Licenses

### **View All Licenses:**
```sql
SELECT license_key, company_name, status, expiry_date, 
       DATEDIFF(expiry_date, NOW()) as days_remaining
FROM licenses
ORDER BY created_at DESC;
```

### **Upgrade Trial to Premium:**
```sql
UPDATE licenses 
SET license_type = 'premium',
    expiry_date = DATE_ADD(NOW(), INTERVAL 1 YEAR),
    max_users = 5
WHERE company_id = 123;
```

### **Extend Expiry:**
```sql
UPDATE licenses 
SET expiry_date = DATE_ADD(expiry_date, INTERVAL 1 YEAR)
WHERE company_id = 123;
```

### **Block Non-Paying Customer:**
```sql
UPDATE licenses 
SET status = 'blocked'
WHERE company_id = 123;
```

### **Unblock After Payment:**
```sql
UPDATE licenses 
SET status = 'active'
WHERE company_id = 123;
```

---

## 📧 Email Notification (Optional)

The system includes an optional email function to send license keys to customers.

### **Enable Email Notifications:**

Uncomment line 53 in `helpers/license_helper.php`:

```php
// sendLicenseEmail($company_email, $license_key, $expiry_date);
```

**Becomes:**
```php
sendLicenseEmail($company_email, $license_key, $expiry_date);
```

### **Customize Email:**

Edit the `sendLicenseEmail()` function in `helpers/license_helper.php` to customize:
- Email subject
- Email content
- From address
- Company branding

---

## 🔍 Troubleshooting

### **Issue: License not created**

**Check:**
1. Hostinger database credentials are correct
2. `licenses` table exists in Hostinger database
3. Check `php_error.log` for errors

**Solution:**
```bash
# Check error log
tail -f php_error.log | grep "License"
```

### **Issue: Duplicate license keys**

**Cause:** Very rare, but possible if two registrations happen at exact same millisecond

**Solution:** License keys include timestamp hash, making duplicates extremely unlikely

### **Issue: Cannot connect to Hostinger**

**Check:**
1. Hostinger database is accessible
2. Credentials are correct
3. IP is whitelisted (if remote access)

---

## 📊 Database Schema

The license is stored in your Hostinger database:

```sql
CREATE TABLE licenses (
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
    notes TEXT
);
```

---

## 🎯 Integration Points

### **Files Modified:**

1. ✅ `register.php` - Added license creation calls
2. ✅ `helpers/license_helper.php` - License creation logic

### **Files Created:**

1. ✅ `helpers/license_helper.php` - New helper file

---

## ✨ Features

### **Automatic:**
- ✅ License key generation
- ✅ 30-day trial activation
- ✅ Company linking
- ✅ Error logging

### **Manual:**
- ✅ License upgrades (via SQL)
- ✅ Expiry extensions (via SQL)
- ✅ Customer blocking (via SQL)
- ✅ Email notifications (optional)

---

## 🚀 Testing

### **Test Registration:**

1. Register a new company
2. Check Hostinger database:
```sql
SELECT * FROM licenses ORDER BY created_at DESC LIMIT 1;
```
3. Verify license was created
4. Check `php_error.log` for confirmation

### **Expected Log Entry:**
```
License created for company ABC Jewellers: GOLD-2024-F7E8D9C2
```

---

## 📞 Support Workflow

### **Customer Registers:**
1. License auto-created (30-day trial)
2. Customer uses app for 30 days
3. License expires

### **Customer Wants to Continue:**
1. Customer contacts you
2. Customer pays
3. You upgrade license:
```sql
UPDATE licenses 
SET license_type = 'premium',
    expiry_date = DATE_ADD(NOW(), INTERVAL 1 YEAR)
WHERE company_name = 'ABC Jewellers';
```
4. Customer can login again

### **Customer Doesn't Pay:**
1. License expires automatically
2. Customer cannot login
3. Data is safe in database
4. When customer pays, you extend license
5. Customer regains access

---

## 🎉 Benefits

✅ **Automatic** - No manual license creation  
✅ **Secure** - License stored in remote database  
✅ **Controlled** - You control access remotely  
✅ **Flexible** - Easy to upgrade/extend  
✅ **Trackable** - All licenses in one place  
✅ **Scalable** - Works for unlimited customers  

---

## 📝 Notes

- License creation happens **after** successful registration
- If license creation fails, registration still succeeds (logged as warning)
- License keys are unique and cannot be duplicated
- Trial licenses are automatically set to expire in 30 days
- You can manually adjust any license parameters via SQL

---

**Everything is automated! Just register a company and the license is created automatically!** 🚀

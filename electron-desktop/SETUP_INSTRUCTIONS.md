# 🚀 Setup Instructions - Electron Desktop App

## 📋 Prerequisites

Before you begin, make sure you have:
- ✅ Node.js 18+ installed
- ✅ Windows 10/11 (for development)
- ✅ Internet connection (for npm install)

---

## 🔧 Step-by-Step Setup

### **Step 1: Install Dependencies**

Open terminal in `electron-desktop` folder:

```bash
cd d:\Project\Gold_Exchange\electron-desktop
npm install
```

This will install:
- Electron
- Electron Builder
- Axios (for API calls)
- Express (optional)

---

### **Step 2: Download Portable PHP & MariaDB**

#### **2.1 Download PHP 8.2 (Thread Safe)**

1. Go to: https://windows.php.net/download/
2. Download: **PHP 8.2.x Thread Safe (x64)**
3. Extract to: `electron-desktop/server/php/`

**Directory structure should be:**
```
server/php/
├── php.exe
├── php.ini-development
├── ext/
└── ...
```

#### **2.2 Configure PHP**

1. Copy `php.ini-development` to `php.ini`
2. Edit `php.ini` and enable these extensions:

```ini
extension=mysqli
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=curl
extension=gd
extension=fileinfo

max_execution_time = 300
memory_limit = 512M
post_max_size = 100M
upload_max_filesize = 100M
```

#### **2.3 Download MariaDB Portable**

1. Go to: https://mariadb.org/download/
2. Download: **MariaDB 10.11.x (Windows ZIP)**
3. Extract to: `electron-desktop/server/mariadb/`

**Directory structure should be:**
```
server/mariadb/
├── bin/
│   └── mysqld.exe
├── data/
└── my.ini
```

#### **2.4 Initialize MariaDB**

Create `server/mariadb/my.ini`:

```ini
[mysqld]
port=3307
datadir=./data
skip-grant-tables=0
max_connections=100
```

Initialize database (first time only):
```bash
cd server\mariadb\bin
mysqld.exe --initialize-insecure --datadir=..\data
```

---

### **Step 3: Copy Your PHP Application**

```bash
# From Gold_Exchange folder, copy all files to server/www/
mkdir server\www
xcopy /E /I /Y ..\*.* server\www\

# Exclude electron-desktop folder itself
```

**Or manually copy:**
- All `.php` files
- `css/` folder
- `js/` folder
- `components/` folder
- `config/` folder
- etc.

---

### **Step 4: Update Database Configuration**

Edit `server/www/config/database.php`:

Make sure it detects Electron environment:

```php
$is_desktop_app = (getenv('PHPDESKTOP_VERSION') !== false);

if ($is_desktop_app) {
    // Desktop settings
    $db_host = '127.0.0.1';
    $db_port = 3307;
    $db_name = 'gold_exchange';
    $db_user = 'root';
    $db_pass = '';
}
```

---

### **Step 5: Add License Verification to Login**

Edit `server/www/login.php`:

Add this code **before** the closing `</body>` tag:

```html
<!-- Copy content from login-integration.js -->
<script>
// Paste the entire content from login-integration.js here
</script>
```

---

### **Step 6: Test in Development Mode**

```bash
npm run dev
```

**What should happen:**
1. ✅ MySQL server starts (port 3307)
2. ✅ PHP server starts (port 8080)
3. ✅ Electron window opens
4. ✅ Login page loads

**Check console for:**
```
🚀 Starting Gold Exchange Desktop...
📦 Starting MySQL...
✅ MySQL ready!
🐘 Starting PHP Server...
✅ PHP Server ready!
🪟 Creating Window...
✅ Application started successfully!
```

---

### **Step 7: Setup License API (Online Server)**

#### **7.1 Upload to Hostinger**

1. Upload `verify-license.php` to your Hostinger
2. Place it at: `public_html/api/verify-license.php`
3. Update database credentials in the file

#### **7.2 Create License Database**

1. Login to Hostinger phpMyAdmin
2. Create database: `u176143338_licenses`
3. Import `license-database.sql`

#### **7.3 Update API URL**

Edit `main.js` line 11:

```javascript
LICENSE_API: 'https://yourdomain.com/api/verify-license.php'
```

---

### **Step 8: Build Installer**

```bash
npm run build-win
```

**Output:**
```
dist/
└── Gold Exchange Setup 1.0.0.exe  (~150-200 MB)
```

**Build time:** 5-10 minutes

---

### **Step 9: Test Installer**

1. Copy installer to a **clean Windows machine**
2. Run installer
3. Install to default location
4. Launch app
5. Test login with license verification

---

## 🎯 Quick Test Checklist

- [ ] Dependencies installed (`npm install`)
- [ ] PHP extracted to `server/php/`
- [ ] PHP extensions enabled in `php.ini`
- [ ] MariaDB extracted to `server/mariadb/`
- [ ] MariaDB initialized
- [ ] PHP app copied to `server/www/`
- [ ] Database config updated
- [ ] License verification added to login
- [ ] Development mode works (`npm run dev`)
- [ ] License API deployed to server
- [ ] License database created
- [ ] API URL updated in `main.js`
- [ ] Installer built (`npm run build-win`)
- [ ] Installer tested on clean machine

---

## 🐛 Common Issues

### Issue: "PHP not found"
**Solution:** 
- Check `server/php/php.exe` exists
- Verify path in `main.js`

### Issue: "MySQL won't start"
**Solution:**
- Run initialization: `mysqld.exe --initialize-insecure`
- Check port 3307 is free
- Check `my.ini` configuration

### Issue: "License verification fails"
**Solution:**
- Check internet connection
- Verify API URL is correct
- Check API is accessible (test in browser)
- Check database credentials in `verify-license.php`

### Issue: "App won't build"
**Solution:**
- Delete `node_modules` and run `npm install` again
- Check `package.json` is correct
- Ensure no syntax errors in `main.js`

---

## 📦 Distribution

### **Option 1: Direct Download**
- Upload installer to your website
- Provide download link to customers
- Send license key via email

### **Option 2: Custom Installers**
- Generate unique installer per customer
- Pre-configure license key
- No manual entry needed

### **Option 3: USB Distribution**
- Copy installer to USB drive
- Include setup instructions
- Provide license key on paper

---

## 🔒 Managing Licenses

### **Block a customer:**
```sql
UPDATE licenses 
SET status = 'blocked' 
WHERE company_id = 123;
```

### **Unblock after payment:**
```sql
UPDATE licenses 
SET status = 'active' 
WHERE company_id = 123;
```

### **Extend license:**
```sql
UPDATE licenses 
SET expiry_date = DATE_ADD(expiry_date, INTERVAL 1 YEAR) 
WHERE company_id = 123;
```

### **View expiring licenses:**
```sql
SELECT company_name, expiry_date, 
       DATEDIFF(expiry_date, NOW()) as days_remaining
FROM licenses
WHERE status = 'active'
AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY);
```

---

## 🎉 You're Done!

Your desktop app is ready to distribute!

**Next steps:**
1. Test thoroughly on different machines
2. Create user documentation
3. Setup customer support system
4. Distribute to customers
5. Monitor license usage

---

## 📞 Need Help?

Refer to:
- `README.md` - Quick reference
- `ELECTRON_DESKTOP_GUIDE.md` - Detailed guide
- Console logs - Check for errors

---

**Estimated Setup Time:** 2-3 hours  
**Build Time:** 5-10 minutes  
**Installer Size:** 150-200 MB

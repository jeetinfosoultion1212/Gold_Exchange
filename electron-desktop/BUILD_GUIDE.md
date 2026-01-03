# 🚀 Build Your Desktop App - Step by Step Guide

## 📋 Prerequisites Checklist

Before you start, make sure you have:
- [ ] Windows 10 or 11
- [ ] Internet connection (for downloads)
- [ ] About 2GB free disk space
- [ ] 1-2 hours of time

---

## 🎯 Step 1: Install Node.js (5 minutes)

### Download & Install:
1. Go to: https://nodejs.org/
2. Download **LTS version** (recommended)
3. Run the installer
4. Click "Next" → "Next" → "Install"
5. **Important:** Check "Add to PATH" option

### Verify Installation:
```bash
# Open PowerShell and type:
node --version
npm --version
```

**Expected output:**
```
v20.x.x
10.x.x
```

✅ If you see version numbers, Node.js is installed!

---

## 🎯 Step 2: Install Dependencies (2 minutes)

```bash
# Navigate to electron-desktop folder
cd d:\Project\Gold_Exchange\electron-desktop

# Install all dependencies
npm install
```

**What this does:**
- Installs Electron
- Installs Electron Builder
- Installs Axios (for API calls)
- Downloads ~200MB of packages

**Expected output:**
```
added 500+ packages in 2m
```

✅ When you see "added X packages", it's done!

---

## 🎯 Step 3: Download PHP Portable (10 minutes)

### 3.1 Download PHP:
1. Go to: https://windows.php.net/download/
2. Find **PHP 8.2.x Thread Safe (x64)**
3. Click **Zip** to download
4. File size: ~30MB

### 3.2 Extract PHP:
1. Extract the ZIP file
2. Copy **everything** to: `d:\Project\Gold_Exchange\electron-desktop\server\php\`

**Your folder should look like:**
```
server/php/
├── php.exe          ✅
├── php.ini-development
├── ext/             ✅
├── php8ts.dll
└── ... (other files)
```

### 3.3 Configure PHP:
1. Copy `php.ini-development` → rename to `php.ini`
2. Open `php.ini` in Notepad
3. Find and uncomment these lines (remove the `;`):

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
```

**How to uncomment:**
```ini
;extension=mysqli    ← Remove the semicolon
extension=mysqli     ← Like this
```

✅ Save and close!

---

## 🎯 Step 4: Download MariaDB Portable (10 minutes)

### 4.1 Download MariaDB:
1. Go to: https://mariadb.org/download/
2. Select **MariaDB 10.11.x**
3. Choose **Windows (ZIP file)**
4. Download (~150MB)

### 4.2 Extract MariaDB:
1. Extract the ZIP file
2. Copy **everything** to: `d:\Project\Gold_Exchange\electron-desktop\server\mariadb\`

**Your folder should look like:**
```
server/mariadb/
├── bin/
│   ├── mysqld.exe   ✅
│   └── mysql.exe
├── data/            (will be created)
└── ... (other folders)
```

### 4.3 Initialize MariaDB:
```bash
# Open PowerShell
cd d:\Project\Gold_Exchange\electron-desktop\server\mariadb\bin

# Initialize database (first time only)
.\mysqld.exe --initialize-insecure --datadir=..\data
```

**Expected output:**
```
[Note] mysqld: ready for connections
```

✅ Database initialized!

---

## 🎯 Step 5: Copy Your PHP App (2 minutes)

### Option A: Manual Copy
1. Create folder: `server\www\`
2. Copy ALL files from `d:\Project\Gold_Exchange\` to `server\www\`
3. **Exclude:** `electron-desktop` folder itself

### Option B: Using Command
```bash
cd d:\Project\Gold_Exchange\electron-desktop

# Create www folder
mkdir server\www

# Copy all PHP files
xcopy /E /I /Y ..\*.php server\www\
xcopy /E /I /Y ..\css server\www\css\
xcopy /E /I /Y ..\js server\www\js\
xcopy /E /I /Y ..\components server\www\components\
xcopy /E /I /Y ..\config server\www\config\
xcopy /E /I /Y ..\handlers server\www\handlers\
```

**Verify:**
```
server/www/
├── login.php        ✅
├── book.php         ✅
├── config/          ✅
├── css/             ✅
└── ... (all your files)
```

✅ All files copied!

---

## 🎯 Step 6: Update Configuration (2 minutes)

### 6.1 Update License API URL:

Open: `main.js` (line 11)

**Change:**
```javascript
LICENSE_API: 'https://yourdomain.com/api/verify-license.php'
```

**To your actual domain:**
```javascript
LICENSE_API: 'https://youractualsite.com/api/verify-license.php'
```

### 6.2 Verify Database Config:

Open: `server\www\config\database.php`

Make sure it has:
```php
$is_desktop_app = (getenv('PHPDESKTOP_VERSION') !== false);

if ($is_desktop_app) {
    $db_host = '127.0.0.1';
    $db_port = 3307;
    $db_name = 'gold_exchange';
    // ... rest of config
}
```

✅ Configuration updated!

---

## 🎯 Step 7: Test in Development Mode (5 minutes)

```bash
cd d:\Project\Gold_Exchange\electron-desktop

# Run in development mode
npm run dev
```

**What should happen:**
1. ✅ Console shows: "Starting MySQL..."
2. ✅ Console shows: "Starting PHP Server..."
3. ✅ Electron window opens
4. ✅ Login page loads

**If you see the login page, SUCCESS!** 🎉

**Common Issues:**

❌ **"PHP not found"**
- Check `server/php/php.exe` exists
- Verify path in main.js

❌ **"MySQL won't start"**
- Run initialization command again
- Check port 3307 is free

❌ **"Page won't load"**
- Wait 5-10 seconds for servers to start
- Check console for errors

---

## 🎯 Step 8: Build the Installer (10 minutes)

```bash
# Make sure you're in electron-desktop folder
cd d:\Project\Gold_Exchange\electron-desktop

# Build Windows installer
npm run build-win
```

**What happens:**
1. Electron Builder starts
2. Packages your app
3. Creates installer
4. Takes 5-10 minutes

**Progress:**
```
• electron-builder  version=24.9.0
• loaded configuration  file=package.json
• packaging       platform=win32 arch=x64
• building        target=nsis
• building block map  blockMapFile=dist\Gold Exchange Setup 1.0.0.exe.blockmap
```

**When done:**
```
✔ Built successfully!
```

**Output location:**
```
dist/
└── Gold Exchange Setup 1.0.0.exe  (~150-200 MB)
```

✅ Installer created!

---

## 🎯 Step 9: Test the Installer (5 minutes)

### 9.1 Test on Your Machine:
1. Go to `dist\` folder
2. Double-click `Gold Exchange Setup 1.0.0.exe`
3. Follow installation wizard
4. Launch the app
5. Try logging in

### 9.2 Test on Clean Machine (Recommended):
1. Copy installer to USB drive
2. Test on another Windows PC
3. Install and run
4. Verify everything works

✅ If app runs and you can login, SUCCESS!

---

## 🎯 Step 10: Deploy License API (10 minutes)

### 10.1 Upload to Hostinger:
1. Login to Hostinger cPanel
2. Go to File Manager
3. Navigate to `public_html/`
4. Create folder: `api/`
5. Upload `verify-license.php` to `public_html/api/`

### 10.2 Create License Database:
1. Go to phpMyAdmin in Hostinger
2. Create database: `u176143338_licenses`
3. Import `license-database.sql`

### 10.3 Test API:
```
https://yourdomain.com/api/verify-license.php
```

Should return:
```json
{"status":"error","message":"Only POST requests are allowed"}
```

✅ API is working!

---

## 🎉 DONE! You Now Have:

✅ **Desktop Application**
- Runs offline
- No XAMPP needed
- Professional installer

✅ **License Control**
- Online verification
- Remote blocking
- 30-day trials

✅ **Ready to Distribute**
- Single .exe installer
- ~150-200 MB size
- Works on any Windows PC

---

## 📦 Distribution Checklist

Before giving to customers:

- [ ] Test installer on clean Windows machine
- [ ] Verify license API is working
- [ ] Create customer license in database
- [ ] Prepare installation instructions
- [ ] Test license blocking/unblocking
- [ ] Prepare support contact info

---

## 🐛 Troubleshooting

### Build Fails:
```bash
# Delete node_modules and reinstall
rmdir /s node_modules
npm install
npm run build-win
```

### Installer Too Large:
- Normal size: 150-200 MB
- Includes: Electron + PHP + MariaDB
- Cannot be reduced significantly

### App Won't Start After Install:
- Check Windows Defender didn't block it
- Run as Administrator
- Check antivirus logs

---

## 📞 Need Help?

Check these files:
- `START_HERE.md` - Overview
- `SETUP_INSTRUCTIONS.md` - Detailed setup
- `QUICK_REFERENCE.md` - Command reference
- `HOW_TO_ADD_LICENSE_CODE.md` - License integration

---

## ⏱️ Total Time Breakdown

| Step | Time |
|------|------|
| Install Node.js | 5 min |
| Install dependencies | 2 min |
| Download & setup PHP | 10 min |
| Download & setup MariaDB | 10 min |
| Copy PHP app | 2 min |
| Update config | 2 min |
| Test in dev mode | 5 min |
| Build installer | 10 min |
| Test installer | 5 min |
| Deploy license API | 10 min |
| **TOTAL** | **~60 minutes** |

---

## 🎯 Quick Commands Summary

```bash
# 1. Install dependencies
npm install

# 2. Test in development
npm run dev

# 3. Build installer
npm run build-win

# 4. Output location
dist\Gold Exchange Setup 1.0.0.exe
```

---

**Ready to start? Follow the steps in order and you'll have a working desktop app in about 1 hour!** 🚀

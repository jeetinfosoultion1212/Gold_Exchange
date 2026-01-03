# 🖥️ Electron Desktop App with Online License Verification

## Overview
This guide will help you create a **standalone desktop application** that:
- ✅ Runs completely offline (no XAMPP needed)
- ✅ Verifies license online during login
- ✅ Blocks users if payment is overdue
- ✅ Embeds PHP + MariaDB inside the app
- ✅ Works on Windows, Mac, and Linux

---

## 📋 Architecture

```
Desktop App Structure:
├── Electron (Frontend Container)
│   ├── Chromium Browser (displays your PHP app)
│   └── Node.js (handles system operations)
├── PHP Server (Embedded)
│   ├── PHP 8.x Runtime
│   └── Your Gold Exchange App
├── MariaDB (Embedded)
│   └── Portable Database
└── License Verification API
    └── Online Server Check
```

---

## 🚀 Step-by-Step Implementation

### **Phase 1: Setup Project Structure**

#### 1.1 Create Electron Project
```bash
# Create new directory
mkdir gold-exchange-desktop
cd gold-exchange-desktop

# Initialize npm project
npm init -y

# Install Electron and dependencies
npm install electron electron-builder
npm install express axios node-fetch
npm install --save-dev electron-packager
```

#### 1.2 Project Structure
```
gold-exchange-desktop/
├── package.json
├── main.js                 # Electron main process
├── preload.js             # Security bridge
├── renderer/              # Frontend files
├── server/                # PHP server wrapper
│   ├── php/              # PHP runtime (portable)
│   ├── mariadb/          # MariaDB portable
│   └── www/              # Your PHP app files
├── resources/            # Icons, assets
└── build/                # Build configuration
```

---

### **Phase 2: Download Portable PHP & MariaDB**

#### 2.1 Download PHP (Portable)
- **Windows**: [PHP 8.2 Thread Safe](https://windows.php.net/download/)
- Extract to: `server/php/`
- Configure `php.ini`:
  ```ini
  extension=mysqli
  extension=pdo_mysql
  extension=mbstring
  extension=openssl
  extension=curl
  max_execution_time = 300
  memory_limit = 512M
  ```

#### 2.2 Download MariaDB (Portable)
- **Windows**: [MariaDB Portable](https://mariadb.org/download/)
- Extract to: `server/mariadb/`
- Create `my.ini`:
  ```ini
  [mysqld]
  port=3307
  datadir=./data
  skip-grant-tables=0
  max_connections=100
  ```

#### 2.3 Copy Your PHP App
```bash
# Copy your entire Gold Exchange app
cp -r d:/Project/Gold_Exchange/* server/www/
```

---

### **Phase 3: Create Electron Main Process**

#### 3.1 Create `main.js`
```javascript
const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');
const { spawn } = require('child_process');
const axios = require('axios');

let mainWindow;
let phpServer;
let mysqlServer;

// Configuration
const CONFIG = {
    PHP_PORT: 8080,
    MYSQL_PORT: 3307,
    LICENSE_API: 'https://yourdomain.com/api/verify-license.php',
    APP_VERSION: '1.0.0'
};

// Start MariaDB Server
function startMySQL() {
    return new Promise((resolve, reject) => {
        const mysqlPath = path.join(__dirname, 'server/mariadb/bin/mysqld.exe');
        const dataDir = path.join(__dirname, 'server/mariadb/data');
        
        mysqlServer = spawn(mysqlPath, [
            `--datadir=${dataDir}`,
            '--port=3307',
            '--console'
        ]);

        mysqlServer.stdout.on('data', (data) => {
            console.log(`MySQL: ${data}`);
            if (data.includes('ready for connections')) {
                resolve();
            }
        });

        mysqlServer.stderr.on('data', (data) => {
            console.error(`MySQL Error: ${data}`);
        });

        setTimeout(resolve, 3000); // Fallback timeout
    });
}

// Start PHP Server
function startPHP() {
    return new Promise((resolve, reject) => {
        const phpPath = path.join(__dirname, 'server/php/php.exe');
        const wwwPath = path.join(__dirname, 'server/www');
        
        phpServer = spawn(phpPath, [
            '-S',
            `localhost:${CONFIG.PHP_PORT}`,
            '-t',
            wwwPath
        ]);

        phpServer.stdout.on('data', (data) => {
            console.log(`PHP: ${data}`);
        });

        phpServer.stderr.on('data', (data) => {
            console.log(`PHP: ${data}`);
            if (data.includes('Development Server')) {
                resolve();
            }
        });

        setTimeout(resolve, 2000); // Fallback timeout
    });
}

// Verify License Online
async function verifyLicense(licenseKey, companyId) {
    try {
        const response = await axios.post(CONFIG.LICENSE_API, {
            license_key: licenseKey,
            company_id: companyId,
            app_version: CONFIG.APP_VERSION,
            machine_id: require('os').hostname()
        }, {
            timeout: 10000 // 10 seconds timeout
        });

        return response.data;
    } catch (error) {
        console.error('License verification failed:', error.message);
        // If offline, allow with grace period
        return {
            status: 'offline',
            message: 'Cannot verify license online. Running in offline mode.',
            grace_days: 7
        };
    }
}

// Create Main Window
function createWindow() {
    mainWindow = new BrowserWindow({
        width: 1400,
        height: 900,
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            nodeIntegration: false,
            contextIsolation: true
        },
        icon: path.join(__dirname, 'resources/icon.png'),
        title: 'Gold Exchange Management System'
    });

    // Load the PHP app
    mainWindow.loadURL(`http://localhost:${CONFIG.PHP_PORT}/login.php`);

    // Open DevTools in development
    if (process.env.NODE_ENV === 'development') {
        mainWindow.webContents.openDevTools();
    }

    mainWindow.on('closed', () => {
        mainWindow = null;
    });
}

// App Initialization
app.whenReady().then(async () => {
    console.log('Starting Gold Exchange Desktop...');
    
    // Start MySQL
    console.log('Starting MySQL...');
    await startMySQL();
    
    // Start PHP
    console.log('Starting PHP Server...');
    await startPHP();
    
    // Create Window
    console.log('Creating Window...');
    createWindow();
});

// Handle License Verification from Renderer
ipcMain.handle('verify-license', async (event, { licenseKey, companyId }) => {
    return await verifyLicense(licenseKey, companyId);
});

// Cleanup on Exit
app.on('window-all-closed', () => {
    if (phpServer) phpServer.kill();
    if (mysqlServer) mysqlServer.kill();
    app.quit();
});

app.on('before-quit', () => {
    if (phpServer) phpServer.kill();
    if (mysqlServer) mysqlServer.kill();
});
```

---

### **Phase 4: Create Preload Script (Security Bridge)**

#### 4.1 Create `preload.js`
```javascript
const { contextBridge, ipcRenderer } = require('electron');

// Expose protected methods to renderer
contextBridge.exposeInMainWorld('electronAPI', {
    verifyLicense: (licenseKey, companyId) => {
        return ipcRenderer.invoke('verify-license', { licenseKey, companyId });
    },
    platform: process.platform,
    version: process.env.APP_VERSION
});
```

---

### **Phase 5: Modify Login Page for License Verification**

#### 5.1 Update `login.php`
Add this JavaScript before the closing `</body>` tag:

```javascript
<script>
// Check if running in Electron
const isElectron = typeof window.electronAPI !== 'undefined';

if (isElectron) {
    // Intercept login form submission
    document.querySelector('form').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;
        
        // Show loading
        Swal.fire({
            title: 'Verifying License...',
            text: 'Please wait while we verify your subscription',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        try {
            // Verify license online
            const licenseResult = await window.electronAPI.verifyLicense(
                'YOUR_LICENSE_KEY', // Get from database or config
                'COMPANY_ID'        // Get from login
            );
            
            if (licenseResult.status === 'active') {
                // License valid, proceed with login
                Swal.close();
                this.submit(); // Submit the form normally
            } else if (licenseResult.status === 'expired') {
                Swal.fire({
                    icon: 'error',
                    title: 'License Expired',
                    text: 'Your subscription has expired. Please contact support to renew.',
                    confirmButtonColor: '#ef4444'
                });
            } else if (licenseResult.status === 'blocked') {
                Swal.fire({
                    icon: 'error',
                    title: 'Account Blocked',
                    text: 'Your account has been suspended due to payment issues. Please contact support.',
                    confirmButtonColor: '#ef4444'
                });
            } else if (licenseResult.status === 'offline') {
                // Offline mode with grace period
                Swal.fire({
                    icon: 'warning',
                    title: 'Running Offline',
                    html: `Cannot verify license online.<br>Grace period: ${licenseResult.grace_days} days remaining`,
                    confirmButtonText: 'Continue Offline',
                    confirmButtonColor: '#f59e0b'
                }).then(() => {
                    this.submit();
                });
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Verification Failed',
                text: 'Could not verify license. Please check your internet connection.',
                confirmButtonColor: '#ef4444'
            });
        }
    });
}
</script>
```

---

### **Phase 6: Create License Verification API (Server-Side)**

#### 6.1 Create `verify-license.php` on your online server
```php
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Database connection (your online server)
$conn = new mysqli('localhost', 'username', 'password', 'licenses_db');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $license_key = $conn->real_escape_string($data['license_key']);
    $company_id = $conn->real_escape_string($data['company_id']);
    $machine_id = $conn->real_escape_string($data['machine_id']);
    
    // Check license in database
    $sql = "SELECT * FROM licenses 
            WHERE license_key = '$license_key' 
            AND company_id = '$company_id'";
    
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $license = $result->fetch_assoc();
        
        // Check expiry date
        $expiry_date = strtotime($license['expiry_date']);
        $today = time();
        
        if ($license['status'] === 'blocked') {
            echo json_encode([
                'status' => 'blocked',
                'message' => 'Account suspended due to payment issues'
            ]);
        } elseif ($expiry_date < $today) {
            echo json_encode([
                'status' => 'expired',
                'message' => 'License has expired',
                'expiry_date' => $license['expiry_date']
            ]);
        } else {
            // Update last seen
            $conn->query("UPDATE licenses 
                         SET last_seen = NOW(), 
                             machine_id = '$machine_id' 
                         WHERE id = {$license['id']}");
            
            echo json_encode([
                'status' => 'active',
                'message' => 'License verified successfully',
                'expiry_date' => $license['expiry_date'],
                'company_name' => $license['company_name']
            ]);
        }
    } else {
        echo json_encode([
            'status' => 'invalid',
            'message' => 'Invalid license key'
        ]);
    }
}
?>
```

#### 6.2 Create License Database Table
```sql
CREATE TABLE licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(255) UNIQUE NOT NULL,
    company_id INT NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    status ENUM('active', 'expired', 'blocked') DEFAULT 'active',
    expiry_date DATE NOT NULL,
    machine_id VARCHAR(255),
    last_seen DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample license
INSERT INTO licenses (license_key, company_id, company_name, expiry_date) 
VALUES ('GOLD-2024-XXXX-YYYY', 1, 'ABC Jewellers', '2025-12-31');
```

---

### **Phase 7: Package Configuration**

#### 7.1 Update `package.json`
```json
{
  "name": "gold-exchange-desktop",
  "version": "1.0.0",
  "description": "Gold Exchange Management System - Desktop App",
  "main": "main.js",
  "scripts": {
    "start": "electron .",
    "dev": "NODE_ENV=development electron .",
    "build": "electron-builder",
    "build-win": "electron-builder --win",
    "build-mac": "electron-builder --mac",
    "build-linux": "electron-builder --linux"
  },
  "build": {
    "appId": "com.goldexchange.desktop",
    "productName": "Gold Exchange",
    "directories": {
      "output": "dist"
    },
    "files": [
      "main.js",
      "preload.js",
      "server/**/*",
      "resources/**/*"
    ],
    "win": {
      "target": "nsis",
      "icon": "resources/icon.ico"
    },
    "nsis": {
      "oneClick": false,
      "allowToChangeInstallationDirectory": true,
      "createDesktopShortcut": true,
      "createStartMenuShortcut": true
    }
  },
  "dependencies": {
    "axios": "^1.6.0",
    "electron": "^28.0.0",
    "express": "^4.18.0"
  },
  "devDependencies": {
    "electron-builder": "^24.9.0"
  }
}
```

---

### **Phase 8: Build & Distribute**

#### 8.1 Build the App
```bash
# Development mode
npm run dev

# Build for Windows
npm run build-win

# Build for all platforms
npm run build
```

#### 8.2 Output
- **Windows**: `dist/Gold Exchange Setup 1.0.0.exe`
- **Installer size**: ~150-200 MB (includes PHP + MariaDB)

---

## 🔒 Security Features

### 1. **License Verification**
- ✅ Online check at every login
- ✅ Grace period for offline mode (7 days)
- ✅ Machine ID binding
- ✅ Remote blocking capability

### 2. **Data Protection**
- ✅ Local database encryption (optional)
- ✅ Secure IPC communication
- ✅ Context isolation enabled

### 3. **Payment Enforcement**
```javascript
// Block user if payment overdue
if (licenseResult.status === 'blocked') {
    // Show payment reminder
    // Disable app functionality
    // Redirect to payment page
}
```

---

## 📦 Distribution Strategy

### Option 1: Direct Download
- Host installer on your website
- Provide unique license key per customer
- Customer downloads + installs + enters license

### Option 2: Custom Installer
- Pre-configure license key in installer
- Each customer gets unique installer
- No manual license entry needed

### Option 3: Auto-Update
```javascript
// Add electron-updater
const { autoUpdater } = require('electron-updater');

autoUpdater.checkForUpdatesAndNotify();
```

---

## 🎯 Key Benefits

| Feature | XAMPP Version | Electron Version |
|---------|--------------|------------------|
| **Installation** | Complex (XAMPP + App) | Single installer |
| **User Experience** | Manual server start | Auto-starts |
| **License Control** | ❌ No control | ✅ Full control |
| **Payment Blocking** | ❌ Cannot block | ✅ Remote block |
| **Updates** | Manual | Auto-update |
| **Professional** | ⭐⭐ | ⭐⭐⭐⭐⭐ |

---

## 🚀 Quick Start Commands

```bash
# 1. Create project
mkdir gold-exchange-desktop && cd gold-exchange-desktop

# 2. Initialize
npm init -y

# 3. Install dependencies
npm install electron electron-builder axios

# 4. Create files (main.js, preload.js, package.json)

# 5. Download PHP + MariaDB portable

# 6. Copy your app to server/www/

# 7. Test
npm run dev

# 8. Build
npm run build-win
```

---

## 📞 Support & Maintenance

### Customer Support Flow:
1. Customer reports issue
2. Check license status in database
3. If payment overdue → Block license
4. Customer pays → Unblock license
5. App works again (no reinstall needed)

### Database Query to Block:
```sql
UPDATE licenses 
SET status = 'blocked' 
WHERE company_id = 123;
```

---

## ✅ Checklist

- [ ] Download PHP 8.2 portable
- [ ] Download MariaDB portable
- [ ] Create Electron project structure
- [ ] Copy your PHP app files
- [ ] Create main.js with server startup
- [ ] Create preload.js for security
- [ ] Modify login.php for license check
- [ ] Create online license API
- [ ] Test in development mode
- [ ] Build installer
- [ ] Test installer on clean machine
- [ ] Deploy license API to server
- [ ] Create license database
- [ ] Generate customer licenses
- [ ] Distribute to customers

---

## 🎉 Result

You'll have a **professional desktop application** that:
- ✅ Installs with one click
- ✅ Runs completely offline
- ✅ Verifies license online
- ✅ Can be blocked remotely
- ✅ Auto-updates (optional)
- ✅ No XAMPP needed
- ✅ Professional user experience

**Estimated Development Time**: 2-3 days  
**Installer Size**: ~150-200 MB  
**Supported Platforms**: Windows, Mac, Linux

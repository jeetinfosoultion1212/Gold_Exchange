# Gold Exchange Desktop - Electron App

## 🚀 Quick Start

### 1. Install Dependencies
```bash
npm install
```

### 2. Setup PHP & MariaDB (Required)

#### Download Portable Versions:
- **PHP 8.2 (Thread Safe)**: https://windows.php.net/download/
- **MariaDB Portable**: https://mariadb.org/download/

#### Directory Structure:
```
electron-desktop/
├── server/
│   ├── php/              # Extract PHP here
│   │   ├── php.exe
│   │   ├── php.ini
│   │   └── ext/
│   ├── mariadb/          # Extract MariaDB here
│   │   ├── bin/
│   │   │   └── mysqld.exe
│   │   └── data/
│   └── www/              # Your PHP app files
│       ├── login.php
│       ├── book.php
│       └── ...
```

### 3. Copy Your PHP App
```bash
# Copy all files from Gold_Exchange to server/www/
xcopy /E /I ..\*.* server\www\
```

### 4. Configure PHP (php.ini)
Enable these extensions:
```ini
extension=mysqli
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=curl
max_execution_time = 300
memory_limit = 512M
```

### 5. Run in Development
```bash
npm run dev
```

### 6. Build Installer
```bash
npm run build-win
```

Output: `dist/Gold Exchange Setup 1.0.0.exe`

---

## 📁 Project Structure

```
electron-desktop/
├── main.js              # Main Electron process
├── preload.js           # Security bridge
├── package.json         # Dependencies & build config
├── server/
│   ├── php/            # PHP runtime (download separately)
│   ├── mariadb/        # MariaDB (download separately)
│   └── www/            # Your PHP application
├── resources/
│   └── icon.png        # App icon
└── dist/               # Built installers (after build)
```

---

## 🔧 Configuration

### Update License API URL
Edit `main.js` line 11:
```javascript
LICENSE_API: 'https://yourdomain.com/api/verify-license.php'
```

### Change Ports (if needed)
Edit `main.js` lines 9-10:
```javascript
PHP_PORT: 8080,
MYSQL_PORT: 3307,
```

---

## 🎯 Features

✅ Embedded PHP & MySQL servers
✅ Online license verification
✅ Offline mode with grace period
✅ Remote blocking capability
✅ Professional installer
✅ Auto-start servers
✅ No XAMPP required

---

## 📦 Build Commands

```bash
# Development mode (with DevTools)
npm run dev

# Build for Windows
npm run build-win

# Build for Mac
npm run build-mac

# Build for Linux
npm run build-linux

# Build for all platforms
npm run build
```

---

## 🐛 Troubleshooting

### PHP Server not starting
- Check if `server/php/php.exe` exists
- Verify php.ini has correct extensions enabled
- Check console for error messages

### MySQL not starting
- Check if `server/mariadb/bin/mysqld.exe` exists
- Verify `server/mariadb/data/` directory exists
- Check port 3307 is not in use

### License verification fails
- Check internet connection
- Verify LICENSE_API URL is correct
- Check server-side API is running

---

## 📝 Next Steps

1. ✅ Install dependencies: `npm install`
2. ⬇️ Download PHP & MariaDB portable
3. 📂 Copy your PHP app to `server/www/`
4. ⚙️ Configure php.ini
5. 🧪 Test: `npm run dev`
6. 🏗️ Build: `npm run build-win`
7. 📤 Distribute installer to customers

---

## 🔒 License Management

See `LICENSE_API_GUIDE.md` for setting up the online license verification server.

---

## 💡 Tips

- Keep installer size small by excluding unnecessary files
- Test on a clean Windows machine before distributing
- Use code signing certificate for production builds
- Enable auto-updates for easier maintenance

---

## 📞 Support

For issues or questions, check the main guide: `ELECTRON_DESKTOP_GUIDE.md`

# 📝 Quick Reference Card

## 🚀 Quick Commands

```bash
# Install dependencies
npm install

# Run in development mode
npm run dev

# Build installer for Windows
npm run build-win

# Build for all platforms
npm run build
```

---

## 📁 Project Structure

```
electron-desktop/
├── main.js                    # ⚙️ Main Electron process
├── preload.js                 # 🔒 Security bridge
├── package.json               # 📦 Dependencies & build config
├── server/
│   ├── php/                  # 🐘 PHP 8.2 runtime (download)
│   ├── mariadb/              # 🗄️ MariaDB database (download)
│   └── www/                  # 📂 Your PHP app files
├── resources/
│   └── icon.png              # 🎨 App icon
└── dist/                     # 📦 Built installers
```

---

## 🔧 Configuration

### Update License API
**File:** `main.js` (line 11)
```javascript
LICENSE_API: 'https://yourdomain.com/api/verify-license.php'
```

### Change Ports
**File:** `main.js` (lines 9-10)
```javascript
PHP_PORT: 8080,
MYSQL_PORT: 3307,
```

---

## 📥 Downloads Needed

1. **PHP 8.2 Thread Safe**
   - URL: https://windows.php.net/download/
   - Extract to: `server/php/`

2. **MariaDB Portable**
   - URL: https://mariadb.org/download/
   - Extract to: `server/mariadb/`

---

## ✅ Setup Checklist

- [ ] Run `npm install`
- [ ] Download & extract PHP to `server/php/`
- [ ] Configure `php.ini` (enable extensions)
- [ ] Download & extract MariaDB to `server/mariadb/`
- [ ] Initialize MariaDB database
- [ ] Copy PHP app to `server/www/`
- [ ] Update database config
- [ ] Add license code to login.php
- [ ] Test with `npm run dev`
- [ ] Deploy license API to server
- [ ] Update API URL in main.js
- [ ] Build installer with `npm run build-win`

---

## 🔒 License Management

### Block Customer
```sql
UPDATE licenses SET status = 'blocked' WHERE company_id = 123;
```

### Unblock Customer
```sql
UPDATE licenses SET status = 'active' WHERE company_id = 123;
```

### Extend License
```sql
UPDATE licenses SET expiry_date = DATE_ADD(expiry_date, INTERVAL 1 YEAR) WHERE company_id = 123;
```

---

## 🐛 Troubleshooting

| Issue | Solution |
|-------|----------|
| PHP not found | Check `server/php/php.exe` exists |
| MySQL won't start | Run `mysqld --initialize-insecure` |
| License fails | Check API URL and internet |
| Build fails | Delete `node_modules`, run `npm install` |

---

## 📚 Documentation Files

- `README.md` - Overview & quick start
- `SETUP_INSTRUCTIONS.md` - Detailed setup guide
- `ELECTRON_DESKTOP_GUIDE.md` - Complete reference
- `login-integration.js` - License verification code
- `verify-license.php` - Server-side API
- `license-database.sql` - Database schema

---

## 🎯 Key Features

✅ Embedded PHP & MySQL  
✅ Online license verification  
✅ Offline mode (7-day grace)  
✅ Remote blocking  
✅ Professional installer  
✅ No XAMPP needed  

---

## 📊 Build Output

**Installer:** `dist/Gold Exchange Setup 1.0.0.exe`  
**Size:** ~150-200 MB  
**Build Time:** 5-10 minutes  

---

## 🎉 Next Steps

1. Complete setup checklist
2. Test in development mode
3. Deploy license API
4. Build installer
5. Test on clean machine
6. Distribute to customers

---

**Need detailed help?** See `SETUP_INSTRUCTIONS.md`

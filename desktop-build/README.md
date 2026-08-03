# Desktop distribution build

## Quick build (recommended)

1. Install **XAMPP** on your build PC (default: `C:\xampp`)
2. Double-click **`CREATE_PORTABLE_APP.bat`** in the project root
3. Wait 2–5 minutes
4. Output:
   - `desktop_package/GoldExchangePortable/` — test folder
   - `desktop_package/GoldExchangePortable.zip` — give this to customers

## Test before shipping

1. Copy `GoldExchangePortable` to a **different folder** (not inside the project)
2. Run **`Start Gold Exchange.bat`**
3. Register a test company at `http://127.0.0.1:8080/register.php`
4. Run **`Stop Gold Exchange.bat`**
5. Run **Start** again — data should persist in the `data/` folder

## What customers do

1. Extract `GoldExchangePortable.zip`
2. Double-click **`Start Gold Exchange.bat`**
3. Register once, then use the app offline
4. **`Stop Gold Exchange.bat`** when done

No XAMPP install required on customer PC.

## Custom XAMPP path

```powershell
cd desktop-build
.\build_portable.ps1 -XamppPath "D:\tools\xampp"
```

## Package size

| Item | Approx size |
|------|-------------|
| ZIP (compressed) | 80–120 MB |
| Extracted folder | 250–350 MB |

## Optional: app window (no browser tabs)

After the portable package works, you can wrap it with **PHP Desktop** for a single `.exe`:

1. Download [PHP Desktop](https://github.com/cztomczak/phpdesktop/releases)
2. Copy `GoldExchangePortable/app` → `phpdesktop/www`
3. Copy `runtime/php` into phpdesktop
4. Configure `settings.json` to run MySQL start script + PHP server
5. PHP Desktop sets `PHPDESKTOP_VERSION` automatically

For most jeweller shops, the ZIP + batch file is enough.

## Silent printing (thermal / default printer)

The desktop package includes **SumatraPDF** and prints receipts with:

`-print-to-default -silent`

No browser print preview. Customer must set their receipt printer as **Windows default printer**.

To test silent print locally before building:

```powershell
$env:GOLD_EXCHANGE_DESKTOP = "1"
$env:GOLD_EXCHANGE_SUMATRA = "C:\path\to\SumatraPDF.exe"
$env:PHPDESKTOP_VERSION = "1.0"
cd C:\Users\HP\Downloads\Gold_Exchange
C:\xampp\php\php.exe -S 127.0.0.1:8080
```

Add `?preview=1` to any receipt URL to force the old print preview (debug only).

## Troubleshooting build

| Problem | Fix |
|---------|-----|
| XAMPP not found | Install XAMPP or pass `-XamppPath` |
| Port 3307 in use | Stop other MySQL; or change port in Start bat + `database.php` |
| Antivirus blocks mysqld | Add exclusion for the portable folder |
| Customer PC missing VC++ runtime | Install [Microsoft VC++ Redistributable](https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist) |

## Database

- Fresh install: empty `gold_exchange` DB created from `config/database_schamas.sql`
- Customer data: stored in `data/` — tell users to back up this folder

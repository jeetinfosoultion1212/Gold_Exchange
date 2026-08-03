# Gold Exchange — Electron Desktop

## Quick start (development)

1. Install **Node.js** (https://nodejs.org/)
2. Install **XAMPP** (PHP + MySQL)
3. From project root:

```bat
BUILD_ELECTRON_APP.bat
```

Or run dev mode without full installer build:

```bat
cd electron-desktop
npm install
npm run dev
```

This opens a **real Electron window** (not Chrome browser).

## Build installer for customers

```bat
BUILD_ELECTRON_APP.bat
```

Output:

- `electron-desktop/dist/GoldExchange-Setup-1.0.0.exe` — Windows installer
- Customer installs → desktop shortcut → runs offline

## Features

- Electron window (no browser tabs)
- Bundled PHP + MariaDB in production build
- **Silent print** to default printer via Electron (no preview dialog)
- Database stored in `%APPDATA%/gold-exchange-desktop/database`

## Dev vs production

| Mode | PHP www folder | MySQL data |
|------|----------------|------------|
| `npm run dev` | Project root | `electron-desktop/data/` |
| Installed app | `resources/server/www` | `%APPDATA%/.../database` |

## Portable Electron build (optional)

```bat
cd electron-desktop
npm run build:portable
```

Creates a single portable `.exe` in `dist/`.

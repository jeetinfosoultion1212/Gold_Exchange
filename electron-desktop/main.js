const { app, BrowserWindow, ipcMain, dialog } = require('electron');
const path = require('path');
const { spawn } = require('child_process');
const axios = require('axios');
const fs = require('fs');

let mainWindow;
let phpServer;
let mysqlServer;

// Configuration
const CONFIG = {
    PHP_PORT: 8080,
    MYSQL_PORT: 3307,
    LICENSE_API: 'https://yourdomain.com/api/verify-license.php', // UPDATE THIS
    APP_VERSION: '1.0.0',
    GRACE_PERIOD_DAYS: 7
};

// Get resource path (works in both dev and production)
function getResourcePath(relativePath) {
    if (app.isPackaged) {
        return path.join(process.resourcesPath, relativePath);
    }
    return path.join(__dirname, relativePath);
}

// Start MariaDB Server
function startMySQL() {
    return new Promise((resolve, reject) => {
        const isWin = process.platform === 'win32';
        const mysqlExe = isWin ? 'mysqld.exe' : 'mysqld';
        const mysqlPath = getResourcePath(path.join('server', 'mariadb', 'bin', mysqlExe));
        const dataDir = getResourcePath(path.join('server', 'mariadb', 'data'));

        console.log('MySQL Path:', mysqlPath);
        console.log('Data Dir:', dataDir);

        // Check if MySQL exists
        if (!fs.existsSync(mysqlPath)) {
            console.warn('MySQL not found. Skipping MySQL startup.');
            console.warn('Please download MariaDB portable and place in server/mariadb/');
            resolve(); // Continue without MySQL for now
            return;
        }

        mysqlServer = spawn(mysqlPath, [
            `--datadir=${dataDir}`,
            `--port=${CONFIG.MYSQL_PORT}`,
            '--console'
        ], {
            cwd: path.dirname(mysqlPath)
        });

        mysqlServer.stdout.on('data', (data) => {
            console.log(`[MySQL] ${data}`);
            if (data.toString().includes('ready for connections')) {
                console.log('✅ MySQL ready!');
                resolve();
            }
        });

        mysqlServer.stderr.on('data', (data) => {
            console.error(`[MySQL Error] ${data}`);
        });

        mysqlServer.on('error', (err) => {
            console.error('MySQL spawn error:', err);
            resolve(); // Continue anyway
        });

        // Fallback timeout
        setTimeout(() => {
            console.log('MySQL startup timeout - continuing anyway');
            resolve();
        }, 5000);
    });
}

// Start PHP Server
function startPHP() {
    return new Promise((resolve, reject) => {
        const isWin = process.platform === 'win32';
        const phpExe = isWin ? 'php.exe' : 'php';
        const phpPath = getResourcePath(path.join('server', 'php', phpExe));
        const wwwPath = getResourcePath(path.join('server', 'www'));

        console.log('PHP Path:', phpPath);
        console.log('WWW Path:', wwwPath);

        // Check if PHP exists
        if (!fs.existsSync(phpPath)) {
            console.error('❌ PHP not found at:', phpPath);
            dialog.showErrorBox(
                'PHP Not Found',
                'PHP runtime is missing. Please ensure PHP is installed in server/php/ directory.'
            );
            reject(new Error('PHP not found'));
            return;
        }

        phpServer = spawn(phpPath, [
            '-S',
            `localhost:${CONFIG.PHP_PORT}`,
            '-t',
            wwwPath
        ], {
            cwd: wwwPath,
            env: { ...process.env, PHPDESKTOP_VERSION: '1.0' }
        });

        phpServer.stdout.on('data', (data) => {
            console.log(`[PHP] ${data}`);
        });

        phpServer.stderr.on('data', (data) => {
            const output = data.toString();
            console.log(`[PHP] ${output}`);
            if (output.includes('Development Server')) {
                console.log('✅ PHP Server ready!');
                resolve();
            }
        });

        phpServer.on('error', (err) => {
            console.error('PHP spawn error:', err);
            reject(err);
        });

        // Fallback timeout
        setTimeout(() => {
            console.log('PHP startup timeout - continuing anyway');
            resolve();
        }, 3000);
    });
}

// Verify License Online
async function verifyLicense(licenseKey, companyId) {
    try {
        console.log('Verifying license...', { licenseKey, companyId });

        const response = await axios.post(CONFIG.LICENSE_API, {
            license_key: licenseKey,
            company_id: companyId,
            app_version: CONFIG.APP_VERSION,
            machine_id: require('os').hostname(),
            platform: process.platform
        }, {
            timeout: 10000, // 10 seconds
            headers: {
                'Content-Type': 'application/json'
            }
        });

        console.log('License verification response:', response.data);
        return response.data;

    } catch (error) {
        console.error('License verification failed:', error.message);

        // If offline or server unreachable, allow with grace period
        return {
            status: 'offline',
            message: 'Cannot verify license online. Running in offline mode.',
            grace_days: CONFIG.GRACE_PERIOD_DAYS,
            offline: true
        };
    }
}

// Create Main Window
function createWindow() {
    mainWindow = new BrowserWindow({
        width: 1400,
        height: 900,
        minWidth: 1200,
        minHeight: 700,
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            nodeIntegration: false,
            contextIsolation: true,
            webSecurity: true
        },
        icon: path.join(__dirname, 'resources', 'icon.png'),
        title: 'Gold Exchange Management System',
        backgroundColor: '#f5f7fa',
        show: false // Don't show until ready
    });

    // Show window when ready
    mainWindow.once('ready-to-show', () => {
        mainWindow.show();
    });

    // Load the PHP app
    const appUrl = `http://localhost:${CONFIG.PHP_PORT}/login.php`;
    console.log('Loading app from:', appUrl);

    mainWindow.loadURL(appUrl).catch(err => {
        console.error('Failed to load app:', err);
        dialog.showErrorBox(
            'Failed to Load',
            'Could not load the application. Please check if PHP server is running.'
        );
    });

    // Open DevTools in development
    if (process.env.NODE_ENV === 'development') {
        mainWindow.webContents.openDevTools();
    }

    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    // Handle navigation
    mainWindow.webContents.on('will-navigate', (event, url) => {
        // Allow navigation only within the app
        if (!url.startsWith(`http://localhost:${CONFIG.PHP_PORT}`)) {
            event.preventDefault();
        }
    });
}

// App Initialization
app.whenReady().then(async () => {
    console.log('🚀 Starting Gold Exchange Desktop...');
    console.log('Platform:', process.platform);
    console.log('App Path:', app.getAppPath());
    console.log('Is Packaged:', app.isPackaged);

    try {
        // Start MySQL
        console.log('📦 Starting MySQL...');
        await startMySQL();

        // Start PHP
        console.log('🐘 Starting PHP Server...');
        await startPHP();

        // Wait a bit for servers to stabilize
        await new Promise(resolve => setTimeout(resolve, 1000));

        // Create Window
        console.log('🪟 Creating Window...');
        createWindow();

        console.log('✅ Application started successfully!');

    } catch (error) {
        console.error('❌ Failed to start application:', error);
        dialog.showErrorBox(
            'Startup Failed',
            `Failed to start the application: ${error.message}`
        );
        app.quit();
    }
});

// Handle License Verification from Renderer
ipcMain.handle('verify-license', async (event, { licenseKey, companyId }) => {
    return await verifyLicense(licenseKey, companyId);
});

// Get app info
ipcMain.handle('get-app-info', () => {
    return {
        version: CONFIG.APP_VERSION,
        platform: process.platform,
        isPackaged: app.isPackaged
    };
});

// Cleanup on Exit
app.on('window-all-closed', () => {
    console.log('All windows closed');
    if (phpServer) {
        console.log('Stopping PHP server...');
        phpServer.kill();
    }
    if (mysqlServer) {
        console.log('Stopping MySQL server...');
        mysqlServer.kill();
    }
    app.quit();
});

app.on('before-quit', () => {
    console.log('App quitting...');
    if (phpServer) phpServer.kill();
    if (mysqlServer) mysqlServer.kill();
});

// Handle errors
process.on('uncaughtException', (error) => {
    console.error('Uncaught Exception:', error);
});

process.on('unhandledRejection', (error) => {
    console.error('Unhandled Rejection:', error);
});

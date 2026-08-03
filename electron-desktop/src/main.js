const { app, BrowserWindow, ipcMain, Menu, shell } = require('electron');
const path = require('path');
const fs = require('fs');
const { spawn, execFile } = require('child_process');
const net = require('net');
const http = require('http');

const APP_PORT = 8080;
const DB_PORT = 3307;
const APP_URL = `http://127.0.0.1:${APP_PORT}/login.php`;
const SESSION_PARTITION = 'persist:goldexchange';

const isDev = process.argv.includes('--dev') || !app.isPackaged;

let mainWindow = null;
let phpProcess = null;
let mysqlProcess = null;

function getPaths() {
  if (isDev) {
    const projectRoot = path.resolve(__dirname, '..', '..');
    return {
      root: projectRoot,
      www: projectRoot,
      php: process.env.XAMPP_PHP || 'C:\\xampp\\php\\php.exe',
      mysql: process.env.XAMPP_MYSQL || 'C:\\xampp\\mysql\\bin\\mysqld.exe',
      mysqlInstall: process.env.XAMPP_MYSQL_INSTALL || 'C:\\xampp\\mysql\\bin\\mysql_install_db.exe',
      mysqlAdmin: process.env.XAMPP_MYSQL_ADMIN || 'C:\\xampp\\mysql\\bin\\mysqladmin.exe',
      data: path.join(projectRoot, 'electron-desktop', 'data'),
    };
  }

  const serverRoot = path.join(process.resourcesPath, 'server');
  return {
    root: serverRoot,
    www: path.join(serverRoot, 'www'),
    php: path.join(serverRoot, 'php', 'php.exe'),
    mysql: path.join(serverRoot, 'mysql', 'bin', 'mysqld.exe'),
    mysqlInstall: path.join(serverRoot, 'mysql', 'bin', 'mysql_install_db.exe'),
    mysqlAdmin: path.join(serverRoot, 'mysql', 'bin', 'mysqladmin.exe'),
    data: path.join(app.getPath('userData'), 'database'),
  };
}

function serverEnv() {
  return {
    ...process.env,
    PHPDESKTOP_VERSION: '1.0',
    GOLD_EXCHANGE_DESKTOP: '1',
    GOLD_EXCHANGE_DB_PORT: String(DB_PORT),
  };
}

function waitForPort(port, host = '127.0.0.1', timeoutMs = 30000) {
  const started = Date.now();
  return new Promise((resolve, reject) => {
    const tryConnect = () => {
      const socket = net.createConnection({ port, host }, () => {
        socket.end();
        resolve(true);
      });
      socket.on('error', () => {
        socket.destroy();
        if (Date.now() - started > timeoutMs) {
          reject(new Error(`Timed out waiting for ${host}:${port}`));
          return;
        }
        setTimeout(tryConnect, 500);
      });
    };
    tryConnect();
  });
}

function pingMysql(adminExe) {
  return new Promise((resolve) => {
    execFile(adminExe, ['-u', 'root', '-h', '127.0.0.1', '-P', String(DB_PORT), 'ping'], { windowsHide: true }, (err) => {
      resolve(!err);
    });
  });
}

async function initDatabase(paths) {
  if (!fs.existsSync(paths.data)) {
    fs.mkdirSync(paths.data, { recursive: true });
  }

  const iniPath = path.join(paths.data, 'my.ini');
  const dataMysql = path.join(paths.data, 'mysql');
  if (!fs.existsSync(dataMysql)) {
    await new Promise((resolve, reject) => {
      const proc = spawn(paths.mysqlInstall, [`--datadir=${paths.data}`, '-P', String(DB_PORT)], {
        cwd: paths.root,
        windowsHide: true,
        stdio: 'ignore',
      });
      proc.on('exit', (code) => (code === 0 ? resolve() : reject(new Error(`mysql_install_db failed (${code})`))));
      proc.on('error', reject);
    });
  }

  if (fs.existsSync(iniPath) && !fs.readFileSync(iniPath, 'utf8').includes('basedir=')) {
    const basedir = paths.mysql.replace(/\\bin\\mysqld.exe$/i, '').replace(/\\/g, '/');
    fs.appendFileSync(iniPath, `\nbasedir=${basedir}\n`);
  }

  const alive = await pingMysql(paths.mysqlAdmin);
  if (alive) {
    return;
  }

  mysqlProcess = spawn(paths.mysql, [`--defaults-file=${iniPath}`, '--standalone'], {
    cwd: paths.root,
    env: serverEnv(),
    windowsHide: true,
    stdio: 'ignore',
  });
  mysqlProcess.on('error', (err) => console.error('MySQL error:', err));

  await waitForPort(DB_PORT);
}

async function startPhpServer(paths) {
  const listening = await new Promise((resolve) => {
    const req = http.get({ hostname: '127.0.0.1', port: APP_PORT, path: '/login.php', timeout: 1000 }, (res) => {
      res.resume();
      resolve(true);
    });
    req.on('error', () => resolve(false));
    req.on('timeout', () => {
      req.destroy();
      resolve(false);
    });
  });

  if (listening) {
    return;
  }

  phpProcess = spawn(paths.php, ['-S', `127.0.0.1:${APP_PORT}`, '-t', paths.www], {
    cwd: paths.www,
    env: serverEnv(),
    windowsHide: true,
    stdio: 'ignore',
  });
  phpProcess.on('error', (err) => console.error('PHP error:', err));

  await waitForPort(APP_PORT);
}

function stopServers() {
  if (phpProcess) {
    phpProcess.kill();
    phpProcess = null;
  }
  const paths = getPaths();
  if (fs.existsSync(paths.mysqlAdmin)) {
    execFile(paths.mysqlAdmin, ['-u', 'root', '-h', '127.0.0.1', '-P', String(DB_PORT), 'shutdown'], { windowsHide: true }, () => {});
  }
  if (mysqlProcess) {
    mysqlProcess.kill();
    mysqlProcess = null;
  }
}

function printInHiddenWindow(targetUrl, html) {
  return new Promise((resolve) => {
    const printWin = new BrowserWindow({
      show: false,
      webPreferences: {
        partition: SESSION_PARTITION,
        contextIsolation: true,
        nodeIntegration: false,
      },
    });

    const cleanup = (result) => {
      if (!printWin.isDestroyed()) {
        printWin.close();
      }
      resolve(result);
    };

    printWin.webContents.on('did-fail-load', () => cleanup({ ok: false, error: 'Failed to load receipt for printing.' }));

    if (html) {
      printWin.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`);
    } else {
      printWin.loadURL(targetUrl);
    }

    printWin.webContents.once('did-finish-load', () => {
      setTimeout(() => {
        printWin.webContents.print(
          {
            silent: true,
            printBackground: true,
            deviceName: '',
          },
          (success, failureReason) => {
            cleanup({
              ok: !!success,
              error: success ? null : failureReason || 'Print failed',
            });
          }
        );
      }, html ? 400 : 700);
    });
  });
}

async function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1360,
    height: 900,
    minWidth: 1024,
    minHeight: 700,
    title: 'Gold Exchange',
    autoHideMenuBar: true,
    icon: path.join(__dirname, '..', 'assets', 'icon.ico'),
    webPreferences: {
      partition: SESSION_PARTITION,
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false,
    },
  });

  Menu.setApplicationMenu(null);

  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith(`http://127.0.0.1:${APP_PORT}`) || url.startsWith('http://localhost:')) {
      return {
        action: 'allow',
        overrideBrowserWindowOptions: {
          autoHideMenuBar: true,
          webPreferences: {
            partition: SESSION_PARTITION,
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
          },
        },
      };
    }
    shell.openExternal(url);
    return { action: 'deny' };
  });

  await mainWindow.loadURL(APP_URL);
}

app.whenReady().then(async () => {
  try {
    const paths = getPaths();
    if (!fs.existsSync(paths.php)) {
      throw new Error(`PHP not found at ${paths.php}. Run BUILD_ELECTRON_APP.bat or install XAMPP.`);
    }
    if (!fs.existsSync(paths.mysql)) {
      throw new Error(`MySQL not found at ${paths.mysql}. Run BUILD_ELECTRON_APP.bat or install XAMPP.`);
    }

    await initDatabase(paths);
    await startPhpServer(paths);
    await createMainWindow();
  } catch (err) {
    console.error(err);
    app.exit(1);
  }
});

app.on('window-all-closed', () => {
  stopServers();
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

app.on('before-quit', () => {
  stopServers();
});

ipcMain.handle('print-receipt', async (_event, relativeUrl) => {
  const url = relativeUrl.startsWith('http')
    ? relativeUrl
    : `http://127.0.0.1:${APP_PORT}/${relativeUrl.replace(/^\//, '')}`;
  return printInHiddenWindow(url, null);
});

ipcMain.handle('print-html', async (_event, html) => {
  return printInHiddenWindow(null, html);
});

# 🚀 Gold Exchange - Deployment Guide

This guide will help you host your Gold Exchange application on a live web server (cPanel, VPS, etc.).

## ✅ Prerequisites

1.  **Domain & Hosting**: You need a hosting provider (like Hostinger, GoDaddy, Bluehost) with **cPanel** or similar.
2.  **PHP Version**: Ensure your hosting supports **PHP 8.0 or higher**.
3.  **Database**: MySQL or MariaDB.

---

## 🛠️ Step 1: Prepare Your Files (One-Click)

We have created a script to automatically package your application.

1.  Open your terminal/command prompt.
2.  Run the following command:
    ```powershell
    ./helpers/prepare_deployment.ps1
    ```
3.  This will create two files in your project folder:
    *   `deployment_package.zip`: Contains all your code.
    *   `latest_db_dump.sql`: Your database backup.

> **Note**: If the script fails to export the database (e.g., due to password issues), generic manually export it:
> 1. Go to `http://localhost/phpmyadmin`
> 2. Select `gold_exchange` database.
> 3. Click **Export** > **Quick** > **Go**.
> 4. Save the file as `latest_db_dump.sql`.

---

## ☁️ Step 2: Upload to Server

1.  Log in to your **Hosting Control Panel (cPanel)**.
2.  Go to **File Manager**.
3.  Navigate to `public_html` (or the subdomain folder where you want the site).
4.  **Upload** the `deployment_package.zip` file.
5.  Right-click the zip file and select **Extract**.
6.  (Optional) Rename `latest_db_dump.sql` to something hard to guess if you uploaded it, or delete it after importing.

---

## 🗄️ Step 3: Setup Database

1.  In cPanel, go to **MySQL Database Wizard**.
2.  **Create a Database** (e.g., `u123_gold_db`).
3.  **Create a User** (e.g., `u123_gold_user`) and set a strong password.
4.  **Add User to Database** and check **ALL PRIVILEGES**.
    *   ⚠️ **Write down these details:** Database Name, Username, Password.
5.  Go to **phpMyAdmin** in cPanel.
6.  Select your new database.
7.  Click **Import**.
8.  Choose your `latest_db_dump.sql` file and click **Go**.

---

## ⚙️ Step 4: Configure Connection

1.  In File Manager, find `config.php` in your root folder.
2.  Right-click and **Edit**.
3.  Look for the `Web / Server Settings` section (around line 20).
4.  Update the values with your **Step 3** details:

    ```php
    } else {
        // Web / Server Settings (Default)
        if (!defined('DB_HOST')) define('DB_HOST', 'localhost'); // Usually localhost
        if (!defined('DB_NAME')) define('DB_NAME', 'YOUR_DATABASE_NAME'); // e.g. u123_gold_db
        if (!defined('DB_USER')) define('DB_USER', 'YOUR_DB_USER');      // e.g. u123_gold_user
        if (!defined('DB_PASS')) define('DB_PASS', 'YOUR_STRONG_PASSWORD');
    }
    ```

5.  **Save Changes**.

---

## 🌐 Step 5: Start Using!

1.  Open your domain in a browser (e.g., `https://your-site.com`).
2.  Login with your existing admin credentials.

---

## 🖥️ (Optional) Connect Desktop App to Live Server

If you want your Desktop Application to connect to this live website instead of its local database:

1.  Open `electron-desktop/main.js` (you will need to rebuild the app).
2.  Find the `createWindow` function.
3.  Change:
    ```javascript
    const appUrl = `http://localhost:${CONFIG.PHP_PORT}/login.php`;
    ```
    To:
    ```javascript
    const appUrl = 'https://your-site.com/login.php';
    ```
4.  Remove the `startPHP()` and `startMySQL()` calls in the startup sequence since you don't need them anymore.
5.  Rebuild the desktop app using `npm run build`.

**Need Help?**
If you see a "Connection Failed" error, check your `config.php` credentials again.

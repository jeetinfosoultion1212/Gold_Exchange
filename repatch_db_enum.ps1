$content = "<?php`r`n" +
"// Database Configuration (Patched + Enum Fix)`r`n" +
"`$is_desktop_app = true;`r`n" +
"`$db_host = '127.0.0.1';`r`n" +
"`$db_name = 'gold_exchange';`r`n" +
"`$db_user = 'root';`r`n" +
"`$db_pass = '';`r`n" +
"`$db_port = 3307;`r`n" +
"`r`n" +
"mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);`r`n" +
"`r`n" +
"try {`r`n" +
"    `$conn = new mysqli(`$db_host, `$db_user, `$db_pass, `$db_name, `$db_port);`r`n" +
"} catch (mysqli_sql_exception `$e) {`r`n" +
"    if (`$e->getCode() === 1049) {`r`n" +
"        try {`r`n" +
"            `$conn = new mysqli(`$db_host, `$db_user, `$db_pass, null, `$db_port);`r`n" +
"            `$conn->query('CREATE DATABASE IF NOT EXISTS ' . `$db_name);`r`n" +
"            `$conn->select_db(`$db_name);`r`n" +
"            `$schema_path = dirname(__DIR__) . '/auto_fix_schema.php';`r`n" +
"            if (file_exists(`$schema_path)) {`r`n" +
"                ob_start();`r`n" +
"                require `$schema_path;`r`n" +
"                ob_end_clean();`r`n" +
"            }`r`n" +
"        } catch (Exception `$ex) {`r`n" +
"            die('DB Init Failed: ' . `$ex->getMessage());`r`n" +
"        }`r`n" +
"    } else {`r`n" +
"        die('Connection Failed: ' . `$e->getMessage());`r`n" +
"    }`r`n" +
"}`r`n" +
"`r`n" +
"if (!`$conn->set_charset('utf8mb4')) { error_log('Error setting charset: ' . `$conn->error); }`r`n" +
"`r`n" +
"// AUTO-FIX ENUM TYPES (Run on every connection logic check)`r`n" +
"try {`r`n" +
"    `$enum_check = `$conn->query(\"SHOW COLUMNS FROM transactions LIKE 'transaction_type'\");`r`n" +
"    if (`$enum_check) {`r`n" +
"        `$row = `$enum_check->fetch_assoc();`r`n" +
"        if (stripos(`$row['Type'], 'Stock_Addition') === false) {`r`n" +
"            `$conn->query(\"ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM('Booking','Sale','Purchase','Received','Payment','Gold_Received','Exchange','Stock_Addition','Stock_Reset','Transaction_Deleted') NOT NULL\");`r`n" +
"        }`r`n" +
"    }`r`n" +
"} catch (Exception `$e) { /* Ignore enum check errors */ }`r`n" +
"?>"

$path = "C:\Users\ACER\AppData\Local\Programs\Gold Exchange\resources\server\www\config\database.php"
[System.IO.File]::WriteAllText($path, $content)
Write-Host "Repatched database.php with Enum fix."

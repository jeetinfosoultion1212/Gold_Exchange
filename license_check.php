<?php
// license_check.php
// Prevents unauthorized use by locking the app to the specific machine hardware.

function getMachineUUID() {
    // Get Windows Machine GUID
    $output = [];
    exec('wmic csproduct get uuid', $output);
    // Output format is usually:
    // UUID
    // XXXXXX-XXXX-XXXX-XXXX-XXXXXX
    if (isset($output[1])) {
        return trim($output[1]);
    }
    return 'UNKNOWN_MACHINE_ID';
}

function verifyLicense() {
    $licenseFile = __DIR__ . '/license.lic';
    $secretSalt = 'MORMUKUT_GOLD_2025_SECURE_SALT'; // Change this to something secret
    $machineUUID = getMachineUUID();
    
    // Calculate expected signature
    $expectedSignature = hash_hmac('sha256', $machineUUID, $secretSalt);
    
    // Check if license file exists
    if (!file_exists($licenseFile)) {
        showLicenseScreen($machineUUID, "License file missing.");
        exit;
    }
    
    // Read license file
    $licenseKey = trim(file_get_contents($licenseFile));
    
    if ($licenseKey !== $expectedSignature) {
        showLicenseScreen($machineUUID, "Invalid License Key for this machine.");
        exit;
    }
    
    // License OK
    return true;
}

function showLicenseScreen($uuid, $error) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Software Activation Required</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 500px; width: 100%; text-align: center; }
            h2 { color: #d32f2f; margin-top: 0; }
            .uuid-box { background: #eee; padding: 15px; font-family: monospace; font-size: 1.2rem; letter-spacing: 1px; border-radius: 6px; margin: 20px 0; border: 1px dashed #999; word-break: break-all; }
            p { color: #555; line-height: 1.5; }
            .contact { font-weight: bold; color: #333; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>Activation Required</h2>
            <p>This software is protected and locked to this computer.</p>
            <p style="color: red; font-weight: bold;"><?= htmlspecialchars($error) ?></p>
            
            <p>To use this application, please send the Code below to your vendor:</p>
            
            <div class="uuid-box">
                <?= htmlspecialchars($uuid) ?>
            </div>
            
            <p class="contact">Contact Administrator for Unlock Key</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Run the check
verifyLicense();
?>

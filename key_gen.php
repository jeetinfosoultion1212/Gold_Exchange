<?php
// key_gen.php - INTERNAL TOOL FOR DEVELOPER ONLY
// DO NOT DISTRIBUTE THIS FILE TO CLIENTS

if (isset($_POST['machine_id'])) {
    $machineUUID = trim($_POST['machine_id']);
    $secretSalt = 'MORMUKUT_GOLD_2025_SECURE_SALT'; // Must match license_check.php
    $licenseKey = hash_hmac('sha256', $machineUUID, $secretSalt);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>License Key Generator</title>
    <style>body { font-family: sans-serif; padding: 20px; }</style>
</head>
<body>
    <h2>License Key Generator</h2>
    <form method="POST">
        <label>Enter Client Machine ID:</label><br>
        <input type="text" name="machine_id" style="width: 300px; padding: 10px;" required placeholder="XXXX-XXXX-XXXX"><br><br>
        <button type="submit" style="padding: 10px 20px;">Generate Key</button>
    </form>
    
    <?php if (isset($licenseKey)): ?>
        <h3>Generated License Key:</h3>
        <textarea style="width: 100%; height: 60px;"><?= $licenseKey ?></textarea>
        <p>Copy this key into a file named <b>license.lic</b> and place it in the application folder.</p>
    <?php endif; ?>
</body>
</html>

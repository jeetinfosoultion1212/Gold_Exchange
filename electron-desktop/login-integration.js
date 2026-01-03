/**
 * Add this JavaScript code to your login.php file
 * Place it before the closing </body> tag inside <script> tags
 * 
 * This enables license verification when running in Electron desktop app
 */

// Check if running in Electron desktop app
const isElectron = typeof window.electronAPI !== 'undefined';

if (isElectron) {
    console.log('✅ Running in Electron Desktop App');

    // Get app info
    window.electronAPI.getAppInfo().then(info => {
        console.log('App Info:', info);
    });

    // Intercept form submission for license verification
    const loginForm = document.querySelector('form');
    const originalSubmit = loginForm.onsubmit;

    loginForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        e.stopPropagation();

        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;

        if (!username || !password) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Fields',
                text: 'Please enter username and password',
                confirmButtonColor: '#3b82f6'
            });
            return false;
        }

        // Show license verification loading
        Swal.fire({
            title: 'Verifying License...',
            html: 'Please wait while we verify your subscription<br><small>This requires internet connection</small>',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        try {
            // Get license key and company ID from your database or config
            // For now, using demo values - UPDATE THIS
            const licenseKey = 'GOLD-2024-DEMO-0001'; // TODO: Get from database
            const companyId = 1; // TODO: Get from user/company

            // Verify license with online server
            const licenseResult = await window.electronAPI.verifyLicense(licenseKey, companyId);

            console.log('License verification result:', licenseResult);

            // Handle different license statuses
            if (licenseResult.status === 'active') {
                // License is valid - proceed with login
                Swal.close();

                // Show success message with expiry info
                const daysRemaining = licenseResult.days_remaining || 0;
                let expiryMessage = '';

                if (daysRemaining <= 7) {
                    expiryMessage = `<br><small class="text-warning">⚠️ License expires in ${daysRemaining} days</small>`;
                } else if (daysRemaining <= 30) {
                    expiryMessage = `<br><small class="text-info">License expires in ${daysRemaining} days</small>`;
                }

                Swal.fire({
                    icon: 'success',
                    title: 'License Verified',
                    html: `Welcome ${licenseResult.company_name || 'User'}!${expiryMessage}`,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    // Submit the form normally
                    loginForm.submit();
                });

            } else if (licenseResult.status === 'expired') {
                // License has expired
                Swal.fire({
                    icon: 'error',
                    title: 'License Expired',
                    html: `
                        <p>Your subscription has expired on <strong>${licenseResult.expiry_date}</strong></p>
                        <p>Please contact support to renew your license.</p>
                        <hr>
                        <p><small>📧 Email: support@yourdomain.com</small></p>
                        <p><small>📞 Phone: +91-XXXXXXXXXX</small></p>
                    `,
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'Contact Support'
                });

            } else if (licenseResult.status === 'blocked') {
                // Account blocked due to payment issues
                Swal.fire({
                    icon: 'error',
                    title: 'Account Suspended',
                    html: `
                        <p><strong>Your account has been suspended.</strong></p>
                        <p>${licenseResult.message || 'Payment pending or overdue.'}</p>
                        <hr>
                        <p>Please contact our support team to resolve this issue:</p>
                        <p><small>📧 ${licenseResult.support_email || 'support@yourdomain.com'}</small></p>
                        <p><small>📞 ${licenseResult.support_phone || '+91-XXXXXXXXXX'}</small></p>
                    `,
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'OK',
                    footer: '<small>Your data is safe and will be restored once payment is received.</small>'
                });

            } else if (licenseResult.status === 'offline') {
                // Cannot verify online - running in offline mode
                const graceDays = licenseResult.grace_days || 7;

                Swal.fire({
                    icon: 'warning',
                    title: 'Running in Offline Mode',
                    html: `
                        <p>Cannot verify license online.</p>
                        <p>You can continue using the app for <strong>${graceDays} days</strong>.</p>
                        <p><small>Please connect to the internet to verify your license.</small></p>
                    `,
                    confirmButtonText: 'Continue Offline',
                    confirmButtonColor: '#f59e0b',
                    showCancelButton: true,
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Allow login in offline mode
                        loginForm.submit();
                    }
                });

            } else if (licenseResult.status === 'invalid') {
                // Invalid license key
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid License',
                    html: `
                        <p>Your license key is invalid or not found.</p>
                        <p>Please contact support for assistance.</p>
                        <hr>
                        <p><small>📧 ${licenseResult.support_email || 'support@yourdomain.com'}</small></p>
                    `,
                    confirmButtonColor: '#ef4444'
                });

            } else {
                // Unknown status
                Swal.fire({
                    icon: 'error',
                    title: 'Verification Failed',
                    text: licenseResult.message || 'Unknown error occurred',
                    confirmButtonColor: '#ef4444'
                });
            }

        } catch (error) {
            console.error('License verification error:', error);

            // Error during verification - allow offline mode
            Swal.fire({
                icon: 'warning',
                title: 'Verification Failed',
                html: `
                    <p>Could not verify license online.</p>
                    <p><small>${error.message || 'Please check your internet connection.'}</small></p>
                    <p>Continue in offline mode?</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'Continue Offline',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#f59e0b',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (result.isConfirmed) {
                    loginForm.submit();
                }
            });
        }

        return false;
    }, true);

} else {
    console.log('ℹ️ Running in Web Browser (not Electron)');
}


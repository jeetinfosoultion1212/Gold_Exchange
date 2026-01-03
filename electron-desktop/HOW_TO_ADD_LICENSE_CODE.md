# How to Add License Verification to Login Page

## ⚠️ Important Note

The file `login-integration.js` is a **code template**, not a standalone JavaScript file. 
The lint errors you see are expected because it contains HTML template strings.

---

## 📝 Instructions

### Step 1: Open your login.php file
```
server/www/login.php
```

### Step 2: Find the closing `</body>` tag
Look for this near the end of the file:
```html
    </script>
</body>
</html>
```

### Step 3: Add the license verification code

**BEFORE** the `</body>` tag, add:

```html
<script>
// Copy the ENTIRE content from login-integration.js here
// (Everything EXCEPT the top comment block)

// Check if running in Electron desktop app
const isElectron = typeof window.electronAPI !== 'undefined';

if (isElectron) {
    console.log('✅ Running in Electron Desktop App');
    
    // ... rest of the code from login-integration.js
}
</script>
</body>
```

---

## ✅ Complete Example

Your login.php should end like this:

```html
    <!-- Existing login form code above -->
    
    <script>
        // Auto-focus on username field
        document.getElementById('username').focus();
        
        // ... existing login.php scripts ...
    </script>
    
    <!-- NEW: License Verification Code -->
    <script>
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
        
        loginForm.addEventListener('submit', async function(e) {
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
                // TODO: Get license key from your database
                const licenseKey = 'GOLD-2024-DEMO-0001';
                const companyId = 1;
                
                // Verify license with online server
                const licenseResult = await window.electronAPI.verifyLicense(licenseKey, companyId);
                
                console.log('License verification result:', licenseResult);
                
                // Handle different license statuses
                if (licenseResult.status === 'active') {
                    Swal.close();
                    
                    const daysRemaining = licenseResult.days_remaining || 0;
                    let expiryMessage = '';
                    
                    if (daysRemaining <= 7) {
                        expiryMessage = `<br><small class="text-warning">⚠️ License expires in ${daysRemaining} days</small>`;
                    }
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'License Verified',
                        html: `Welcome ${licenseResult.company_name || 'User'}!${expiryMessage}`,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        loginForm.submit();
                    });
                    
                } else if (licenseResult.status === 'expired') {
                    Swal.fire({
                        icon: 'error',
                        title: 'License Expired',
                        html: `<p>Your subscription has expired.</p>`,
                        confirmButtonColor: '#ef4444'
                    });
                    
                } else if (licenseResult.status === 'blocked') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Account Suspended',
                        html: `<p>Your account has been suspended due to payment issues.</p>`,
                        confirmButtonColor: '#ef4444'
                    });
                    
                } else if (licenseResult.status === 'offline') {
                    const graceDays = licenseResult.grace_days || 7;
                    
                    Swal.fire({
                        icon: 'warning',
                        title: 'Running in Offline Mode',
                        html: `<p>Cannot verify license online. Grace period: ${graceDays} days</p>`,
                        confirmButtonText: 'Continue Offline',
                        confirmButtonColor: '#f59e0b',
                        showCancelButton: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            loginForm.submit();
                        }
                    });
                }
                
            } catch (error) {
                console.error('License verification error:', error);
                
                Swal.fire({
                    icon: 'warning',
                    title: 'Verification Failed',
                    html: `<p>Could not verify license online. Continue in offline mode?</p>`,
                    showCancelButton: true,
                    confirmButtonText: 'Continue Offline',
                    confirmButtonColor: '#f59e0b'
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
    </script>
</body>
</html>
```

---

## 🔧 Configuration

### Update License Key Source

Replace these lines:
```javascript
const licenseKey = 'GOLD-2024-DEMO-0001'; // TODO: Get from database
const companyId = 1; // TODO: Get from user/company
```

With actual database lookup or config file read.

---

## ✅ Testing

1. **In Browser:** Should log "Running in Web Browser"
2. **In Electron:** Should log "Running in Electron Desktop App"
3. **License Check:** Should show "Verifying License..." dialog

---

## 📝 Notes

- The `login-integration.js` file will show lint errors - **this is normal**
- It's a template file, not meant to be executed directly
- Copy the code into `<script>` tags in your HTML file
- The lint errors will disappear once it's in the HTML context

---

## 🐛 Troubleshooting

**Issue:** "electronAPI is not defined"
- **Solution:** Make sure preload.js is loaded correctly

**Issue:** "Swal is not defined"
- **Solution:** Ensure SweetAlert2 is loaded in login.php

**Issue:** License verification always fails
- **Solution:** Check LICENSE_API URL in main.js
- **Solution:** Verify internet connection
- **Solution:** Check server-side API is running

---

**Ready?** Copy the code from `login-integration.js` into your `login.php` file!

const { contextBridge, ipcRenderer } = require('electron');

// Expose protected methods to renderer process
contextBridge.exposeInMainWorld('electronAPI', {
    // Verify license with online server
    verifyLicense: (licenseKey, companyId) => {
        return ipcRenderer.invoke('verify-license', { licenseKey, companyId });
    },

    // Get app information
    getAppInfo: () => {
        return ipcRenderer.invoke('get-app-info');
    },

    // Platform information
    platform: process.platform,

    // Check if running in Electron
    isElectron: true
});

console.log('✅ Preload script loaded - Electron API available');

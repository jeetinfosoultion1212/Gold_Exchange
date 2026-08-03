const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('GE_DESKTOP_APP', true);
contextBridge.exposeInMainWorld('GE_ELECTRON_APP', true);

contextBridge.exposeInMainWorld('electronAPI', {
  isElectron: true,
  printReceipt: (url) => ipcRenderer.invoke('print-receipt', url),
  printHtml: (html) => ipcRenderer.invoke('print-html', html),
});

(function (global) {
    'use strict';

    function isDesktop() {
        return !!global.GE_DESKTOP_APP || !!(global.electronAPI && global.electronAPI.isElectron);
    }

    function isElectronPrint() {
        return !!(global.electronAPI && typeof global.electronAPI.printReceipt === 'function');
    }

    function notifyPrintError(message) {
        if (global.Swal && typeof global.Swal.fire === 'function') {
            global.Swal.fire('Print failed', message || 'Could not send to printer.', 'error');
            return;
        }
        global.alert(message || 'Print failed');
    }

    /**
     * Open a server receipt URL. Desktop: silent print to default printer. Browser: preview popup.
     */
    function printReceipt(url, options) {
        options = options || {};
        if (!url) {
            return null;
        }

        if (isElectronPrint() && !options.preview) {
            global.electronAPI.printReceipt(url).then(function (data) {
                if (!data || !data.ok) {
                    notifyPrintError((data && data.error) || 'Unknown error');
                }
            });
            return null;
        }

        if (!isDesktop() || options.preview) {
            const width = Math.min(1100, global.screen.availWidth - 20);
            const height = Math.min(820, global.screen.availHeight - 40);
            const left = global.screenX + Math.max(0, (global.outerWidth - width) / 2);
            const top = global.screenY + 20;
            const features = [
                'popup=yes',
                'width=' + width,
                'height=' + height,
                'left=' + Math.round(left),
                'top=' + Math.round(top),
                'scrollbars=yes',
                'resizable=yes'
            ].join(',');
            const win = global.open(url, '_blank', features);
            if (win) {
                win.focus();
            }
            return win;
        }

        const sep = url.indexOf('?') >= 0 ? '&' : '?';
        const printUrl = url + sep + 'ajax=1';

        fetch(printUrl, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    notifyPrintError((data && data.error) || 'Unknown error');
                }
            })
            .catch(function (err) {
                console.error(err);
                notifyPrintError('Network error while printing.');
            });

        return null;
    }

    /**
     * Print HTML generated in JavaScript (exchange additions, booking, etc.).
     */
    function printHtml(html, options) {
        options = options || {};
        if (!html) {
            return;
        }

        if (isElectronPrint() && !options.preview) {
            global.electronAPI.printHtml(html).then(function (data) {
                if (!data || !data.ok) {
                    notifyPrintError((data && data.error) || 'Unknown error');
                }
            });
            return;
        }

        if (!isDesktop() || options.preview) {
            const win = global.open('', '_blank', 'width=320,height=640');
            if (!win) {
                notifyPrintError('Popup blocked. Allow popups to print.');
                return;
            }
            win.document.write(html);
            win.document.close();
            win.focus();
            setTimeout(function () {
                win.print();
                win.close();
            }, 300);
            return;
        }

        fetch('handlers/silent_print.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ html: html })
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    notifyPrintError((data && data.error) || 'Unknown error');
                }
            })
            .catch(function (err) {
                console.error(err);
                notifyPrintError('Network error while printing.');
            });
    }

    global.GePrint = {
        isDesktop: isDesktop,
        printReceipt: printReceipt,
        printHtml: printHtml
    };
})(window);

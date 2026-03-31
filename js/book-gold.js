// book-gold.js - Modern booking gold logic from scratch
// ------------------------------------------------------
/**
 * Main Book Gold JavaScript - clean ES6, no legacy code
 * Handles: Booking form logic (ID, date, calc), validation, submit. More modules will follow.
 */

/** Util helpers - currency, AJAX, general */
const Utils = {
    formatCurrency(num) {
        return `₹${parseFloat(num || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
    },
    parseCurrency(text) {
        return parseFloat((text || '').replace(/[₹,]/g, '')) || 0;
    },
    todayDatetimeLocal() {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        return now.toISOString().slice(0, 16);
    },
    post(action, data = {}) {
        console.log(`[Utils.post] Sending request - action: ${action}`);
        console.log('[Utils.post] Data:', data);
        const params = new URLSearchParams({action, ...data}).toString();
        console.log('[Utils.post] URL params:', params);
        
        return fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params
        }).then(async res => {
            console.log('[Utils.post] Response status:', res.status, res.statusText);
            const text = await res.text();
            console.log('[Utils.post] Response text:', text);
            try {
                const json = JSON.parse(text);
                console.log('[Utils.post] Parsed JSON:', json);
                return json;
            } catch (e) {
                // If response is not JSON, return error object
                console.error('[Utils.post] Non-JSON response:', text);
                console.error('[Utils.post] Parse error:', e);
                return {
                    status: 'error',
                    message: 'Invalid response from server. Please try again.'
                };
            }
        }).catch(err => {
            console.error('[Utils.post] Fetch error:', err);
            throw err;
        });
    }
};

/**
 * BookGoldForm - module for gold booking form UI/Logic
 */
const BookGoldForm = (() => {
    let bookingForm, bookingIdInput, dateInput, weightInput, rateInput, totalInput, submitBtn;

    /** Initialize all form logic. */
    function init() {
        // Cache elements
        bookingForm = document.getElementById('bookingForm');
        bookingIdInput = document.getElementById('bookingIdInput');
        dateInput = document.querySelector('[name="date_of_transaction"]');
        weightInput = document.querySelector('[name="booking_weight"]');
        rateInput = document.querySelector('[name="rate"]');
        totalInput = document.querySelector('[name="total_amount"]');
        submitBtn = document.getElementById('submitBtn');

        setDefaultDate();
        fetchBookingId();
        setupCalculation();
        setupValidation();
        
        // Prevent default form submission
        if (bookingForm) {
            bookingForm.addEventListener('submit', (e) => {
                e.preventDefault();
                e.stopPropagation();
                return false;
            });
        }
    }

    /** Set default date/time to now. */
    function setDefaultDate() {
        if (dateInput) dateInput.value = Utils.todayDatetimeLocal();
    }

    /** Get a booking ID from the backend; fallback to a generated ID. */
    function fetchBookingId() {
        if (!bookingIdInput) return;
        Utils.post('generate_booking_id').then(r => {
            if (r.status === 'success' && r.booking_id) {
                bookingIdInput.value = r.booking_id;
            } else {
                fallbackBookingId();
            }
        }).catch(fallbackBookingId);
    }
    function fallbackBookingId() {
        const companyId = window.companyId || Math.floor(Math.random() * 1000);
        const serial = Math.floor(Math.random() * 9000) + 1000;
        bookingIdInput.value = `B${companyId}${serial}`;
    }

    /** Live calculation: weight x rate -> total (currency formatted) */
    function setupCalculation() {
        if (weightInput && rateInput && totalInput) {
            [weightInput, rateInput].forEach(el => el.addEventListener('input', calcTotal));
        }
    }
    function calcTotal() {
        const weight = parseFloat(weightInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        totalInput.value = weight && rate ? Utils.formatCurrency(weight * rate) : '';
    }

    /** Simple validation and feedback */
    function setupValidation() {
        if (!submitBtn) return;
        submitBtn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            console.log('[BookGoldForm] Submit button clicked');
            
            // Use keyboard navigation validation if available
            if (window.KeyboardNavigation && window.KeyboardNavigation.validateAllFields) {
                if (!window.KeyboardNavigation.validateAllFields()) {
                    const firstInvalid = window.KeyboardNavigation.getFirstInvalidField();
                    if (firstInvalid) {
                        firstInvalid.focus();
                        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    return false;
                }
            } else {
                // Fallback validation
                let errors = [];
                const partyId = document.getElementById('partyId');
                const partyName = document.getElementById('partyNameInput');
                const purity = document.querySelector('[name="purity"]');
                const bookingType = document.querySelector('[name="booking_type"]');
                
                if (!partyId || !partyId.value) errors.push('Party (please select or add a party)');
                if (!partyName || !partyName.value) errors.push('Party Name');
                if (!bookingIdInput.value) errors.push('Booking ID');
                if (!dateInput.value) errors.push('Date');
                if (!weightInput.value || parseFloat(weightInput.value) <= 0) errors.push('Weight');
                if (!purity || !purity.value) errors.push('Purity');
                if (!rateInput.value || parseFloat(rateInput.value) <= 0) errors.push('Rate');
                if (!totalInput.value) errors.push('Total');
                if (!bookingType || !bookingType.value) errors.push('Booking Type');
                if (errors.length) {
                    showError(`Please fill: ${errors.join(', ')}`);
                    return;
                }
            }
            
            // Show confirmation modal before submit
            showConfirmModal();
            return false;
        });
    }

    /** Submit booking form (POST, then feedback) */
    function submitForm() {
        console.log('[BookGoldForm] submitForm called');
        
        const partyId = document.getElementById('partyId');
        const partyName = document.getElementById('partyNameInput');
        const purity = document.querySelector('[name="purity"]');
        const bookingType = document.querySelector('[name="booking_type"]');
        const narration = document.querySelector('[name="narration"]');
        const cashReceived = document.querySelector('[name="cash_received"]');
        const bankReceived = document.querySelector('[name="bank_received"]');
        const bankPaymentType = document.querySelector('[name="bank_payment_type"]');
        
        console.log('[BookGoldForm] Form elements found:');
        console.log('  - partyId element:', partyId);
        console.log('  - partyId value:', partyId ? partyId.value : 'ELEMENT NOT FOUND');
        console.log('  - partyName element:', partyName);
        console.log('  - partyName value:', partyName ? partyName.value : 'ELEMENT NOT FOUND');
        console.log('  - purity:', purity ? purity.value : 'NOT FOUND');
        console.log('  - bookingType:', bookingType ? bookingType.value : 'NOT FOUND');
        console.log('  - bookingIdInput:', bookingIdInput ? bookingIdInput.value : 'NOT FOUND');
        console.log('  - dateInput:', dateInput ? dateInput.value : 'NOT FOUND');
        console.log('  - weightInput:', weightInput ? weightInput.value : 'NOT FOUND');
        console.log('  - rateInput:', rateInput ? rateInput.value : 'NOT FOUND');
        console.log('  - totalInput:', totalInput ? totalInput.value : 'NOT FOUND');
        
        const data = {
            receipt_id: bookingIdInput.value,
            party_id: partyId ? partyId.value : '',
            party_name: partyName ? partyName.value.trim() : '',
            date_of_transaction: dateInput.value,
            booking_weight: weightInput.value,
            purity: purity ? purity.value : '',
            rate: rateInput.value,
            total_amount: Utils.parseCurrency(totalInput.value),
            booking_type: bookingType ? bookingType.value : '',
            narration: narration ? narration.value.trim() : '',
            cash_received: cashReceived ? parseFloat(cashReceived.value) || 0 : 0,
            bank_received: bankReceived ? parseFloat(bankReceived.value) || 0 : 0,
            bank_payment_type: bankPaymentType ? bankPaymentType.value : ''
        };
        
        console.log('[BookGoldForm] Data being sent to server:');
        console.log(JSON.stringify(data, null, 2));
        console.log('[BookGoldForm] party_id in data:', data.party_id);
        console.log('[BookGoldForm] party_id type:', typeof data.party_id);
        console.log('[BookGoldForm] party_id empty?', !data.party_id || data.party_id === '');
        
        Utils.post('save_booking', data)
            .then((r) => {
                console.log('[BookGoldForm] Server response:', r);
                if (r.status === 'success') {
                    console.log('[BookGoldForm] Booking saved successfully!');
                    // Clear form first
                    bookingForm && bookingForm.reset();
                    setDefaultDate();
                    fetchBookingId();
                    // Clear party selection
                    if (partyId) partyId.value = '';
                    if (partyName) partyName.value = '';
                    
                    // Show receipt modal with booking data and wait for it to close
                    showSuccess('Booking saved successfully!', r.data || {})
                        .then(() => {
                            // Refocus on party field only after modal is closed
                            setTimeout(() => {
                                const partyNameField = document.getElementById('partyNameInput');
                                if (partyNameField) {
                                    partyNameField.focus();
                                }
                            }, 100);
                        });
                } else {
                    console.error('[BookGoldForm] Server returned error:', r.message);
                    showError(r.message || 'Could not save booking.');
                }
            }).catch((err) => {
                console.error('[BookGoldForm] Submission error:', err);
                showError('Submission failed. Please try again.');
            });
    }

    /** Show confirmation modal before submit */
    function showConfirmModal() {
        if (!window.Swal) {
            submitForm();
            return;
        }
        
        // Get form data for preview
        const partyName = document.getElementById('partyNameInput')?.value || '';
        const weight = weightInput?.value || '0.000';
        const rate = rateInput?.value || '0.00';
        const total = totalInput?.value || '₹0.00';
        const bookingType = document.querySelector('[name="booking_type"]')?.value || '';
        
        Swal.fire({
            title: 'Confirm Booking',
            html: `
                <div style="text-align: left; padding: 10px;">
                    <p style="margin-bottom: 10px;"><strong>Party:</strong> ${partyName}</p>
                    <p style="margin-bottom: 10px;"><strong>Weight:</strong> ${weight} g</p>
                    <p style="margin-bottom: 10px;"><strong>Rate:</strong> ₹${rate}/g</p>
                    <p style="margin-bottom: 10px;"><strong>Total:</strong> ${total}</p>
                    <p><strong>Type:</strong> ${bookingType || 'Cash'}</p>
                </div>
                <p style="margin-top: 15px; color: #666; font-size: 14px;">Do you want to proceed with this booking?</p>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Confirm & Submit',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                submitForm();
            }
            });
    }

    /** Show error modal/notification */
    function showError(msg) {
        if (window.Swal) {
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
        } else {
            alert(msg);
        }
    }
    /** Show success modal with receipt */
    function showSuccess(msg, bookingData) {
        if (!window.Swal) {
            alert(msg);
            return;
        }
        
        // Format date
        const bookingDate = bookingData?.date_of_transaction 
            ? new Date(bookingData.date_of_transaction).toLocaleString('en-IN', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            })
            : new Date().toLocaleString('en-IN');
        
        // Calculate remaining amount
        const totalAmount = parseFloat(bookingData?.amount || 0);
        const totalReceived = parseFloat(bookingData?.total_received || 0);
        const remaining = totalAmount - totalReceived;
        
        // Get company name from session or use default
        const companyName = window.companyName || 'Gold Trading Company';
        
        // Create receipt HTML
        const receiptHTML = `
            <div id="booking-receipt" class="receipt-container" style="max-width: 400px; margin: 0 auto; font-family: Arial, sans-serif;">
                <div class="receipt-header" style="text-align: center; border-bottom: 2px dashed #333; padding-bottom: 15px; margin-bottom: 15px;">
                    <div style="font-size: 20px; font-weight: bold; margin-bottom: 5px;">${companyName}</div>
                    <div style="font-size: 12px; color: #666;">Booking Receipt</div>
                </div>
                
                <div class="receipt-body" style="font-size: 13px;">
                    <div style="margin-bottom: 12px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="color: #666;">Receipt ID:</span>
                            <span style="font-weight: bold;">${bookingData?.receipt_id || 'N/A'}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="color: #666;">Date:</span>
                            <span>${bookingDate}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #666;">Party:</span>
                            <span style="font-weight: bold;">${bookingData?.party_name || 'N/A'}</span>
                        </div>
                    </div>
                    
                    <div style="border-top: 1px dashed #ccc; border-bottom: 1px dashed #ccc; padding: 12px 0; margin: 12px 0;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span style="color: #666;">Weight:</span>
                            <span style="font-weight: bold;">${parseFloat(bookingData?.booking_weight || 0).toFixed(3)} g</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span style="color: #666;">Purity:</span>
                            <span>${parseFloat(bookingData?.purity || 0).toFixed(2)}%</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span style="color: #666;">Rate:</span>
                            <span>₹${parseFloat(bookingData?.rate || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}/g</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span style="color: #666;">Booking Type:</span>
                            <span style="font-weight: bold;">${bookingData?.booking_type || 'Cash'}</span>
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 5px; margin: 12px 0;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span style="color: #666;">Total Amount:</span>
                            <span style="font-weight: bold; font-size: 16px;">₹${totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                        </div>
                        ${totalReceived > 0 ? `
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span style="color: #666;">Received:</span>
                            <span style="color: #28a745; font-weight: bold;">₹${totalReceived.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                        </div>
                        ` : ''}
                        ${remaining > 0 ? `
                        <div style="display: flex; justify-content: space-between; border-top: 1px solid #ddd; padding-top: 8px; margin-top: 8px;">
                            <span style="color: #666;">Remaining:</span>
                            <span style="color: #dc3545; font-weight: bold; font-size: 15px;">₹${remaining.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    ${bookingData?.narration ? `
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #ccc;">
                        <div style="color: #666; font-size: 11px; margin-bottom: 5px;">Note:</div>
                        <div style="font-size: 12px;">${bookingData.narration}</div>
                    </div>
                    ` : ''}
                </div>
                
                <div class="receipt-footer" style="text-align: center; border-top: 2px dashed #333; padding-top: 15px; margin-top: 15px; font-size: 11px; color: #666;">
                    <div>Thank you for your business!</div>
                </div>
            </div>
        `;
        
        // Store the promise from Swal.fire() so we can return it
        const swalPromise = Swal.fire({
            title: 'Booking Saved Successfully!',
            html: receiptHTML,
            width: '500px',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: '<i class="fab fa-whatsapp"></i> Send WhatsApp',
            denyButtonText: '<i class="fas fa-print"></i> Print',
            cancelButtonText: 'OK',
            confirmButtonColor: '#25D366',
            denyButtonColor: '#007bff',
            cancelButtonColor: '#6c757d',
            reverseButtons: true,
            allowOutsideClick: false,
            allowEscapeKey: true,
            focusConfirm: false, // We'll handle focus manually for better control
            customClass: {
                popup: 'receipt-modal',
                htmlContainer: 'receipt-html-container'
            },
            didOpen: () => {
                // Ensure buttons are keyboard accessible
                const modal = document.querySelector('.swal2-popup');
                if (modal) {
                    const confirmBtn = modal.querySelector('.swal2-confirm');
                    const denyBtn = modal.querySelector('.swal2-deny');
                    const cancelBtn = modal.querySelector('.swal2-cancel');
                    
                    // Add proper ARIA labels and ensure buttons are focusable
                    [confirmBtn, denyBtn, cancelBtn].forEach(btn => {
                        if (btn) {
                            btn.setAttribute('tabindex', '0');
                            btn.setAttribute('role', 'button');
                            // Ensure button text is accessible (not just icon)
                            if (btn.textContent.trim() === '' || btn.innerHTML.includes('<i')) {
                                const text = btn.innerHTML.match(/>([^<]+)</)?.[1] || '';
                                if (text) {
                                    btn.setAttribute('aria-label', text.trim());
                                }
                            }
                        }
                    });
                    
                    // Focus first button after a short delay to ensure modal is fully rendered
                    setTimeout(() => {
                        // Focus cancel button (OK) first as it's the primary action
                        if (cancelBtn) {
                            cancelBtn.focus();
                        } else if (denyBtn) {
                            denyBtn.focus();
                        } else if (confirmBtn) {
                            confirmBtn.focus();
                        }
                    }, 200);
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Send WhatsApp
                sendBookingWhatsApp(bookingData);
            } else if (result.isDenied) {
                // Print receipt
                printBookingReceipt(bookingData, companyName);
            }
            // Return result so the promise chain can continue
            return result;
        });
        
        // Return the promise so callers can wait for modal to close
        return swalPromise;
    }
    
    /** Send booking receipt via WhatsApp */
    function sendBookingWhatsApp(bookingData) {
        if (!bookingData?.party_contact) {
            Swal.fire('Error', 'Party contact number not available', 'error');
            return;
        }
        
        const totalAmount = parseFloat(bookingData.amount || 0);
        const totalReceived = parseFloat(bookingData.total_received || 0);
        const remaining = totalAmount - totalReceived;
        const companyName = window.companyName || 'Gold Trading Company';
        
        // Format WhatsApp message
        const message = `*${companyName}*\n` +
            `*Booking Receipt*\n\n` +
            `Receipt ID: *${bookingData.receipt_id}*\n` +
            `Date: ${new Date(bookingData.date_of_transaction).toLocaleString('en-IN')}\n` +
            `Party: *${bookingData.party_name}*\n\n` +
            `Weight: ${parseFloat(bookingData.booking_weight).toFixed(3)} g\n` +
            `Purity: ${parseFloat(bookingData.purity).toFixed(2)}%\n` +
            `Rate: ₹${parseFloat(bookingData.rate).toLocaleString('en-IN', {minimumFractionDigits: 2})}/g\n` +
            `Booking Type: ${bookingData.booking_type || 'Cash'}\n\n` +
            `Total Amount: *₹${totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}*\n` +
            (totalReceived > 0 ? `Received: ₹${totalReceived.toLocaleString('en-IN', {minimumFractionDigits: 2})}\n` : '') +
            (remaining > 0 ? `Remaining: *₹${remaining.toLocaleString('en-IN', {minimumFractionDigits: 2})}*\n` : '') +
            `\nThank you for your business!`;
        
        // Clean phone number (remove spaces, dashes, etc.)
        const phoneNumber = bookingData.party_contact.replace(/[\s\-\(\)]/g, '');
        const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
        
        window.open(whatsappUrl, '_blank');
    }
    
    // printBookingReceipt is now defined globally below

    // Export init only
    return { init };
})();

/**
 * Print booking receipt (thermal printer compatible)
 * Global function accessible from all modules
 */
function printBookingReceipt(bookingData, companyName) {
    const totalAmount = parseFloat(bookingData.amount || 0);
    const totalReceived = parseFloat(bookingData.total_received || 0);
    const remaining = totalAmount - totalReceived;
    const bookingDate = bookingData.date_of_transaction 
        ? new Date(bookingData.date_of_transaction).toLocaleString('en-IN')
        : new Date().toLocaleString('en-IN');
    
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Receipt - ${bookingData.receipt_id}</title>
            <style>
                @page {
                    size: 80mm auto;
                    margin: 5mm;
                }
                
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Courier New', monospace;
                    font-size: 11pt;
                    width: 70mm;
                    margin: 0 auto;
                    padding: 5mm;
                }
                
                .receipt-header {
                    text-align: center;
                    border-bottom: 1px dashed #000;
                    padding-bottom: 8px;
                    margin-bottom: 8px;
                }
                
                .company-name {
                    font-size: 14pt;
                    font-weight: bold;
                    margin-bottom: 3px;
                }
                
                .receipt-title {
                    font-size: 10pt;
                    color: #666;
                }
                
                .receipt-body {
                    font-size: 10pt;
                    line-height: 1.4;
                }
                
                .receipt-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 4px;
                }
                
                .receipt-label {
                    color: #333;
                }
                
                .receipt-value {
                    font-weight: bold;
                }
                
                .receipt-divider {
                    border-top: 1px dashed #000;
                    margin: 8px 0;
                }
                
                .receipt-section {
                    background: #f5f5f5;
                    padding: 8px;
                    margin: 8px 0;
                    border-radius: 3px;
                }
                
                .receipt-footer {
                    text-align: center;
                    border-top: 1px dashed #000;
                    padding-top: 8px;
                    margin-top: 8px;
                    font-size: 9pt;
                    color: #666;
                }
                
                @media print {
                    body {
                        width: 70mm;
                    }
                    @page {
                        margin: 0;
                    }
                }
            </style>
        </head>
        <body>
            <div class="receipt-header">
                <div class="company-name">${companyName}</div>
                <div class="receipt-title">BOOKING RECEIPT</div>
            </div>
            
            <div class="receipt-body">
                <div class="receipt-row">
                    <span class="receipt-label">Receipt ID:</span>
                    <span class="receipt-value">${bookingData.receipt_id}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Date:</span>
                    <span>${bookingDate}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Party:</span>
                    <span class="receipt-value">${bookingData.party_name}</span>
                </div>
                
                <div class="receipt-divider"></div>
                
                <div class="receipt-row">
                    <span class="receipt-label">Weight:</span>
                    <span class="receipt-value">${parseFloat(bookingData.booking_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Purity:</span>
                    <span>${parseFloat(bookingData.purity).toFixed(2)}%</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Rate:</span>
                    <span>₹${parseFloat(bookingData.rate).toLocaleString('en-IN', {minimumFractionDigits: 2})}/g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Type:</span>
                    <span>${bookingData.booking_type || 'Cash'}</span>
                </div>
                
                <div class="receipt-divider"></div>
                
                <div class="receipt-section">
                    <div class="receipt-row">
                        <span class="receipt-label">Total Amount:</span>
                        <span class="receipt-value" style="font-size: 12pt;">₹${totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                    </div>
                    ${totalReceived > 0 ? `
                    <div class="receipt-row">
                        <span class="receipt-label">Received:</span>
                        <span style="color: #28a745;">₹${totalReceived.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                    </div>
                    ` : ''}
                    ${remaining > 0 ? `
                    <div class="receipt-row" style="border-top: 1px solid #ddd; padding-top: 4px; margin-top: 4px;">
                        <span class="receipt-label">Remaining:</span>
                        <span class="receipt-value" style="color: #dc3545;">₹${remaining.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                    </div>
                    ` : ''}
                </div>
                
                ${bookingData.narration ? `
                <div class="receipt-divider"></div>
                <div>
                    <div style="font-size: 9pt; color: #666; margin-bottom: 2px;">Note:</div>
                    <div style="font-size: 9pt;">${bookingData.narration}</div>
                </div>
                ` : ''}
            </div>
            
            <div class="receipt-footer">
                Thank you for your business!
            </div>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank', 'width=300,height=600');
    printWindow.document.write(printContent);
    printWindow.document.close();
    
    setTimeout(() => {
        printWindow.focus();
        printWindow.print();
        // Don't close immediately, let user see the print dialog
    }, 250);
}

/**
 * PartySearch - module for party name autocomplete and add-new
 */
const PartySearch = (() => {
    let input, idField, dropdown, addBtn;
    let results = [], selectedIndex = -1;
    let lastSelectedPartyName = ''; // Track last selected party name

    function init() {
        console.log('[PartySearch] Initializing...');
        input = document.getElementById('partyNameInput');
        idField = document.getElementById('partyId');
        dropdown = document.getElementById('partyList');
        addBtn = document.getElementById('addNewPartyBtn');
        
        console.log('[PartySearch] Elements found:');
        console.log('  - input (partyNameInput):', input);
        console.log('  - idField (partyId):', idField);
        console.log('  - dropdown (partyList):', dropdown);
        console.log('  - addBtn (addNewPartyBtn):', addBtn);
        
        if (!input || !idField || !dropdown) {
            console.error('[PartySearch] ERROR: Required elements not found!');
            return;
        }
        input.addEventListener('input', handleInput);
        input.addEventListener('keydown', handleKey);
        dropdown.addEventListener('mousedown', e => e.preventDefault()); // Prevent blur selection
        if (addBtn) addBtn.addEventListener('click', showAddPartyModal);
        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target) && e.target !== input) {
                hideDropdown();
            }
        });
        
        // Focus party field on page load (will be overridden by main handler, but keep for safety)
        // Main focus happens in DOMContentLoaded handler
        
        // Add Alt+A keyboard shortcut to open add party modal
        document.addEventListener('keydown', (e) => {
            if (e.altKey && e.key.toLowerCase() === 'a') {
                e.preventDefault();
                showAddPartyModal();
            }
        });
    }

    function handleInput(e) {
        const term = input.value.trim();
        
        // Only clear party_id if the input doesn't match the last selected party
        // This prevents clearing when user just types in the already-selected party name
        if (term !== lastSelectedPartyName) {
            idField.value = '';
            lastSelectedPartyName = '';
            console.log('[PartySearch] Input changed, cleared party_id. New term:', term);
        } else {
            // If term matches last selected party, don't show dropdown
            // This prevents dropdown from showing immediately after selection
            if (term === lastSelectedPartyName && idField.value) {
                hideDropdown();
                return;
            }
        }
        
        if (term.length < 1) {
            hideDropdown();
            return;
        }
        Utils.post('search_parties', { term }).then((result) => {
            results = Array.isArray(result) ? result : [];
            showDropdown(results);
        }).catch(() => hideDropdown());
    }

    function showDropdown(parties) {
        dropdown.innerHTML = '';
        if (!parties.length) {
            const item = document.createElement('div');
            item.textContent = 'No party found. Add new?';
            item.className = 'party-item p-2 text-blue-700 cursor-pointer';
            item.onclick = showAddPartyModal;
            dropdown.appendChild(item);
            dropdown.classList.remove('hidden');
            selectedIndex = -1;
            return;
        }
        parties.forEach((party, i) => {
            const el = document.createElement('div');
            el.className = 'party-item p-2 hover:bg-blue-100 cursor-pointer flex flex-col border-b border-gray-100';
            
            // Format outstanding amount
            const outstanding = party.outstanding || 0;
            const outstandingText = outstanding > 0 
                ? `<span class="text-xs font-semibold text-red-600">Outstanding: ₹${parseFloat(outstanding).toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>`
                : `<span class="text-xs text-green-600">No Outstanding</span>`;
            
            el.innerHTML = `
                <div class="flex items-center justify-between mb-1">
                    <span class="font-semibold text-gray-900">${party.party_name}</span>
                    <span class="text-xs font-mono text-blue-600 bg-blue-50 px-2 py-0.5 rounded">ID: ${party.id}</span>
                </div>
                <div class="flex flex-col gap-0.5">
                    ${party.address ? `<span class="text-xs text-gray-500">${party.address}</span>` : ''}
                    ${outstandingText}
                </div>
            `;
            el.onclick = () => selectParty(i);
            dropdown.appendChild(el);
        });
        dropdown.classList.remove('hidden');
        selectedIndex = -1;
    }

    function hideDropdown() {
        dropdown.innerHTML = '';
        dropdown.classList.add('hidden');
        selectedIndex = -1;
    }

    function handleKey(e) {
        const count = dropdown.querySelectorAll('.party-item').length;
        const isDropdownOpen = !dropdown.classList.contains('hidden');
        
        // If dropdown is open, handle navigation keys
        if (isDropdownOpen && ['ArrowDown','ArrowUp','Enter','Escape'].includes(e.key)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation(); // Prevent other handlers from interfering
        } else if (!isDropdownOpen) {
            // If dropdown is hidden and Enter is pressed
            if (e.key === 'Enter') {
                const term = input.value.trim();
                const partyId = document.getElementById('partyId');
                
                // If party is already selected, let keyboard navigation move to next field
                if (partyId && partyId.value) {
                    return; // Let keyboard navigation handle moving to next field
                }
                
                // If user typed something but dropdown is closed, trigger search
                if (term.length > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Trigger search
                    Utils.post('search_parties', { term }).then((result) => {
                        results = Array.isArray(result) ? result : [];
                        if (results.length > 0) {
                            showDropdown(results);
                            // Select first party automatically
                            selectedIndex = 0;
                            highlightRow();
                        } else {
                            // No results, show add party modal
                            showAddPartyModal();
                        }
                    }).catch(() => {
                        // On error, show add party modal
                        showAddPartyModal();
                    });
                } else {
                    // Empty field, let keyboard navigation handle it (will show validation error)
                    return;
                }
            }
            return; // For other keys, let default behavior
        }
        
        if (e.key === 'ArrowDown' && count > 0) {
            selectedIndex = (selectedIndex + 1) % count;
            highlightRow();
        } else if (e.key === 'ArrowUp' && count > 0) {
            selectedIndex = (selectedIndex - 1 + count) % count;
            highlightRow();
        } else if (e.key === 'Enter' && isDropdownOpen) {
            // If Enter is pressed and dropdown is open
            if (count > 0) {
                // If no party is selected, select the first one (index 0)
                if (selectedIndex < 0) {
                    selectedIndex = 0;
                    highlightRow();
                }
                // Select the highlighted party
                selectParty(selectedIndex);
                // After selection, move to next field
                setTimeout(() => {
                    const weightInput = document.querySelector('[name="booking_weight"]');
                    if (weightInput) {
                        weightInput.focus();
                        weightInput.select();
                    }
                }, 100);
            } else {
                // No parties found, show add party modal
                hideDropdown();
                showAddPartyModal();
            }
        } else if (e.key === 'Escape' && isDropdownOpen) {
            hideDropdown();
            input.focus();
        }
    }

    function highlightRow() {
        dropdown.querySelectorAll('.party-item').forEach((el, i) => {
            if (i === selectedIndex) el.classList.add('bg-blue-200');
            else el.classList.remove('bg-blue-200');
        });
    }

    function selectParty(i) {
        if (!results[i]) return;
        
        // Set party name and ID
        if (input) {
            input.value = results[i].party_name;
            lastSelectedPartyName = results[i].party_name;
        }
        
        if (idField) {
            idField.value = results[i].id;
        }
        
        // Hide dropdown immediately after selection
        hideDropdown();
        
        // Ensure dropdown is hidden (double check)
        if (dropdown) {
            dropdown.classList.add('hidden');
            dropdown.innerHTML = '';
        }
        
        // Remove all error styling immediately
        if (input) {
            input.classList.remove('border-red-500', 'input-error');
            input.classList.add('border-green-500');
            setTimeout(() => input.classList.remove('border-green-500'), 1000);
        }
        
        // Clear validation error through keyboard navigation if available
        if (window.KeyboardNavigation && typeof window.KeyboardNavigation.clearValidationError === 'function') {
            window.KeyboardNavigation.clearValidationError('partyNameInput');
        }
        
        // Also manually remove error message from any container
        const errorContainers = document.querySelectorAll('.validation-error');
        errorContainers.forEach(container => {
            if (container.textContent.includes('party') || container.textContent.includes('Party')) {
                container.classList.add('hidden');
                container.textContent = '';
            }
        });
        
        // Trigger input event to update validation state
        if (input) {
            const inputEvent = new Event('input', { bubbles: true });
            input.dispatchEvent(inputEvent);
        }
    }

    function showAddPartyModal() {
        SharedPartyHandler.showAddPartyModal({
            apiPath: 'book.php',
            onSuccess: function(response, partyData) {
                if (input) {
                    input.value = partyData.party_name;
                    lastSelectedPartyName = partyData.party_name;
                }
                
                if (idField) {
                    idField.value = response.party_id;
                }
                
                // Visual feedback
                if (input) {
                    input.classList.add('border-green-500');
                    setTimeout(() => input.classList.remove('border-green-500'), 2000);
                }
                
                hideDropdown();
                
                // Focus back on party field
                setTimeout(() => {
                    if (input) input.focus();
                }, 500);
            }
        });
    }

    return { init };
})();

/**
 * BookingHistory - module for history dropdown, edit, and delete
 */
const BookingHistory = (() => {
    let listBtn, listPanel, form;
    let bookings = [];

    function init() {
        listBtn = document.getElementById('showBookingListBtn');
        listPanel = document.getElementById('bookingList');
        form = document.getElementById('bookingForm');
        if (!(listBtn && listPanel && form)) return;
        listBtn.addEventListener('click', showList);
        document.getElementById('bookingIdInput').addEventListener('click', showList);
        document.addEventListener('click', (e) => {
            if (!listPanel.contains(e.target) && e.target !== listBtn) hideList();
        });
    }

    function showList() {
        Utils.post('get_booking_list')
            .then(list => {
                bookings = Array.isArray(list) ? list : [];
                listPanel.innerHTML = '';
                if (!bookings.length) {
                    const noDiv = document.createElement('div');
                    noDiv.className = 'p-3 text-center text-gray-500';
                    noDiv.textContent = 'No previous bookings.';
                    listPanel.appendChild(noDiv);
                } else {
                    bookings.forEach((b, i) => {
                        const d = document.createElement('div');
                        d.className = 'booking-item p-2 border-b hover:bg-yellow-100 cursor-pointer';
                        d.innerHTML = `<b>${b.receipt_id}</b> <span class='text-xs text-gray-500'>${b.party_name || ''} · ${b.date_of_transaction ? b.date_of_transaction.split('T')[0] : ''}</span>`;
                        d.onclick = () => edit(i);
                        listPanel.appendChild(d);
                    });
                }
                listPanel.classList.remove('hidden');
            });
    }

    function hideList() {
        listPanel.innerHTML = '';
        listPanel.classList.add('hidden');
    }

    function edit(i) {
        const b = bookings[i];
        if (!b) return;
        // Fill form fields for editing
        form.querySelector('[name="receipt_id"]').value = b.receipt_id;
        form.querySelector('[name="date_of_transaction"]').value = b.date_of_transaction && b.date_of_transaction.replace(' ', 'T');
        form.querySelector('[name="party_name"]').value = b.party_name || '';
        form.querySelector('[name="party_id"]').value = b.party_id || '';
        form.querySelector('[name="booking_weight"]').value = b.gold_weight || '';
        form.querySelector('[name="purity"]').value = b.purity || '';
        form.querySelector('[name="rate"]').value = b.rate || '';
        form.querySelector('[name="total_amount"]').value = b.gold_amount ? Utils.formatCurrency(b.gold_amount) : '';
        // Unlock update and delete, hide save
        document.getElementById('submitBtn').classList.add('hidden');
        document.getElementById('updateBtn').classList.remove('hidden');
        document.getElementById('deleteBtn').classList.remove('hidden');
        document.getElementById('cancelEditBtn').classList.remove('hidden');
        hideList();
        // Wire buttons
        document.getElementById('updateBtn').onclick = () => update(b.id);
        document.getElementById('deleteBtn').onclick = () => del(b.id, b.receipt_id);
        document.getElementById('cancelEditBtn').onclick = resetForm;
    }

    function update(id) {
        if (!window.Swal) return alert('SweetAlert2 required!');
        Swal.fire({
            title: 'Update booking?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, update'
        }).then(r => {
            if (!r.isConfirmed) return;
            // Gather form data
            const data = {
                booking_id: id,
                action: 'update_booking',
                receipt_id: form.querySelector('[name="receipt_id"]').value,
                date_of_transaction: form.querySelector('[name="date_of_transaction"]').value,
                party_id: form.querySelector('[name="party_id"]').value,
                booking_weight: form.querySelector('[name="booking_weight"]').value,
                purity: form.querySelector('[name="purity"]').value,
                rate: form.querySelector('[name="rate"]').value,
                total_amount: Utils.parseCurrency(form.querySelector('[name="total_amount"]').value)
            };
            Utils.post('update_booking', data).then(resp => {
                if (resp.status === 'success') {
                    Swal.fire('Updated!', '', 'success');
                    resetForm();
                } else {
                    Swal.fire('Error', resp.message || 'Failed', 'error');
                }
            }).catch(() => Swal.fire('Error', 'Update failed.', 'error'));
        });
    }

    function del(id, receiptId) {
        if (!window.Swal) return alert('SweetAlert2 required!');
        Swal.fire({
            title: `Delete booking ${receiptId}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete'
        }).then(r => {
            if (!r.isConfirmed) return;
            Utils.post('delete_booking', { booking_id: id }).then(res => {
                if (res.status === 'success') {
                    Swal.fire('Deleted!', '', 'success');
                    resetForm();
                } else {
                    Swal.fire('Error', res.message || 'Failed', 'error');
                }
            }).catch(() => Swal.fire('Error', 'Delete failed.', 'error'));
        });
    }

    function resetForm() {
        form.reset();
        BookGoldForm && BookGoldForm.init && BookGoldForm.init();
        document.getElementById('submitBtn').classList.remove('hidden');
        document.getElementById('updateBtn').classList.add('hidden');
        document.getElementById('deleteBtn').classList.add('hidden');
        document.getElementById('cancelEditBtn').classList.add('hidden');
    }

    return { init };
})();

/**
 * TransactionsTable - right panel table, row select, actions (edit/delete/share)
 */
const TransactionsTable = (() => {
    let tbody;

    function init() {
        tbody = document.querySelector('.responsive-table tbody');
        if (!tbody) return;
        tbody.addEventListener('click', handleRowClick);
    }

    function handleRowClick(e) {
        let tr = e.target.closest('tr');
        if (!tr || !tr.classList.contains('selectable-row')) return;
        selectRow(tr);
        // Look for action button
        if (e.target.closest('button')) {
            const btn = e.target.closest('button');
            if (btn.classList.contains('print-transaction')) printTransaction(tr);
            if (btn.classList.contains('delete-transaction')) deleteTransaction(tr);
            if (btn.classList.contains('share-transaction')) shareTransaction(tr);
            e.stopPropagation();
            return;
        }
    }

    function selectRow(tr) {
        tbody.querySelectorAll('tr.selectable-row').forEach(row => row.classList.remove('bg-blue-100'));
        tr.classList.add('bg-blue-100');
    }

    function printTransaction(tr) {
        // Get receipt ID from the row
        const receiptId = tr.querySelector('.font-mono')?.textContent.trim() || tr.getAttribute('data-receipt-id');
        if (!receiptId) {
            Swal.fire('Error', 'Receipt ID not found', 'error');
            return;
        }
        
        // Try to get transaction data from data attribute first
        let transaction = null;
        const transactionData = tr.getAttribute('data-transaction');
        
        if (transactionData) {
            try {
                // Decode base64 encoded JSON
                let jsonString;
                try {
                    // Try base64 decode first (new format)
                    jsonString = atob(transactionData);
                } catch (e) {
                    // If base64 decode fails, try parsing as-is (old format or HTML entities)
                    jsonString = transactionData;
                    
                    // Decode HTML entities if present
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = jsonString;
                    jsonString = tempDiv.textContent || tempDiv.innerText || jsonString;
                    
                    // Manual HTML entity decoding as fallback
                    if (jsonString.includes('&')) {
                        jsonString = jsonString
                            .replace(/&quot;/g, '"')
                            .replace(/&#039;/g, "'")
                            .replace(/&amp;/g, '&')
                            .replace(/&lt;/g, '<')
                            .replace(/&gt;/g, '>');
                    }
                }
                
                // Parse the JSON
                transaction = JSON.parse(jsonString);
            } catch (error) {
                console.warn('Failed to parse transaction data from attribute:', error);
                // Will fetch from server instead
            }
        }
        
        // If we have transaction data and it's a booking, use it
        if (transaction && transaction.transaction_type === 'Booking') {
            printBookingFromData(transaction);
            return;
        }
        
        // Otherwise, fetch booking details from server using receipt_id
        fetchBookingByReceiptId(receiptId);
    }
    
    function fetchBookingByReceiptId(receiptId) {
        // Show loading indicator
        Swal.fire({
            title: 'Loading...',
            text: 'Fetching booking details',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Fetch booking details from server
        Utils.post('get_booking_details', { receipt_id: receiptId })
            .then((response) => {
                Swal.close();
                
                if (response.error) {
                    Swal.fire('Error', response.error, 'error');
                    return;
                }
                
                // Check if it's a booking transaction
                if (response.transaction_type !== 'Booking') {
                    Swal.fire('Info', 'Print receipt is only available for booking transactions', 'info');
                    return;
                }
                
                // Print the booking receipt
                printBookingFromData(response);
            })
            .catch((error) => {
                Swal.close();
                console.error('Error fetching booking details:', error);
                Swal.fire('Error', 'Failed to fetch booking details. Please try again.', 'error');
            });
    }
    
    function printBookingFromData(transaction) {
        const companyName = window.companyName || 'Gold Trading Company';
        
        // Calculate total received if available
        let totalReceived = 0;
        if (transaction.total_received !== undefined) {
            totalReceived = parseFloat(transaction.total_received) || 0;
        }
        
        // Prepare booking data for printing
        const bookingData = {
            receipt_id: transaction.receipt_id,
            party_name: transaction.party_name,
            date_of_transaction: transaction.date_of_transaction,
            booking_weight: transaction.gold_weight,
            purity: transaction.purity,
            rate: transaction.rate,
            amount: transaction.gold_amount,
            booking_type: transaction.booking_type || 'Cash',
            narration: transaction.narration || '',
            total_received: totalReceived
        };
        
        // Print the receipt
        printBookingReceipt(bookingData, companyName);
    }

    function deleteTransaction(tr) {
        const receiptId = tr.querySelector('.font-mono')?.textContent.trim();
        if (!receiptId) return;
        Swal.fire({
            title: `Delete transaction ${receiptId}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete'
        }).then(({isConfirmed}) => {
            if (!isConfirmed) return;
            Utils.post('delete_transaction', {receipt_id: receiptId}).then(res => {
                if (res.status === 'success') {
                    Swal.fire('Deleted!','Transaction deleted successfully','success');
                    tr.remove();
                } else {
                    Swal.fire('Error', res.message || 'Failed', 'error');
                }
            }).catch(() => Swal.fire('Error','Delete failed','error'));
        });
    }

    function shareTransaction(tr) {
        const receiptId = tr.querySelector('.font-mono')?.textContent.trim();
        const party = tr.querySelector('td:nth-child(2)')?.textContent.trim();
        const amount = tr.querySelector('td:nth-child(4) .font-bold')?.textContent.trim();
        const text = `Transaction: ${receiptId}\nParty: ${party}\nAmount: ${amount}`;
        navigator.clipboard.writeText(text)
            .then(() => {
                Swal.fire('Copied!', 'Transaction details copied to clipboard', 'success');
            })
            .catch(() => {
                Swal.fire('Share', text, 'info');
            });
    }

    return { init };
})();

// ------- PAGE BOOTSTRAP -------
document.addEventListener('DOMContentLoaded', () => {
    // Initialize keyboard navigation first
    if (typeof KeyboardNavigation !== 'undefined') {
        KeyboardNavigation.init();
        window.KeyboardNavigation = KeyboardNavigation; // Make it globally available
    }
    
    BookGoldForm.init();
    PartySearch.init();
    BookingHistory.init();
    TransactionsTable.init();
    
    // Focus on Party Name field after initialization (user-friendly)
    // Use longer timeout to ensure all initialization is complete
    setTimeout(() => {
        const partyField = document.getElementById('partyNameInput');
        if (partyField) {
            partyField.focus();
            // Also ensure it's visible and scroll into view if needed
            partyField.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, 500);
});

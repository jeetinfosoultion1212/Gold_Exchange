/**
 * exchange.js
 * -----------------------------------------------------------------------
 * Single consolidated script for exchange.php.
 *
 * This replaces (and merges, with duplicates removed) the following files:
 *   - exchange.js                    (party search, calculations, form init)
 *   - exchange_additions.js          (receipt search, print helpers)
 *   - exchange_multi_item.js         (multi-item save/load — now the ONLY save/load path)
 *   - shared-party-handler.js        (Add New Party modal)
 *   - clear_party.js                 (clear party selection button)
 *   - the inline <script> block that used to live inside exchange.php
 *
 * NOT carried over: keyboard_navigation.js. That module targets a
 * `#bookingForm` with fields like `booking_weight` / `booking_type` that do
 * not exist on this page (this page uses `#exchangeForm`). Its own init()
 * bails out immediately when `#bookingForm` isn't found, and it was never
 * even called anywhere — so it was dead weight here and has been dropped.
 *
 * Fixes made while consolidating (see inline comments marked FIX:):
 *   1. The "Edit" button in the transaction list now reloads the FULL
 *      multi-item row set (Gold/Silver lines), instead of only the
 *      aggregated totals into hidden fields.
 *   2. Amount now recalculates whenever weights/issue change, not only
 *      when the Rate field is retyped.
 *   3. Removed dead code: the old single-item saveTransaction/loadTransaction,
 *      the unused printExchangeReceipt() thermal-receipt builder, and the
 *      duplicate receipt-search binding (it was wired up twice).
 * -----------------------------------------------------------------------
 */

/** Compact SweetAlert2 for simple confirm/alert dialogs (default popup is ~512px wide). */
function geSwalCompact(options) {
    const defaults = {
        width: '320px',
        padding: '0.85rem 1rem',
        customClass: {
            popup: 'ge-swal-sm',
            confirmButton: 'ge-swal-sm-btn',
            cancelButton: 'ge-swal-sm-btn'
        }
    };
    const merged = Object.assign({}, defaults, options || {});
    if (options && options.customClass) {
        merged.customClass = Object.assign({}, defaults.customClass, options.customClass);
    }
    return Swal.fire(merged);
}

/* ============================================================
   0. PRINT (opens print_exchange_receipt.php in a popup)
   ============================================================ */
   function openExchangeReceiptPrint(transactionId) {
    if (!transactionId) return null;
    const url = 'print_exchange_receipt.php?id=' + encodeURIComponent(transactionId);
    if (window.GePrint && typeof window.GePrint.printReceipt === 'function') {
        return window.GePrint.printReceipt(url);
    }
    const width = Math.min(1100, window.screen.availWidth - 20);
    const height = Math.min(820, window.screen.availHeight - 40);
    const left = window.screenX + Math.max(0, (window.outerWidth - width) / 2);
    const top = window.screenY + 20;
    const features = [
        'popup=yes',
        'width=' + width,
        'height=' + height,
        'left=' + Math.round(left),
        'top=' + Math.round(top),
        'scrollbars=yes',
        'resizable=yes',
        'toolbar=no',
        'menubar=no',
        'location=no',
        'status=no'
    ].join(',');
    const win = window.open(url, 'exchangeReceiptPrint_' + transactionId, features);
    if (!win) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Popup Blocked',
                html: 'Please allow popups for this site, or <a href="' + url + '" target="_blank" rel="noopener">click here to open the receipt</a>.',
                confirmButtonText: 'OK'
            });
        } else {
            alert('Popup blocked. Please allow popups to print receipts.');
        }
        return null;
    }
    win.focus();
    return win;
}

/* ============================================================
   1. SHARED PARTY HANDLER (Add New Party modal)
   ============================================================ */
const SharedPartyHandler = (() => {
    /**
     * Shows a comprehensive modal to add a new party.
     * @param {Object} options - Configuration options
     * @param {Function} options.onSuccess - Callback function called with (response, partyData)
     * @param {string} options.apiPath - Optional custom path for the AJAX request
     * @param {string} options.prefillName - Optional party name to prefill
     */
    function showAddPartyModal(options = {}) {
        if (typeof Swal === 'undefined') {
            console.error('SweetAlert2 is required for SharedPartyHandler');
            return;
        }

        Swal.fire({
            title: '<div class="flex items-center text-slate-800 text-[11px] font-bold uppercase tracking-tight"><i class="fas fa-user-plus mr-1.5 text-blue-600"></i>Add New Party</div>',
            html: `
                <div class="text-left" style="font-family: 'Poppins', sans-serif;">
                    <div class="grid grid-cols-6 gap-x-2 gap-y-1">
                        <!-- Row 1: Party Name -->
                        <div class="col-span-6">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Party Name *</label>
                            <input type="text" id="newPartyName" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded focus:ring-1 focus:ring-blue-500 transition text-[11px] font-bold h-7" placeholder="Full name of party">
                        </div>

                        <!-- Row 2: Address -->
                        <div class="col-span-6">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Address</label>
                            <input type="text" id="newPartyAddress" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded focus:ring-1 focus:ring-blue-500 transition text-[11px] font-bold h-7" placeholder="Local address">
                        </div>

                        <!-- Row 3: City & State -->
                        <div class="col-span-3">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">City</label>
                            <input type="text" id="newPartyCity" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded text-[11px] font-bold h-7" placeholder="City">
                        </div>
                        <div class="col-span-3">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">State</label>
                            <select id="newPartyState" class="w-full px-1 py-1 bg-slate-50 border border-slate-200 rounded text-[11px] font-bold h-7 appearance-none">
                                <option value="">Select State</option>
                                <option value="Andhra Pradesh">Andhra Pradesh</option>
                                <option value="Arunachal Pradesh">Arunachal Pradesh</option>
                                <option value="Assam">Assam</option>
                                <option value="Bihar">Bihar</option>
                                <option value="Chhattisgarh">Chhattisgarh</option>
                                <option value="Goa">Goa</option>
                                <option value="Gujarat">Gujarat</option>
                                <option value="Haryana">Haryana</option>
                                <option value="Himachal Pradesh">Himachal Pradesh</option>
                                <option value="Jharkhand">Jharkhand</option>
                                <option value="Karnataka">Karnataka</option>
                                <option value="Kerala">Kerala</option>
                                <option value="Madhya Pradesh">Madhya Pradesh</option>
                                <option value="Maharashtra">Maharashtra</option>
                                <option value="Manipur">Manipur</option>
                                <option value="Meghalaya">Meghalaya</option>
                                <option value="Mizoram">Mizoram</option>
                                <option value="Nagaland">Nagaland</option>
                                <option value="Odisha">Odisha</option>
                                <option value="Punjab">Punjab</option>
                                <option value="Rajasthan">Rajasthan</option>
                                <option value="Sikkim">Sikkim</option>
                                <option value="Tamil Nadu">Tamil Nadu</option>
                                <option value="Telangana">Telangana</option>
                                <option value="Tripura">Tripura</option>
                                <option value="Uttar Pradesh">Uttar Pradesh</option>
                                <option value="Uttarakhand">Uttarakhand</option>
                                <option value="West Bengal">West Bengal</option>
                                <option value="Andaman and Nicobar Islands">Andaman and Nicobar Islands</option>
                                <option value="Chandigarh">Chandigarh</option>
                                <option value="Dadra and Nagar Haveli and Daman and Diu">Dadra and Nagar Haveli and Daman and Diu</option>
                                <option value="Delhi">Delhi</option>
                                <option value="Jammu and Kashmir">Jammu and Kashmir</option>
                                <option value="Ladakh">Ladakh</option>
                                <option value="Lakshadweep">Lakshadweep</option>
                                <option value="Puducherry">Puducherry</option>
                            </select>
                        </div>

                        <!-- Row 4: Contact & GSTIN -->
                        <div class="col-span-3">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Contact No</label>
                            <input type="text" id="newPartyContact" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded text-[11px] font-bold h-7" placeholder="Mobile / Phone">
                        </div>
                        <div class="col-span-3">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">GSTIN</label>
                            <input type="text" id="newPartyGSTIN" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded text-[11px] font-bold h-7 uppercase" placeholder="GST Number">
                        </div>

                        <!-- Row 5: Initial Outstandings -->
                        <div class="col-span-6 border-t border-slate-100 pt-2 mt-1">
                            <label class="block text-[9px] font-black text-blue-600 uppercase tracking-widest mb-2 border-b border-blue-50 pb-1">Initial Outstandings</label>

                            <div class="grid grid-cols-2 gap-3 mb-2">
                                <div>
                                    <label class="block text-[8px] font-bold text-amber-600 mb-0.5 uppercase tracking-tighter">Gold Balance (g)</label>
                                    <input type="number" step="0.001" id="newPartyGoldBal" class="w-full px-2 py-1.5 bg-amber-50 border border-amber-200 rounded text-xs font-black text-amber-900 h-8" placeholder="0.000">
                                </div>
                                <div>
                                    <label class="block text-[8px] font-bold text-slate-500 mb-0.5 uppercase tracking-tighter">Silver Balance (g)</label>
                                    <input type="number" step="0.001" id="newPartySilverBal" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-200 rounded text-xs font-black text-slate-700 h-8" placeholder="0.000">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[8px] font-bold text-green-600 mb-0.5 uppercase tracking-tighter">Cash Balance (₹)</label>
                                    <input type="number" step="0.01" id="newPartyCashBal" class="w-full px-2 py-1.5 bg-green-50 border border-green-200 rounded text-xs font-black text-green-800 h-8" placeholder="0.00">
                                </div>
                                <div>
                                    <label class="block text-[8px] font-bold text-blue-600 mb-0.5 uppercase tracking-tighter">Bank Balance (₹)</label>
                                    <input type="number" step="0.01" id="newPartyBankBal" class="w-full px-2 py-1.5 bg-blue-50 border border-blue-200 rounded text-xs font-black text-blue-800 h-8" placeholder="0.00">
                                </div>
                            </div>
                        </div>

                        <!-- Row 6: Bank Account -->
                        <div class="col-span-6 border-t border-slate-100 pt-1 mt-1">
                            <label class="block text-[8.5px] font-bold text-slate-400 uppercase tracking-tight mb-1">Bank Information</label>
                            <div class="grid grid-cols-3 gap-1.5">
                                <input type="text" id="newPartyBankName" class="w-full px-1.5 py-1 bg-slate-50 border border-slate-200 rounded text-[10px] font-bold h-7 uppercase tracking-tighter" placeholder="Bank Name">
                                <input type="text" id="newPartyAccountNo" class="w-full px-1.5 py-1 bg-slate-50 border border-slate-200 rounded text-[10px] font-bold h-7 uppercase tracking-tighter" placeholder="A/C No">
                                <input type="text" id="newPartyIFSC" class="w-full px-1.5 py-1 bg-slate-50 border border-slate-200 rounded text-[10px] font-bold h-7 uppercase tracking-tighter" placeholder="IFSC">
                            </div>
                        </div>
                    </div>
                </div>
            `,
            width: '380px',
            showCancelButton: true,
            confirmButtonText: 'Create',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#64748b',
            padding: '0.4rem',
            customClass: {
                title: 'p-0 mb-1',
                confirmButton: 'text-[10px] px-3 py-1 font-bold uppercase tracking-tighter mx-1',
                cancelButton: 'text-[10px] px-3 py-1 font-bold uppercase tracking-tighter mx-1'
            },
            didOpen: () => {
                setTimeout(() => {
                    if (options.prefillName) {
                        $('#newPartyName').val(options.prefillName);
                        $('#newPartyAddress').focus();
                    } else {
                        $('#newPartyName').focus();
                    }
                }, 100);

                const inputs = [
                    '#newPartyName', '#newPartyAddress', '#newPartyCity', '#newPartyState',
                    '#newPartyContact', '#newPartyGSTIN', '#newPartyCashBal',
                    '#newPartyBankBal', '#newPartyGoldBal', '#newPartyBankName',
                    '#newPartyAccountNo', '#newPartyIFSC'
                ];

                inputs.forEach((selector, index) => {
                    $(selector).on('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            if (index < inputs.length - 1) {
                                $(inputs[index + 1]).focus();
                            } else {
                                Swal.clickConfirm();
                            }
                        }
                    });
                });
            },
            preConfirm: () => {
                const partyData = {
                    party_name: $('#newPartyName').val().trim(),
                    address: $('#newPartyAddress').val().trim(),
                    contact_no: $('#newPartyContact').val().trim(),
                    city: $('#newPartyCity').val().trim(),
                    state: $('#newPartyState').val(),
                    gstin: $('#newPartyGSTIN').val().trim() || 'N/A',
                    cash_balance: parseFloat($('#newPartyCashBal').val()) || 0,
                    bank_balance: parseFloat($('#newPartyBankBal').val()) || 0,
                    gold_balance: parseFloat($('#newPartyGoldBal').val()) || 0,
                    silver_balance: parseFloat($('#newPartySilverBal').val()) || 0,
                    bank_name: $('#newPartyBankName').val() || '',
                    account_no: $('#newPartyAccountNo').val() || '',
                    ifsc_code: $('#newPartyIFSC').val() || ''
                };

                if (!partyData.party_name) {
                    Swal.showValidationMessage('Name required!');
                    return false;
                }

                return partyData;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                saveParty(result.value, options);
            }
        });
    }

    function saveParty(partyData, options) {
        const apiPath = options.apiPath || '';

        $.ajax({
            url: apiPath,
            type: 'POST',
            data: {
                action: 'save_party',
                ...partyData
            },
            dataType: 'json',
            success: function (response) {
                if (response.status === 'success' || response.party_id) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Party Added!',
                        text: response.message || 'Party has been successfully created.',
                        timer: 1500,
                        showConfirmButton: false
                    });

                    if (typeof options.onSuccess === 'function') {
                        options.onSuccess(response, partyData);
                    }
                } else {
                    Swal.fire('Error', response.message || 'Could not add party.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Server connection failed. Please try again.', 'error');
            }
        });
    }

    return { showAddPartyModal };
})();

/* ============================================================
   2. MULTI-ITEM RECEIVED ROWS (Gold/Silver lines)
   ============================================================ */
function addReceivedItem() {
    const table = document.getElementById('receivedItemsTable');
    const rowCount = table.querySelectorAll('.received-item-row').length + 1;

    const newRow = `
        <tr class="received-item-row">
            <td class="px-2 py-1.5 border-b text-gray-700 font-bold item-number" style="width: 40px;">${rowCount}</td>
            <td class="px-2 py-1.5 border-b" style="width: 64px;">
                <select class="w-full px-1 py-1 text-[10px] font-bold text-gray-800 bg-white border border-gray-200 rounded received-material">
                    <option value="Gold" selected>Gold</option>
                    <option value="Silver">Silver</option>
                </select>
            </td>
            <td class="px-2 py-1.5 border-b">
                <input type="number" step="0.001" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 received-weight" placeholder="0.000" required>
            </td>
            <td class="px-2 py-1.5 border-b">
                <input type="number" step="0.01" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-yellow-400 focus:border-yellow-400 received-purity" placeholder="0.00" required>
            </td>
            <td class="px-2 py-1.5 border-b">
                <input type="number" step="0.001" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-emerald-400 focus:border-emerald-400 received-fine" placeholder="0.000">
            </td>
            <td class="px-2 py-1.5 border-b text-center" style="width: 48px;">
                <button type="button" onclick="removeReceivedItem(this)" class="text-red-600 hover:text-red-800 text-sm">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
        </tr>
    `;

    table.insertAdjacentHTML('beforeend', newRow);
    updateItemNumbers();
    attachCalculationListeners();
}

function removeReceivedItem(btn) {
    const table = document.getElementById('receivedItemsTable');
    if (table.querySelectorAll('.received-item-row').length > 1) {
        btn.closest('tr').remove();
        updateItemNumbers();
        calculateTotals();
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Cannot Remove',
            text: 'At least one received item is required',
            timer: 2000,
            showConfirmButton: false
        });
    }
}

function updateItemNumbers() {
    const rows = document.querySelectorAll('.received-item-row');
    rows.forEach((row, index) => {
        row.querySelector('.item-number').textContent = index + 1;
    });
}

function calculateRowFine(row) {
    const weight = parseFloat(row.querySelector('.received-weight').value) || 0;
    const purity = parseFloat(row.querySelector('.received-purity').value) || 0;
    let fine = (weight * purity / 100);
    // Round to 3 decimals (grams), matching the field's actual precision.
    // Rounding to 2 decimals here first (hundredths) then padding to 3 was
    // silently inflating the fine weight (e.g. 24.21797 -> 24.22 -> "24.220"
    // instead of 24.218), which threw off the back-calculated purity %.
    const roundedFine = (Math.round(fine * 1000) / 1000).toFixed(3);
    row.querySelector('.received-fine').value = roundedFine;
}

/** Issue vault metal follows the Metal column (lines with weight must agree). */
function syncIssueMetalFromReceivedRows() {
    const rows = document.querySelectorAll('#receivedItemsTable .received-item-row');
    const matsWeighted = new Set();
    let firstRowMaterial = 'Gold';
    let sawFirst = false;
    rows.forEach(row => {
        const sel = row.querySelector('.received-material');
        const m = (sel && String(sel.value).toLowerCase() === 'silver') ? 'Silver' : 'Gold';
        const w = parseFloat(row.querySelector('.received-weight').value) || 0;
        if (w > 0) {
            matsWeighted.add(m);
        }
        if (!sawFirst) {
            firstRowMaterial = m;
            sawFirst = true;
        }
    });
    let issueMat = 'Gold';
    let conflict = false;
    if (matsWeighted.size === 1) {
        issueMat = matsWeighted.values().next().value;
    } else if (matsWeighted.size > 1) {
        conflict = true;
        issueMat = 'Gold';
    } else {
        issueMat = firstRowMaterial;
    }
    const hid = document.getElementById('exchangeMaterialHidden');
    const disp = document.getElementById('issueMetalDisplay');
    if (hid) {
        hid.value = issueMat;
    }
    if (disp) {
        disp.classList.remove('text-red-600', 'font-bold');
        if (conflict) {
            disp.textContent = 'Fix: one metal only';
            disp.classList.add('text-red-600', 'font-bold');
        } else {
            disp.textContent = issueMat === 'Silver' ? 'Fine silver' : 'Fine gold';
        }
    }
}

function calculateTotals(recalcFineRows = false) {
    // Received items
    let totalReceivedFine = 0;
    let totalReceivedWeight = 0;
    let weightedPuritySum = 0;
    const receivedRows = document.querySelectorAll('.received-item-row');
    receivedRows.forEach(row => {
        if (recalcFineRows) {
            calculateRowFine(row);
        }
        const weight = parseFloat(row.querySelector('.received-weight').value) || 0;
        const purity = parseFloat(row.querySelector('.received-purity').value) || 0;
        const fine = parseFloat(row.querySelector('.received-fine').value) || 0;
        totalReceivedWeight += weight;
        totalReceivedFine += fine;
        weightedPuritySum += weight * purity;
    });

    document.getElementById('totalReceivedWeight').value = totalReceivedWeight.toFixed(3);
    // Same 3-decimal rounding fix as calculateRowFine() - was rounding to hundredths.
    const finalFine = (Math.round(totalReceivedFine * 1000) / 1000).toFixed(3);
    document.getElementById('totalReceivedFine').value = finalFine;

    const issueWeight = parseFloat(document.getElementById('issueWeightInput').value) || 0;

    const difference = issueWeight - parseFloat(finalFine);
    const differenceField = document.getElementById('differenceWeight');
    differenceField.value = difference.toFixed(3);

    if (difference > 0) {
        differenceField.classList.remove('text-red-700', 'text-gray-700', 'font-semibold');
        differenceField.classList.add('text-green-700', 'font-bold');
    } else if (difference < 0) {
        differenceField.classList.remove('text-green-700', 'text-gray-700', 'font-semibold');
        differenceField.classList.add('text-red-700', 'font-bold');
    } else {
        differenceField.classList.remove('text-green-700', 'text-red-700', 'font-bold');
        differenceField.classList.add('text-gray-700', 'font-semibold');
    }

    // Hidden fields for backward compatibility with server field names
    document.getElementById('receivedWeight').value = totalReceivedWeight.toFixed(3);
    document.getElementById('fineWeight').value = finalFine;
    document.getElementById('issueWeight').value = issueWeight.toFixed(3);

    // Weighted average of entered purities — not back-calculated from rounded fine weight.
    if (totalReceivedWeight > 0) {
        const avgPurity = (weightedPuritySum / totalReceivedWeight).toFixed(2);
        document.getElementById('purity').value = avgPurity;
    }

    syncIssueMetalFromReceivedRows();

    // FIX: previously amount only recalculated when the Rate field itself was retyped,
    // so it silently went stale whenever weights/issue changed without touching Rate.
    // Recalculating amount here keeps it always in sync with the difference weight.
    if (typeof calculateAmount === 'function') {
        calculateAmount();
    }
}

function onReceivedWeightOrPurityInput() {
    calculateTotals(true);
}

function attachCalculationListeners() {
    document.querySelectorAll('.received-weight, .received-purity').forEach(input => {
        input.removeEventListener('input', onReceivedWeightOrPurityInput);
        input.addEventListener('input', onReceivedWeightOrPurityInput);
    });
    document.querySelectorAll('.received-fine').forEach(input => {
        input.removeEventListener('input', onReceivedFineInput);
        input.addEventListener('input', onReceivedFineInput);
    });
    document.querySelectorAll('.received-material').forEach(sel => {
        sel.removeEventListener('change', onReceivedMaterialChange);
        sel.addEventListener('change', onReceivedMaterialChange);
    });

    const issueWeight = document.getElementById('issueWeightInput');
    if (issueWeight) {
        issueWeight.removeEventListener('input', onReceivedMaterialChange);
        issueWeight.addEventListener('input', onReceivedMaterialChange);
    }
}

function onReceivedFineInput() {
    calculateTotals(false);
}

function onReceivedMaterialChange() {
    calculateTotals(false);
}

/* ============================================================
   3. AMOUNT / PAYMENT STATUS
   ============================================================ */
function calculateAmount() {
    const difference = parseFloat($('#differenceWeight').val()) || 0;
    const displayRate = parseFloat($('#rate').val()) || 0;
    const rate = (window.GoldRateUtils && GoldRateUtils.effectivePerGram)
        ? GoldRateUtils.effectivePerGram(displayRate)
        : displayRate;
    const amount = Math.round(Math.abs(difference) * rate); // Round to whole number
    $('#amount').val(amount);

    const $diffField = $('#differenceWeight');
    $diffField.removeClass('text-red-600 text-green-600 font-bold');

    if (difference > 0) {
        $diffField.addClass('text-green-600 font-bold');
        $('#paymentAmountLabel').html('<strong>Received Amount (₹)</strong>');
    } else if (difference < 0) {
        $diffField.addClass('text-red-600 font-bold');
        $('#paymentAmountLabel').html('<strong>Paid Amount (₹)</strong>');
    } else {
        $('#paymentAmountLabel').html('<strong>Paid Amount (₹)</strong>');
    }

    updatePaymentStatus();
}

function updatePaymentStatus() {
    const amount = parseFloat($('#amount').val()) || 0;
    const paymentAmount = parseFloat($('#paymentAmount').val()) || 0;

    let status = 'Due';
    if (amount > 0 && paymentAmount >= amount) {
        status = 'Paid';
    } else if (paymentAmount > 0) {
        status = 'Partial';
    } else {
        status = 'Due';
    }

    $('input[name="payment_status"]').val(status);
}

/* ============================================================
   4. RECEIPT ID (generate + sales-style list with infinite scroll)
   ============================================================ */
function generateReceiptId() {
    $.ajax({
        url: '',
        method: 'POST',
        data: { action: 'get_next_receipt_id' },
        dataType: 'json',
        success: function (response) {
            $('input[name="receipt_id"]').val(response.receipt_id);
        },
        error: function () {
            const firmId = window.companyId || '';
            const prefix = `${firmId}EX`;
            const timestamp = Date.now().toString().slice(-6);
            $('input[name="receipt_id"]').val(`${prefix}${timestamp}`);
        }
    });
}

function escapeExchangeListHtml(value) {
    if (value == null) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatExchangeTxnDateParts(dateStr) {
    if (!dateStr) return { date: '', time: '' };
    const normalized = String(dateStr).includes('T') ? dateStr : String(dateStr).replace(' ', 'T');
    const d = new Date(normalized);
    if (Number.isNaN(d.getTime())) return { date: '', time: '' };
    const months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    const time = d.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        timeZone: 'Asia/Kolkata'
    });
    return {
        date: `${String(d.getDate()).padStart(2, '0')} ${months[d.getMonth()]}`,
        time
    };
}

function exchangePaymentBadgeHtml(paymentAmount, amount) {
    const paid = parseFloat(paymentAmount) || 0;
    const amt = parseFloat(amount) || 0;
    if (paid >= amt && amt > 0) {
        return '<span class="ge-pay-badge bg-green-100 text-green-700">Paid</span>';
    }
    if (paid > 0) {
        return '<span class="ge-pay-badge bg-yellow-100 text-yellow-700">Part</span>';
    }
    return '<span class="ge-pay-badge bg-rose-100 text-rose-700">Due</span>';
}

function buildExchangeTxnWeightCellHtml(item) {
    const items = Array.isArray(item.items) ? item.items : [];
    if (items.length > 1) {
        const rows = items.map(function (ri) {
            const w = (parseFloat(ri.weight) || 0).toFixed(3);
            return `<div class="text-[9px] font-bold text-slate-700 leading-none whitespace-nowrap">${w}<span class="text-[7px] font-normal ml-0.5">g</span></div>`;
        }).join('');
        return `<div class="space-y-0.5">${rows}</div>`;
    }
    const rcvWt = (parseFloat(item.received_weight) || 0).toFixed(3);
    return `<div class="text-[10px] font-bold text-slate-700 leading-none">${rcvWt}<span class="text-[8px] font-normal ml-0.5">g</span></div>`;
}

function buildExchangeTxnPurityCellHtml(item) {
    const items = Array.isArray(item.items) ? item.items : [];
    if (items.length > 1) {
        const rows = items.map(function (ri) {
            const p = (parseFloat(ri.purity) || 0).toFixed(2);
            return `<div class="text-[9px] font-semibold text-slate-500 leading-none whitespace-nowrap">${p}%</div>`;
        }).join('');
        return `<div class="space-y-0.5">${rows}</div>`;
    }
    const purity = (parseFloat(item.display_purity) || 0).toFixed(2);
    return `<div class="text-[10px] font-semibold text-slate-500 leading-none whitespace-nowrap">${purity}%</div>`;
}

function buildExchangeTxnFineCellHtml(item, rateDisplay, goldSuffix) {
    const items = Array.isArray(item.items) ? item.items : [];
    const rateLine = `<div class="text-[8px] font-medium text-slate-400 uppercase mt-0.5">@ &#8377;${rateDisplay}${escapeExchangeListHtml(goldSuffix)}</div>`;
    if (items.length > 1) {
        const rows = items.map(function (ri) {
            const f = (parseFloat(ri.fine) || 0).toFixed(3);
            return `<div class="text-[9px] font-semibold text-amber-600 leading-none whitespace-nowrap">${f}<span class="text-[7px] font-normal ml-0.5">g</span></div>`;
        }).join('');
        return `<div class="space-y-0.5">${rows}</div>${rateLine}`;
    }
    const fineWt = (parseFloat(item.fine_weight) || 0).toFixed(3);
    return `
        <div class="text-[10px] font-semibold text-amber-600 leading-none">${fineWt}<span class="text-[8px] font-normal ml-0.5">g</span></div>
        ${rateLine}`;
}

function buildExchangeTxnRowHtml(item, serial) {
    const isSilver = String(item.exchange_material || 'Gold').trim().toLowerCase() === 'silver';
    const coinIcon = isSilver
        ? '<i class="fas fa-coins text-slate-600 text-[9px] shrink-0" title="Silver (vault issue)" aria-hidden="true"></i>'
        : '<i class="fas fa-coins text-amber-600 text-[9px] shrink-0" title="Gold (vault issue)" aria-hidden="true"></i>';
    const dt = formatExchangeTxnDateParts(item.date_of_transaction);
    const diff = parseFloat(item.difference_weight) || 0;
    const diffColor = diff > 0 ? 'text-green-600' : (diff < 0 ? 'text-red-600' : 'text-gray-600');
    const diffPrefix = diff > 0 ? '+' : '';
    const goldSuffix = (window.EXCHANGE_LIST_CONFIG && window.EXCHANGE_LIST_CONFIG.goldRateSuffix) || '/10g';
    const issueWt = (parseFloat(item.delivered_weight) || 0).toFixed(3);
    const rateDisplay = Number(item.rate_display || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    const amountDisplay = Number(item.amount || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    const partyName = escapeExchangeListHtml((item.party_name || '').toUpperCase());
    const receiptId = escapeExchangeListHtml(item.receipt_id);

    return `
        <tr class="hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0 ge-txn-row">
            <td class="py-1.5 px-1 align-top text-center ge-serial-col">
                <span class="text-[9px] font-bold text-slate-400 tabular-nums">${serial}</span>
            </td>
            <td class="py-1.5 px-2 align-top group ge-id-col">
                <div class="text-[10px] font-bold text-blue-600 group-hover:underline truncate flex items-center gap-0.5">
                    <span class="truncate">#${receiptId}</span>${coinIcon}
                </div>
                <div class="text-[8px] font-semibold text-slate-400 leading-tight tabular-nums whitespace-nowrap">${escapeExchangeListHtml(dt.date)} · ${escapeExchangeListHtml(dt.time)}</div>
            </td>
            <td class="py-1.5 px-2 align-top ge-party-col">
                <div class="text-[10px] font-semibold text-slate-800 truncate uppercase" title="${partyName}">${partyName}</div>
            </td>
            <td class="py-1.5 px-2 align-top text-right ge-rcv-col">
                ${buildExchangeTxnWeightCellHtml(item)}
            </td>
            <td class="py-1.5 px-2 align-top text-right ge-purity-col">
                ${buildExchangeTxnPurityCellHtml(item)}
            </td>
            <td class="py-1.5 px-2 align-top text-right ge-fine-col">
                ${buildExchangeTxnFineCellHtml(item, rateDisplay, goldSuffix)}
            </td>
            <td class="py-1.5 px-2 align-top text-right ge-issue-col">
                <div class="text-[10px] font-semibold text-slate-600 leading-none">${issueWt}<span class="text-[8px] font-normal ml-0.5">g</span></div>
                <div class="text-[8px] font-bold ${diffColor} uppercase mt-0.5">${diffPrefix}${diff.toFixed(3)}</div>
            </td>
            <td class="py-1.5 px-2 align-top text-right ge-amount-col">
                <div class="text-[10px] font-bold text-slate-800 leading-none">&#8377;${amountDisplay}</div>
                <div class="mt-1 flex justify-end">${exchangePaymentBadgeHtml(item.payment_amount, item.amount)}</div>
            </td>
            <td class="py-1.5 px-2 align-top ge-action-col whitespace-nowrap">
                <div class="flex items-center justify-end gap-0.5">
                    <button type="button" onclick="event.stopPropagation(); loadTransaction(${parseInt(item.id, 10)});"
                        class="ge-action-btn text-blue-500 hover:text-blue-700" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button"
                        onclick="event.stopPropagation(); openExchangeReceiptPrint(${parseInt(item.id, 10)}); return false;"
                        class="ge-action-btn print-exchange-receipt text-emerald-600 hover:text-emerald-800"
                        data-id="${parseInt(item.id, 10)}" title="Print Receipt">
                        <i class="fas fa-print"></i>
                    </button>
                </div>
            </td>
        </tr>`;
}

let exchangeTxnListState = { offset: 0, hasMore: false, loading: false };
let receiptListState = { offset: 0, hasMore: true, loading: false, term: '' };

function initExchangeTxnListState() {
    const cfg = window.EXCHANGE_LIST_CONFIG || {};
    exchangeTxnListState = {
        offset: cfg.initialOffset || 0,
        hasMore: !!cfg.hasMore,
        loading: false
    };
}

function setTxnListLoader(show) {
    const $loader = $('#geTxnListLoader');
    if (show) {
        if ($loader.length === 0) {
            $('#recentTransactionList').append(
                '<tr id="geTxnListLoader"><td colspan="9" class="py-2 text-center text-[10px] text-slate-400"><i class="fas fa-spinner fa-spin mr-1"></i>Loading more...</td></tr>'
            );
        }
    } else {
        $loader.remove();
    }
}

function loadMoreExchangeTransactions() {
    const cfg = window.EXCHANGE_LIST_CONFIG;
    if (!cfg || exchangeTxnListState.loading || !exchangeTxnListState.hasMore) return;

    exchangeTxnListState.loading = true;
    setTxnListLoader(true);

    $.ajax({
        url: '',
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'get_exchange_list',
            start_date: cfg.startDate,
            end_date: cfg.endDate,
            search: cfg.search || '',
            offset: exchangeTxnListState.offset,
            limit: cfg.pageSize || 50
        },
        success: function (res) {
            setTxnListLoader(false);
            exchangeTxnListState.loading = false;
            if (!res || res.status !== 'success' || !Array.isArray(res.items)) return;

            const $tbody = $('#recentTransactionList');
            let serial = $tbody.find('tr.ge-txn-row').length;
            res.items.forEach(function (item) {
                serial += 1;
                $tbody.append(buildExchangeTxnRowHtml(item, serial));
            });
            exchangeTxnListState.offset += res.items.length;
            exchangeTxnListState.hasMore = !!res.has_more;
        },
        error: function () {
            setTxnListLoader(false);
            exchangeTxnListState.loading = false;
        }
    });
}

function initExchangeTxnInfiniteScroll() {
    const scrollEl = document.getElementById('geTxnScroll');
    if (!scrollEl) return;
    scrollEl.addEventListener('scroll', function () {
        if (exchangeTxnListState.loading || !exchangeTxnListState.hasMore) return;
        if (this.scrollTop + this.clientHeight >= this.scrollHeight - 60) {
            loadMoreExchangeTransactions();
        }
    });
}

function exchangeReceiptMaterialIcon(item) {
    const isSilver = String(item.exchange_material || 'Gold').trim().toLowerCase() === 'silver';
    if (isSilver) {
        return '<span class="inline-flex items-center justify-center w-4 h-4 rounded bg-slate-100 text-slate-700 border border-slate-200 shrink-0" title="Silver"><i class="fas fa-coins text-[7px]"></i></span>';
    }
    return '<span class="inline-flex items-center justify-center w-4 h-4 rounded bg-amber-100 text-amber-700 border border-amber-200 shrink-0" title="Gold"><i class="fas fa-coins text-[7px]"></i></span>';
}

function appendExchangeReceiptListItems(items) {
    const $receiptList = $('#receiptList');
    items.forEach(function (item) {
        const row = document.createElement('div');
        row.className = 'receipt-list-item px-2 py-1 border-b border-gray-100 hover:bg-blue-50 cursor-pointer text-left receipt-item';
        row.setAttribute('data-receipt-id', item.receipt_id);
        const dt = item.date_of_transaction ? String(item.date_of_transaction).split(/[\sT]/)[0] : '';
        const party = (item.party_name || '').trim() || '—';
        const wtStr = (parseFloat(item.received_weight) || 0).toFixed(3) + ' g';
        const amtStr = '₹' + Number(item.amount || 0).toLocaleString('en-IN');
        const tip = [item.receipt_id, dt, party, wtStr, amtStr].join(' · ');
        const modeIcon = exchangeReceiptMaterialIcon(item);
        row.innerHTML = `
            <div class="flex justify-between items-center gap-1">
                <span class="flex items-center gap-1 min-w-0">${modeIcon}<span class="font-bold text-blue-600 truncate">${escapeExchangeListHtml(item.receipt_id)}</span></span>
                <span class="text-gray-400 shrink-0">${escapeExchangeListHtml(dt)}</span>
            </div>`;
        const nameEl = document.createElement('div');
        nameEl.className = 'text-gray-800 truncate mt-px font-medium';
        nameEl.textContent = party;
        nameEl.title = tip;
        row.appendChild(nameEl);
        const wAmtEl = document.createElement('div');
        wAmtEl.className = 'text-gray-600 mt-px';
        wAmtEl.textContent = wtStr + ' · ' + amtStr;
        wAmtEl.title = tip;
        row.appendChild(wAmtEl);
        row.addEventListener('click', function () {
            selectReceipt(item.receipt_id);
        });
        $receiptList[0].appendChild(row);
    });
}

function setReceiptListLoader(show) {
    const $loader = $('#receiptListLoader');
    if (show) {
        if ($loader.length === 0) {
            $('#receiptList').append('<div id="receiptListLoader" class="py-2 px-2 text-center text-gray-400"><i class="fas fa-spinner fa-spin"></i></div>');
        }
    } else {
        $loader.remove();
    }
}

function fetchExchangeReceiptList(append) {
    if (receiptListState.loading) return;
    if (append && !receiptListState.hasMore) return;

    if (!append) {
        receiptListState.offset = 0;
        receiptListState.hasMore = true;
        $('#receiptList').empty();
    }

    receiptListState.loading = true;
    setReceiptListLoader(true);

    $.ajax({
        url: '',
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'get_exchange_receipt_list',
            term: receiptListState.term,
            offset: receiptListState.offset,
            limit: append ? 50 : 100
        },
        success: function (res) {
            setReceiptListLoader(false);
            receiptListState.loading = false;
            if (!res || res.status !== 'success') {
                if (!append) {
                    $('#receiptList').html('<div class="py-2 px-2 text-center text-red-600">Error loading list</div>');
                }
                return;
            }
            if (!append && (!res.items || res.items.length === 0)) {
                $('#receiptList').html('<div class="py-2 px-2 text-center text-gray-500">No exchanges found</div>');
                receiptListVisible = false;
                receiptListState.hasMore = false;
                return;
            }
            if (res.items && res.items.length > 0) {
                appendExchangeReceiptListItems(res.items);
                receiptListState.offset += res.items.length;
            }
            receiptListState.hasMore = !!res.has_more;
            receiptListVisible = true;
            $('#receiptList').removeClass('hidden');
            currentReceiptIndex = -1;
        },
        error: function () {
            setReceiptListLoader(false);
            receiptListState.loading = false;
            if (!append) {
                $('#receiptList').html('<div class="py-2 px-2 text-center text-red-600">Error loading list</div>');
            }
        }
    });
}

function showExchangeReceiptList() {
    receiptListState.term = '';
    $('#receiptList').removeClass('hidden');
    fetchExchangeReceiptList(false);
}

function selectReceipt(receiptId) {
    $('#receiptId').val(receiptId);
    $('#receiptList').addClass('hidden');
    receiptListVisible = false;
    currentReceiptIndex = -1;
    loadTransactionByReceiptId(receiptId);
}

/* ============================================================
   5. PARTY SEARCH / SELECT / DUES / CLEAR
   ============================================================ */
let partySearchXhr = null;
let partySearchToken = 0;
const partySearchCache = new Map();

function searchParties(searchTerm) {
    const term = searchTerm.trim().toLowerCase();

    // Serve instantly from cache if we've already fetched this exact term
    // (very common when a user backspaces then retypes the same letters).
    if (partySearchCache.has(term)) {
        displayPartyList(partySearchCache.get(term));
        return;
    }

    // Abort any still-in-flight request so a slow, older response can't
    // arrive late and overwrite a newer/faster one (that "flicker" is a big
    // part of why the list feels late).
    if (partySearchXhr && partySearchXhr.readyState !== 4) {
        partySearchXhr.abort();
    }

    const myToken = ++partySearchToken;
    partySearchXhr = $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'search_parties',
            term: searchTerm
        },
        dataType: 'json',
        success: function (parties) {
            if (myToken !== partySearchToken) return; // stale response, ignore
            if (partySearchCache.size > 50) partySearchCache.clear();
            partySearchCache.set(term, parties);
            displayPartyList(parties);
        },
        error: function (jqXHR, textStatus) {
            if (textStatus === 'abort') return;
            console.error('Error searching parties');
        }
    });
}

function displayPartyList(parties) {
    const $partyList = $('#partyList');
    $partyList.empty();
    currentIndex = -1;

    const searchTerm = $('#partyNameInput').val();

    if (parties.length === 0) {
        const $createItem = $('<div>')
            .addClass('px-3 py-2 hover:bg-green-50 cursor-pointer border-b border-gray-100 transition-colors party-item bg-green-50')
            .attr('data-index', 0)
            .attr('data-create-new', 'true')
            .attr('data-name', searchTerm)
            .html(`
                <div class="flex items-center">
                    <i class="fas fa-plus-circle text-green-600 mr-2"></i>
                    <div>
                        <div class="font-semibold text-sm text-green-700">Create New Party</div>
                        <div class="text-xs text-green-600">"${searchTerm}"</div>
                    </div>
                </div>
            `)
            .click(function () {
                createNewPartyQuick(searchTerm);
            });
        $partyList.append($createItem);
        $partyList.removeClass('hidden');
        partyListVisible = true;
        return;
    }

    parties.forEach(function (party, index) {
        const $item = $('<div>')
            .addClass('px-3 py-2 hover:bg-yellow-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors party-item')
            .attr('data-index', index)
            .attr('data-id', party.id)
            .attr('data-name', party.party_name)
            .attr('data-address', party.address || '')
            .html(`
                <div class="flex justify-between items-start">
                    <div class="font-bold text-[11px] text-slate-800 uppercase tracking-tight">${party.party_name}</div>
                    <div class="text-[10px] text-slate-400 font-medium truncate max-w-[120px]">${party.address || 'No address'}</div>
                </div>
                <div class="flex items-center space-x-3 mt-1">
                    ${parseFloat(party.total_due_amount) != 0 ? `<div class="text-[10px] text-rose-600 font-bold tracking-tight"><i class="fas fa-wallet mr-1 opacity-70"></i>₹${parseFloat(party.total_due_amount).toLocaleString()}</div>` : ''}
                    ${parseFloat(party.total_due_gold) != 0 ? `<div class="text-[10px] text-amber-600 font-bold tracking-tight"><i class="fas fa-coins mr-1 opacity-70"></i>${parseFloat(party.total_due_gold).toFixed(3)}g</div>` : ''}
                    ${parseFloat(party.total_due_silver) != 0 ? `<div class="text-[10px] text-slate-500 font-bold tracking-tight"><i class="fas fa-compact-disc mr-1 opacity-70"></i>${parseFloat(party.total_due_silver).toFixed(3)}g</div>` : ''}
                </div>
            `)
            .click(function () {
                selectParty(party);
            });
        $partyList.append($item);
    });

    const $createItem = $('<div>')
        .addClass('px-3 py-2 hover:bg-green-50 cursor-pointer transition-colors party-item bg-green-50 border-t-2 border-green-200')
        .attr('data-index', parties.length)
        .attr('data-create-new', 'true')
        .attr('data-name', searchTerm)
        .html(`
            <div class="flex items-center">
                <i class="fas fa-plus-circle text-green-600 mr-2"></i>
                <div class="font-semibold text-sm text-green-700">Create New Party "${searchTerm}"</div>
            </div>
        `)
        .click(function () {
            createNewPartyQuick(searchTerm);
        });
    $partyList.append($createItem);

    $partyList.removeClass('hidden');
    partyListVisible = true;
}

function selectParty(party) {
    selectedPartyName = party.party_name;
    $('#partyNameInput').val(party.party_name).addClass('border-green-500');
    $('#partyId').val(party.id); // keep hidden party_id in sync (used by clear-party feature)
    $('#partyList').addClass('hidden');
    partyListVisible = false;
    currentIndex = -1;

    $('#clearPartyBtn').removeClass('hidden');

    loadPartyDues(party.party_name);
    $('.received-weight').first().focus();
}

function loadPartyDues(partyName) {
    if ($('#partyDueInfoInline').length === 0) {
        return;
    }

    if (!partyName || partyName.trim() === '') {
        $('#partyDueInfoInline').addClass('hidden');
        updatePaymentStatus();
        return;
    }

    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'get_party_dues',
            party_name: partyName
        },
        dataType: 'json',
        success: function (data) {
            if (data && (parseFloat(data.due_amount) > 0 || parseFloat(data.due_gold) > 0)) {
                $('#dueAmountValueInline').text('₹' + parseFloat(data.due_amount).toFixed(2));
                $('#dueGoldValueInline').text(parseFloat(data.due_gold).toFixed(3) + 'g');
                $('#partyDueInfoInline').removeClass('hidden');
            } else {
                $('#partyDueInfoInline').addClass('hidden');
            }
            updatePaymentStatus();
        },
        error: function () {
            $('#partyDueInfoInline').addClass('hidden');
            updatePaymentStatus();
        }
    });
}

function showAddPartyModal() {
    SharedPartyHandler.showAddPartyModal({
        onSuccess: function (response, partyData) {
            $('#partyNameInput').val(partyData.party_name);
            $('#partyId').val(response.party_id);
            selectedPartyName = partyData.party_name;

            $('#partyNameInput').addClass('border-green-500');
            $('#clearPartyBtn').removeClass('hidden');
            setTimeout(() => $('#partyNameInput').removeClass('border-green-500'), 2000);

            loadPartyDues(partyData.party_name);
            $('.received-weight').first().focus();
        }
    });
}

function createNewPartyQuick(partyName) {
    if (!partyName || partyName.trim() === '') {
        return;
    }

    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'save_party',
            party_name: partyName.trim(),
            address: '',
            contact_no: ''
        },
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true
                });

                Toast.fire({
                    icon: 'success',
                    title: `Party "${partyName}" created!`
                });

                $('#partyNameInput').val(partyName).addClass('border-green-500');
                if (response.party_id) {
                    $('#partyId').val(response.party_id);
                }
                selectedPartyName = partyName;
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                $('#clearPartyBtn').removeClass('hidden');

                $('.received-weight').first().focus();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message,
                    width: '320px',
                    confirmButtonColor: '#ef4444'
                });
            }
        },
        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to create party',
                width: '320px',
                confirmButtonColor: '#ef4444'
            });
        }
    });
}

/** Clear Party Selection Feature (was clear_party.js) */
function clearPartySelection() {
    $('#partyNameInput').val('').removeClass('border-green-500');
    $('#partyId').val('');
    $('#partyDueInfoInline').addClass('hidden');
    $('#clearPartyBtn').addClass('hidden');
    if (typeof selectedPartyName !== 'undefined') {
        selectedPartyName = '';
    }
    $('#partyNameInput').focus();

    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 1500,
        timerProgressBar: true
    });
    Toast.fire({ icon: 'info', title: 'Party cleared' });
}

/* ============================================================
   6. SAVE / LOAD / DELETE TRANSACTION (multi-item — the only path)
   ============================================================ */
let exchangeSaveInProgress = false;

function setExchangeSaveLoading(loading) {
    const $btn = $('#submitBtn');
    const $icon = $('#submitIcon');
    const $text = $('#submitText');
    const isUpdate = !!$('input[name="transaction_id"]').val();
    const defaultLabel = isUpdate ? 'Update' : 'Save';
    const defaultIcon = isUpdate ? 'fa-save' : 'fa-exchange-alt';

    exchangeSaveInProgress = loading;
    $btn.prop('disabled', loading);
    $('#deleteBtn, #resetFormBtn').prop('disabled', loading);

    if (loading) {
        $icon.removeClass('fa-save fa-exchange-alt').addClass('fa-spinner fa-spin');
        $text.text(isUpdate ? 'Updating...' : 'Saving...');
        $btn.addClass('opacity-75 cursor-not-allowed');
    } else {
        $icon.removeClass('fa-spinner fa-spin').addClass(defaultIcon);
        $text.text(defaultLabel);
        $btn.removeClass('opacity-75 cursor-not-allowed');
    }
}

function saveTransaction() {
    if (exchangeSaveInProgress) return;
    setExchangeSaveLoading(true);

    updatePaymentStatus();

    const receivedItems = [];
    const rows = document.querySelectorAll('#receivedItemsTable .received-item-row');

    rows.forEach(row => {
        const weight = parseFloat(row.querySelector('.received-weight').value) || 0;
        const purity = parseFloat(row.querySelector('.received-purity').value) || 0;
        const fine = parseFloat(row.querySelector('.received-fine').value) || 0;
        const matSel = row.querySelector('.received-material');
        const material = matSel ? String(matSel.value || 'Gold').trim() : 'Gold';

        receivedItems.push({ weight, purity, fine, material });
    });

    const metalsWithWeight = new Set();
    receivedItems.forEach(it => {
        if (it.weight > 0) metalsWithWeight.add(it.material || 'Gold');
    });
    if (metalsWithWeight.size > 1) {
        setExchangeSaveLoading(false);
        Swal.fire({
            icon: 'error',
            title: 'Mixed metals',
            text: 'All received lines with weight must be the same metal: all Gold or all Silver.',
            confirmButtonColor: '#EAB308'
        });
        return;
    }

    calculateTotals();
    updatePaymentStatus();

    // Header purity must match entered line purities (server also validates from received_items).
    let purityWeightSum = 0;
    let purityWeightedSum = 0;
    receivedItems.forEach(it => {
        if (it.weight > 0) {
            purityWeightSum += it.weight;
            purityWeightedSum += it.weight * it.purity;
        }
    });
    if (purityWeightSum > 0) {
        document.getElementById('purity').value = (purityWeightedSum / purityWeightSum).toFixed(2);
    }

    const metalNorm = (m) => (String(m || '').toLowerCase() === 'silver' ? 'Silver' : 'Gold');
    let vaultMetal = 'Gold';
    const wSet = new Set();
    receivedItems.forEach(it => {
        if (it.weight > 0) wSet.add(metalNorm(it.material));
    });
    if (wSet.size === 1) {
        vaultMetal = Array.from(wSet)[0];
    } else if (wSet.size === 0 && receivedItems.length > 0) {
        vaultMetal = metalNorm(receivedItems[0].material);
    }
    $('#exchangeMaterialHidden').val(vaultMetal);

    const postData = {};
    $('#exchangeForm').serializeArray().forEach(function (x) {
        if (x.name === 'exchange_material') return;
        postData[x.name] = x.value;
    });
    postData.exchange_material = vaultMetal;
    postData.received_items = JSON.stringify(receivedItems);

    $.ajax({
        url: '',
        method: 'POST',
        data: postData,
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    text: response.message,
                    width: '320px',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-print mr-1"></i>PRINT',
                    cancelButtonText: 'CLOSE',
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#64748b'
                }).then((result) => {
                    if (result.isConfirmed && response.transaction_id) {
                        openExchangeReceiptPrint(response.transaction_id);
                    }
                    window.location.href = window.location.pathname + '?focus=party';
                });
            } else {
                setExchangeSaveLoading(false);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message,
                    width: '320px',
                    confirmButtonColor: '#ef4444'
                });
            }
        },
        error: function () {
            setExchangeSaveLoading(false);
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Operation failed',
                width: '320px',
                confirmButtonColor: '#ef4444'
            });
        }
    });
}

/**
 * Loads a transaction (with all its Gold/Silver item rows) and populates the form for editing.
 * Works whether you have the receipt_id (from receipt search) or the numeric id (from the
 * transaction list's Edit button) — pass whichever you have via `by`.
 *
 * FIX: the transaction list's Edit button used to call a different, older loader that only
 * pulled aggregated totals (no item rows), so editing from the list silently dropped any
 * second/third Gold or Silver line. It now goes through this same multi-item loader as the
 * receipt-search flow.
 */
function loadTransaction(idOrReceiptId) {
    const isNumericId = /^\d+$/.test(String(idOrReceiptId));
    const ajaxData = isNumericId
        ? { action: 'get_exchange_by_id', id: idOrReceiptId }
        : { action: 'get_exchange_by_receipt_id', receipt_id: idOrReceiptId };

    $.ajax({
        url: '',
        method: 'POST',
        data: ajaxData,
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                populateFormWithTransaction(response.data);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message || 'Transaction not found',
                    confirmButtonColor: '#EAB308'
                });
            }
        },
        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to load transaction',
                confirmButtonColor: '#EAB308'
            });
        }
    });
}

// Kept as a clearly-named alias for the receipt-search flow.
function loadTransactionByReceiptId(receiptId) {
    loadTransaction(receiptId);
}

function populateFormWithTransaction(transaction) {
    $('input[name="transaction_id"]').val(transaction.id);
    $('input[name="receipt_id"]').val(transaction.receipt_id);
    $('input[name="date_of_transaction"]').val(String(transaction.date_of_transaction).replace(' ', 'T'));
    $('#partyNameInput').val(transaction.party_name).addClass('border-green-500');
    $('#partyId').val(transaction.party_id);
    $('#clearPartyBtn').removeClass('hidden');

    // Rebuild received item rows
    const table = document.getElementById('receivedItemsTable');
    table.innerHTML = '';

    const buildRow = (rowNum, weight, purity, fine, material) => {
        const m = (material && String(material).toLowerCase() === 'silver') ? 'Silver' : 'Gold';
        const auSel = m === 'Gold' ? 'selected' : '';
        const agSel = m === 'Silver' ? 'selected' : '';
        return `
            <tr class="received-item-row">
                <td class="px-2 py-1.5 border-b text-gray-700 font-bold item-number" style="width: 40px;">${rowNum}</td>
                <td class="px-2 py-1.5 border-b" style="width: 64px;">
                    <select class="w-full px-1 py-1 text-[10px] font-bold text-gray-800 bg-white border border-gray-200 rounded received-material">
                        <option value="Gold" ${auSel}>Gold</option>
                        <option value="Silver" ${agSel}>Silver</option>
                    </select>
                </td>
                <td class="px-2 py-1.5 border-b">
                    <input type="number" step="0.001" value="${weight}" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 received-weight" placeholder="0.000" required>
                </td>
                <td class="px-2 py-1.5 border-b">
                    <input type="number" step="0.01" value="${purity}" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-yellow-400 focus:border-yellow-400 received-purity" placeholder="0.00" required>
                </td>
                <td class="px-2 py-1.5 border-b">
                    <input type="number" step="0.001" value="${fine}" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-emerald-400 focus:border-emerald-400 received-fine" placeholder="0.000">
                </td>
                <td class="px-2 py-1.5 border-b text-center" style="width: 48px;">
                    <button type="button" onclick="removeReceivedItem(this)" class="text-red-600 hover:text-red-800 text-sm">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            </tr>
        `;
    };

    if (transaction.received_items && transaction.received_items.length > 0) {
        transaction.received_items.forEach((item, index) => {
            table.insertAdjacentHTML('beforeend', buildRow(index + 1, item.weight, item.purity, item.fine, item.material));
        });
    } else if (transaction.received_weight && parseFloat(transaction.received_weight) > 0) {
        // Legacy single-line transaction (no exchange_items rows saved)
        table.insertAdjacentHTML('beforeend', buildRow(
            1, transaction.received_weight, transaction.purity, transaction.fine_weight, transaction.exchange_material
        ));
    } else {
        addReceivedItem();
    }

    // Issue / vault metal
    const issueWeightInput = document.getElementById('issueWeightInput');
    if (issueWeightInput) {
        issueWeightInput.value = transaction.delivered_weight || 0;
    }

    const em = (transaction.exchange_material && String(transaction.exchange_material).toLowerCase() === 'silver') ? 'Silver' : 'Gold';
    $('#exchangeMaterialHidden').val(em);
    $('#issueMetalDisplay').text(em === 'Silver' ? 'Fine silver' : 'Fine gold');

    // Payment fields
    $('#rate').val(transaction.rate || 0);
    $('#amount').val(transaction.amount || 0);
    $('#paymentAmount').val(transaction.payment_amount || 0);
    const pm = transaction.payment_method || 'Cash';
    const pmSelect = $('select[name="payment_method"]');
    if (pmSelect.find(`option[value="${pm}"]`).length) {
        pmSelect.val(pm);
    } else if (pm === 'Bank' || pm === 'Bank Transfer' || pm === 'NEFT' || pm === 'RTGS') {
        pmSelect.val('Bank');
    } else {
        pmSelect.val('Cash');
    }
    $('input[name="payment_status"]').val(transaction.payment_status || 'Due');
    $('input[name="narration"]').val(transaction.narration || '');

    attachCalculationListeners();
    calculateTotals(false);
    loadPartyDues(transaction.party_name);
    updatePaymentStatus();

    $('#deleteBtn').removeClass('hidden');
    $('#submitText').text('Update');
    $('#submitIcon').removeClass('fa-exchange-alt').addClass('fa-save');

    $('html, body').animate({ scrollTop: $('#exchangeForm').offset().top - 100 }, 500);
}

function deleteTransaction(id) {
    geSwalCompact({
        title: 'Are you sure?',
        text: 'This will delete the transaction and restore the stock!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#EF4444',
        cancelButtonColor: '#6B7280',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'delete_transaction',
                    id: id
                },
                dataType: 'json',
                success: function (response) {
                    if (response.status === 'success') {
                        geSwalCompact({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message,
                            confirmButtonColor: '#EAB308'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        geSwalCompact({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message,
                            confirmButtonColor: '#EAB308'
                        });
                    }
                },
                error: function () {
                    geSwalCompact({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Failed to delete transaction',
                        confirmButtonColor: '#EAB308'
                    });
                }
            });
        }
    });
}

function resetForm() {
    $('#exchangeForm')[0].reset();
    $('input[name="transaction_id"]').val('');
    $('#partyId').val('');
    generateReceiptId();

    const now = new Date();
    const indianTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
    const year = indianTime.getFullYear();
    const month = String(indianTime.getMonth() + 1).padStart(2, '0');
    const day = String(indianTime.getDate()).padStart(2, '0');
    const hours = String(indianTime.getHours()).padStart(2, '0');
    const minutes = String(indianTime.getMinutes()).padStart(2, '0');
    $('input[name="date_of_transaction"]').val(`${year}-${month}-${day}T${hours}:${minutes}`);

    // Reset received items table back to a single empty row
    const table = document.getElementById('receivedItemsTable');
    table.innerHTML = `
        <tr class="received-item-row group">
            <td class="px-2 py-1 border-b text-gray-500 font-bold item-number w-8">1</td>
            <td class="px-2 py-1 border-b min-w-[4.5rem]">
                <select class="w-full px-1 py-1 text-[10px] font-bold text-gray-800 bg-white border border-gray-200 rounded received-material compact-input">
                    <option value="Gold" selected>Gold</option>
                    <option value="Silver">Silver</option>
                </select>
            </td>
            <td class="px-2 py-1 border-b">
                <input type="number" step="0.001" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 received-weight" placeholder="0.000" required>
            </td>
            <td class="px-2 py-1 border-b">
                <input type="number" step="0.01" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-yellow-400 focus:border-yellow-400 received-purity" placeholder="0.00" required>
            </td>
            <td class="px-2 py-1 border-b">
                <input type="number" step="0.001" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-emerald-400 focus:border-emerald-400 received-fine" placeholder="0.000">
            </td>
            <td class="px-2 py-1 border-b text-center w-10">
                <button type="button" onclick="removeReceivedItem(this)" class="text-red-400 hover:text-red-600 text-xs transition-colors">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
        </tr>`;
    attachCalculationListeners();

    $('#issueWeightInput').val('');
    $('#exchangeMaterialHidden').val('Gold');
    $('#issueMetalDisplay').removeClass('text-red-600 font-bold').text('Fine gold');

    $('#partyDueInfoInline').addClass('hidden');
    $('#clearPartyBtn').addClass('hidden');
    $('#partyNameInput').removeClass('border-green-500');
    selectedPartyName = '';

    $('#deleteBtn').addClass('hidden');
    setExchangeSaveLoading(false);
    $('#submitText').text('Save');
    $('#submitIcon').removeClass('fa-save fa-spinner fa-spin').addClass('fa-exchange-alt');

    $('#differenceWeight').removeClass('text-red-600 text-green-600 font-bold');

    calculateTotals();
    updatePaymentStatus();
}

/* ============================================================
   7. PAGE INIT
   ============================================================ */
let searchTimeout = null;
let partyListVisible = false;
let currentIndex = -1;
let selectedPartyName = '';
let receiptListVisible = false;
let currentReceiptIndex = -1;

$(document).ready(function () {
    // Inject the "clear party" button once, next to the party name field
    if ($('#clearPartyBtn').length === 0) {
        const clearBtn = `<button type="button" id="clearPartyBtn" onclick="clearPartySelection()" class="hidden absolute inset-y-0 right-0 pr-2 flex items-center text-gray-400 hover:text-red-600 transition-colors z-10" title="Clear party">
            <i class="fas fa-times-circle text-lg"></i>
        </button>`;
        $('#partyNameInput').parent().addClass('relative').append(clearBtn);
    }

    // Receipt ID + date/time init
    generateReceiptId();

    const now = new Date();
    const indianTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
    const year = indianTime.getFullYear();
    const month = String(indianTime.getMonth() + 1).padStart(2, '0');
    const day = String(indianTime.getDate()).padStart(2, '0');
    const hours = String(indianTime.getHours()).padStart(2, '0');
    const minutes = String(indianTime.getMinutes()).padStart(2, '0');
    $('input[name="date_of_transaction"]').val(`${year}-${month}-${day}T${hours}:${minutes}`);

    setTimeout(function () {
        $('#partyNameInput').focus();
    }, 100);

    updatePaymentStatus();
    attachCalculationListeners();
    calculateTotals();
    initExchangeTxnListState();
    initExchangeTxnInfiniteScroll();

    // Rate input recalculates amount
    $('#rate').on('input', calculateAmount);

    // Paid amount input recalculates payment status
    $('#paymentAmount').on('input', updatePaymentStatus);

    /* ---------- Party name autocomplete ---------- */
    $('#partyNameInput').on('input', function () {
        const searchTerm = $(this).val();
        const value = searchTerm.trim();

        $('#clearPartyBtn').toggleClass('hidden', value.length === 0);

        if (searchTerm !== selectedPartyName) {
            selectedPartyName = '';
            $('#partyNameInput').removeClass('border-green-500');
        }

        if (searchTerm.length < 1) {
            $('#partyList').addClass('hidden').empty();
            $('#partyDueInfoInline').addClass('hidden');
            partyListVisible = false;
            currentIndex = -1;
            return;
        }

        clearTimeout(searchTimeout);
        // Short debounce so the list feels near-instant while typing, but still
        // avoids firing a request on every single keystroke of a fast typist.
        searchTimeout = setTimeout(function () {
            searchParties(searchTerm);
        }, 120);
    });

    $('#partyNameInput').on('keydown', function (e) {
        if (e.altKey && (e.key === 'a' || e.key === 'A')) {
            e.preventDefault();
            e.stopPropagation();
            showAddPartyModal();
            return;
        }

        const partyItems = document.querySelectorAll('#partyList .party-item');

        if (e.key === 'ArrowDown' && partyListVisible && partyItems.length > 0) {
            e.preventDefault();
            currentIndex = currentIndex < 0 ? 0 : Math.min(currentIndex + 1, partyItems.length - 1);
            updatePartyHighlight();
        } else if (e.key === 'ArrowUp' && partyListVisible && partyItems.length > 0) {
            e.preventDefault();
            currentIndex = currentIndex <= 0 ? -1 : Math.max(currentIndex - 1, 0);
            updatePartyHighlight();
        } else if (e.key === 'Enter' && partyListVisible && currentIndex >= 0 && partyItems.length > 0) {
            e.preventDefault();
            const selectedItem = partyItems[currentIndex];
            const isCreateNew = selectedItem.hasAttribute('data-create-new');

            if (isCreateNew) {
                const term = selectedItem.getAttribute('data-name') || $('#partyNameInput').val().trim();
                createNewPartyQuick(term);
            } else {
                selectParty({
                    id: selectedItem.getAttribute('data-id'),
                    party_name: selectedItem.getAttribute('data-name'),
                    address: selectedItem.getAttribute('data-address')
                });
            }
        }
    });

    function updatePartyHighlight() {
        const partyItems = document.querySelectorAll('#partyList .party-item');
        partyItems.forEach((item, index) => {
            if (index === currentIndex) {
                item.classList.add('bg-yellow-50', 'border-yellow-300');
                item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                item.classList.remove('bg-yellow-50', 'border-yellow-300');
            }
        });
    }

    /* ---------- Receipt ID list (sales-style) ---------- */
    $('#showReceiptListBtn, #receiptId').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        showExchangeReceiptList();
    });

    $('#receiptList').on('scroll', function () {
        if (receiptListState.loading || !receiptListState.hasMore) return;
        const el = this;
        if (el.scrollTop + el.clientHeight >= el.scrollHeight - 40) {
            fetchExchangeReceiptList(true);
        }
    });

    $('#receiptId').on('keydown', function (e) {
        const receiptItems = document.querySelectorAll('#receiptList .receipt-item');

        if (e.key === 'ArrowDown' && receiptListVisible && receiptItems.length > 0) {
            e.preventDefault();
            currentReceiptIndex = currentReceiptIndex < 0 ? 0 : Math.min(currentReceiptIndex + 1, receiptItems.length - 1);
            updateReceiptHighlight();
        } else if (e.key === 'ArrowUp' && receiptListVisible && receiptItems.length > 0) {
            e.preventDefault();
            currentReceiptIndex = currentReceiptIndex <= 0 ? -1 : Math.max(currentReceiptIndex - 1, 0);
            updateReceiptHighlight();
        } else if (e.key === 'Enter' && receiptListVisible && currentReceiptIndex >= 0 && receiptItems.length > 0) {
            e.preventDefault();
            const selectedItem = receiptItems[currentReceiptIndex];
            selectReceipt(selectedItem.getAttribute('data-receipt-id'));
        } else if (e.key === 'Escape') {
            $('#receiptList').addClass('hidden');
            receiptListVisible = false;
            currentReceiptIndex = -1;
        }
    });

    function updateReceiptHighlight() {
        const receiptItems = document.querySelectorAll('#receiptList .receipt-item');
        receiptItems.forEach((item, index) => {
            if (index === currentReceiptIndex) {
                item.classList.add('bg-yellow-50', 'border-yellow-300');
                item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                item.classList.remove('bg-yellow-50', 'border-yellow-300');
            }
        });
    }

    /* ---------- Click-outside handlers ---------- */
    $(document).click(function (e) {
        if (!$(e.target).closest('#partyNameInput, #partyList').length) {
            $('#partyList').addClass('hidden');
            partyListVisible = false;
            currentIndex = -1;
        }
        if (!$(e.target).closest('#receiptId, #receiptList, #showReceiptListBtn').length) {
            $('#receiptList').addClass('hidden');
            receiptListVisible = false;
            currentReceiptIndex = -1;
        }
    });

    /* ---------- Form actions ---------- */
    $('#exchangeForm').submit(function (e) {
        e.preventDefault();
        saveTransaction();
    });

    $('#resetFormBtn').click(function () {
        resetForm();
    });

    $('#deleteBtn').click(function () {
        const transactionId = $('input[name="transaction_id"]').val();
        if (transactionId) {
            deleteTransaction(transactionId);
        }
    });

    // Print button delegated click (list rows use inline onclick already, this is a safety net)
    $(document).on('click', '.print-exchange-receipt', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const transactionId = $(this).attr('data-id') || $(this).data('id');
        if (transactionId) {
            openExchangeReceiptPrint(transactionId);
        }
    });

    /* ---------- Date range filter validation ---------- */
    $('#startDate, #endDate').on('change', function () {
        const startDate = new Date($('#startDate').val());
        const endDate = new Date($('#endDate').val());

        if (startDate > endDate) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Date Range',
                text: 'End date must be greater than or equal to start date',
                confirmButtonColor: '#3085d6',
                timer: 2000,
                showConfirmButton: false
            });
            if ($(this).attr('id') === 'startDate') {
                $('#endDate').val($('#startDate').val());
            } else {
                $('#startDate').val($('#endDate').val());
            }
        }
    });
});
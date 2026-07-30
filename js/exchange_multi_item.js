// Multi-Item Gold Exchange - Frontend Implementation
// This file adds multi-item handling to the gold exchange form

// Save transaction with multi-item support
function saveTransactionMultiItem() {
    // Ensure payment status is updated before submission
    if (typeof updatePaymentStatus === 'function') {
        updatePaymentStatus();
    }

    // Collect all received items from the table
    const receivedItems = [];
    const rows = document.querySelectorAll('#receivedItemsTable .received-item-row');

    rows.forEach(row => {
        const weight = parseFloat(row.querySelector('.received-weight').value) || 0;
        const purity = parseFloat(row.querySelector('.received-purity').value) || 0;
        const fine = parseFloat(row.querySelector('.received-fine').value) || 0;
        const matSel = row.querySelector('.received-material');
        const material = matSel ? String(matSel.value || 'Gold').trim() : 'Gold';

        // Every row sent so server can read Metal even if weight parses as 0
        receivedItems.push({
            weight: weight,
            purity: purity,
            fine: fine,
            material: material
        });
    });

    const metalsWithWeight = new Set();
    receivedItems.forEach(it => {
        if (it.weight > 0) metalsWithWeight.add(it.material || 'Gold');
    });
    if (metalsWithWeight.size > 1) {
        Swal.fire({
            icon: 'error',
            title: 'Mixed metals',
            text: 'All received lines with weight must be the same metal: all Gold or all Silver.',
            confirmButtonColor: '#EAB308'
        });
        return;
    }

    if (typeof calculateTotals === 'function') {
        calculateTotals();
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
    const hid = document.getElementById('exchangeMaterialHidden');
    if (hid) {
        hid.value = vaultMetal;
    }

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
                // Show success message with print option
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-print mr-2"></i>Print Receipt',
                    cancelButtonText: 'Close',
                    confirmButtonColor: '#EAB308',
                    cancelButtonColor: '#6B7280'
                }).then((result) => {
                    if (result.isConfirmed && response.transaction_id) {
                        openExchangeReceiptPrint(response.transaction_id);
                    }
                    // Reload page and focus on party field for next entry
                    window.location.href = window.location.pathname + '?focus=party';
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message,
                    confirmButtonColor: '#EAB308'
                });
            }
        },
        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to save transaction',
                confirmButtonColor: '#EAB308'
            });
        }
    });
}

// Load transaction for editing with multi-item support
function loadTransactionMultiItem(receipt_id) {
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'get_exchange_by_receipt_id',
            receipt_id: receipt_id
        },
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                const transaction = response.data;

                console.log('Loading transaction:', transaction);

                // Populate main form fields
                $('input[name="transaction_id"]').val(transaction.id);
                $('input[name="receipt_id"]').val(transaction.receipt_id);
                $('input[name="date_of_transaction"]').val(transaction.date_of_transaction.replace(' ', 'T'));
                $('#partyNameInput').val(transaction.party_name);
                $('input[name="party_id"]').val(transaction.party_id);

                // Clear existing rows
                const table = document.getElementById('receivedItemsTable');
                table.innerHTML = '';

                // Populate received items
                if (transaction.received_items && transaction.received_items.length > 0) {
                    console.log('Loading items from exchange_items table:', transaction.received_items);
                    transaction.received_items.forEach((item, index) => {
                        const rowNum = index + 1;
                        const m = (item.material && String(item.material).toLowerCase() === 'silver') ? 'Silver' : 'Gold';
                        const auSel = m === 'Gold' ? 'selected' : '';
                        const agSel = m === 'Silver' ? 'selected' : '';
                        const newRow = `
                            <tr class="received-item-row">
                                <td class="px-2 py-1.5 border-b text-gray-700 font-bold item-number" style="width: 40px;">${rowNum}</td>
                                <td class="px-2 py-1.5 border-b" style="width: 64px;">
                                    <select class="w-full px-1 py-1 text-[10px] font-bold text-gray-800 bg-white border border-gray-200 rounded received-material">
                                        <option value="Gold" ${auSel}>Gold</option>
                                        <option value="Silver" ${agSel}>Silver</option>
                                    </select>
                                </td>
                                <td class="px-2 py-1.5 border-b">
                                    <input type="number" step="0.001" value="${item.weight}" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 received-weight" placeholder="0.000" required>
                                </td>
                                <td class="px-2 py-1.5 border-b">
                                    <input type="number" step="0.01" value="${item.purity}" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-yellow-400 focus:border-yellow-400 received-purity" placeholder="0.00" required>
                                </td>
                                <td class="px-2 py-1.5 border-b">
                                    <input type="number" step="0.001" value="${item.fine}" class="w-full px-2 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-200 rounded received-fine cursor-not-allowed" readonly>
                                </td>
                                <td class="px-2 py-1.5 border-b text-center" style="width: 48px;">
                                    <button type="button" onclick="removeReceivedItem(this)" class="text-red-600 hover:text-red-800 text-sm">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                        table.insertAdjacentHTML('beforeend', newRow);
                    });
                } else {
                    // If no items in exchange_items table (old transaction), create row from aggregated data
                    console.log('No items in exchange_items, using aggregated data');
                    if (transaction.received_weight && parseFloat(transaction.received_weight) > 0) {
                        const legM = (transaction.exchange_material && String(transaction.exchange_material).toLowerCase() === 'silver') ? 'Silver' : 'Gold';
                        const legAu = legM === 'Gold' ? 'selected' : '';
                        const legAg = legM === 'Silver' ? 'selected' : '';
                        const newRow = `
                            <tr class="received-item-row">
                                <td class="px-2 py-1.5 border-b text-gray-700 font-bold item-number" style="width: 40px;">1</td>
                                <td class="px-2 py-1.5 border-b" style="width: 64px;">
                                    <select class="w-full px-1 py-1 text-[10px] font-bold text-gray-800 bg-white border border-gray-200 rounded received-material">
                                        <option value="Gold" ${legAu}>Gold</option>
                                        <option value="Silver" ${legAg}>Silver</option>
                                    </select>
                                </td>
                                <td class="px-2 py-1.5 border-b">
                                    <input type="number" step="0.001" value="${transaction.received_weight}" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 received-weight" placeholder="0.000" required>
                                </td>
                                <td class="px-2 py-1.5 border-b">
                                    <input type="number" step="0.01" value="${transaction.purity}" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-yellow-400 focus:border-yellow-400 received-purity" placeholder="0.00" required>
                                </td>
                                <td class="px-2 py-1.5 border-b">
                                    <input type="number" step="0.001" value="${transaction.fine_weight}" class="w-full px-2 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-200 rounded received-fine cursor-not-allowed" readonly>
                                </td>
                                <td class="px-2 py-1.5 border-b text-center" style="width: 48px;">
                                    <button type="button" onclick="removeReceivedItem(this)" class="text-red-600 hover:text-red-800 text-sm">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                        table.insertAdjacentHTML('beforeend', newRow);
                    } else {
                        // Create empty default row
                        console.log('Creating empty default row');
                        if (typeof addReceivedItem === 'function') {
                            addReceivedItem();
                        }
                    }
                }

                // Populate issue and payment fields
                const issueWeightInput = document.getElementById('issueWeightInput');
                if (issueWeightInput) {
                    issueWeightInput.value = transaction.delivered_weight || 0;
                }

                const hidEm = document.getElementById('exchangeMaterialHidden');
                const dispEm = document.getElementById('issueMetalDisplay');
                const em = (transaction.exchange_material && String(transaction.exchange_material).toLowerCase() === 'silver') ? 'Silver' : 'Gold';
                if (hidEm) hidEm.value = em;
                if (dispEm) dispEm.textContent = em === 'Silver' ? 'Fine silver' : 'Fine gold';

                const rateInput = document.getElementById('rate');
                if (rateInput) {
                    rateInput.value = transaction.rate || 0;
                }

                const amountInput = document.getElementById('amount');
                if (amountInput) {
                    amountInput.value = transaction.amount || 0;
                }

                const paymentAmountInput = document.getElementById('paymentAmount');
                if (paymentAmountInput) {
                    paymentAmountInput.value = transaction.payment_amount || 0;
                }

                const paymentMethodSelect = document.querySelector('select[name="payment_method"]');
                if (paymentMethodSelect) {
                    paymentMethodSelect.value = transaction.payment_method || 'Cash';
                }

                const narrationInput = document.querySelector('input[name="narration"]');
                if (narrationInput) {
                    narrationInput.value = transaction.narration || '';
                }

                // Recalculate totals and attach listeners
                if (typeof attachCalculationListeners === 'function') {
                    attachCalculationListeners();
                }
                if (typeof calculateTotals === 'function') {
                    calculateTotals();
                }

                // Load party dues
                if (typeof loadPartyDues === 'function') {
                    loadPartyDues(transaction.party_name);
                }

                // Show delete button and change submit text
                $('#deleteBtn').removeClass('hidden');
                $('#submitText').text('Update');
                $('#submitIcon').removeClass('fa-exchange-alt').addClass('fa-save');

                // Scroll to form
                $('html, body').animate({
                    scrollTop: $('#exchangeForm').offset().top - 100
                }, 500);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message || 'Receipt not found',
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

// Always use multi-item save so Metal column + received_items reach the server
window.saveTransaction = saveTransactionMultiItem;

// Add receipt ID search handler
$(document).ready(function () {
    // Receipt ID search
    $('#receiptId').on('input', function () {
        const searchTerm = $(this).val().trim();

        if (searchTerm.length < 2) {
            $('#receiptList').addClass('hidden').empty();
            return;
        }

        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'search_receipt_ids',
                term: searchTerm
            },
            dataType: 'json',
            success: function (receipts) {
                displayReceiptList(receipts);
            },
            error: function () {
                console.error('Error searching receipts');
            }
        });
    });

    // Display receipt list
    function displayReceiptList(receipts) {
        const $receiptList = $('#receiptList');
        $receiptList.empty();

        if (receipts.length === 0) {
            $receiptList.addClass('hidden');
            return;
        }

        receipts.forEach(function (receipt) {
            const $item = $('<div>')
                .addClass('px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100')
                .html(`
                    <div class="font-semibold text-sm text-gray-800">${receipt.receipt_id}</div>
                    <div class="text-xs text-gray-600">${receipt.party_name} - ${receipt.date}</div>
                `)
                .click(function () {
                    loadTransactionMultiItem(receipt.receipt_id);
                    $receiptList.addClass('hidden');
                });
            $receiptList.append($item);
        });

        $receiptList.removeClass('hidden');
    }

    // Hide receipt list when clicking outside
    $(document).click(function (e) {
        if (!$(e.target).closest('#receiptId, #receiptList').length) {
            $('#receiptList').addClass('hidden');
        }
    });
});

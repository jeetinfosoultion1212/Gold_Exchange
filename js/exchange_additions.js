// Gold Exchange Additions - Receipt Search and Print Functions
$(document).ready(function () {
    let receiptSearchTimeout = null;
    let receiptListVisible = false;
    let currentReceiptIndex = -1;

    // Show all receipts on click/focus
    $('#receiptId').on('click focus', function () {
        searchReceiptIds(''); // Always show all receipts on click
    });

    // Receipt ID autocomplete with dropdown
    $('#receiptId').on('input', function () {
        const searchTerm = $(this).val().trim();

        clearTimeout(receiptSearchTimeout);
        receiptSearchTimeout = setTimeout(function () {
            searchReceiptIds(searchTerm || ''); // Empty string shows all
        }, 300);
    });

    // Keyboard navigation for receipt list
    $('#receiptId').on('keydown', function (e) {
        const receiptItems = document.querySelectorAll('#receiptList .receipt-item');

        // Arrow Down
        if (e.key === 'ArrowDown' && receiptListVisible && receiptItems.length > 0) {
            e.preventDefault();
            if (currentReceiptIndex < 0) {
                currentReceiptIndex = 0;
            } else {
                currentReceiptIndex = Math.min(currentReceiptIndex + 1, receiptItems.length - 1);
            }
            updateReceiptHighlight();
        }
        // Arrow Up
        else if (e.key === 'ArrowUp' && receiptListVisible && receiptItems.length > 0) {
            e.preventDefault();
            if (currentReceiptIndex <= 0) {
                currentReceiptIndex = -1;
            } else {
                currentReceiptIndex = Math.max(currentReceiptIndex - 1, 0);
            }
            updateReceiptHighlight();
        }
        // Enter to select
        else if (e.key === 'Enter' && receiptListVisible && currentReceiptIndex >= 0 && receiptItems.length > 0) {
            e.preventDefault();
            const selectedItem = receiptItems[currentReceiptIndex];
            const receiptId = selectedItem.getAttribute('data-receipt-id');
            selectReceipt(receiptId);
        }
        // Escape to close
        else if (e.key === 'Escape') {
            $('#receiptList').addClass('hidden');
            receiptListVisible = false;
            currentReceiptIndex = -1;
        }
    });

    // Update receipt highlight
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

    // Hide receipt list when clicking outside
    $(document).click(function (e) {
        if (!$(e.target).closest('#receiptId, #receiptList').length) {
            $('#receiptList').addClass('hidden');
            receiptListVisible = false;
            currentReceiptIndex = -1;
        }
    });

    // Print receipt from list action button (backup for delegated clicks)
    $(document).on('click', '.print-exchange-receipt', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const transactionId = $(this).attr('data-id') || $(this).data('id');
        if (transactionId && typeof openExchangeReceiptPrint === 'function') {
            openExchangeReceiptPrint(transactionId);
        }
    });
});

// Search receipt IDs for autocomplete
function searchReceiptIds(searchTerm) {
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
            console.error('Error searching receipt IDs');
        }
    });
}

// Display receipt list dropdown
function displayReceiptList(receipts) {
    const $receiptList = $('#receiptList');
    $receiptList.empty();
    currentReceiptIndex = -1;

    if (receipts.length === 0) {
        $receiptList.addClass('hidden');
        receiptListVisible = false;
        return;
    }

    receipts.forEach(function (receipt, index) {
        const $item = $('<div>')
            .addClass('px-3 py-2 hover:bg-yellow-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors receipt-item')
            .attr('data-index', index)
            .attr('data-receipt-id', receipt.receipt_id)
            .html(`
                <div class="font-semibold text-sm text-gray-800">${receipt.receipt_id}</div>
                <div class="text-xs text-gray-600">${receipt.party_name} • ${receipt.date}</div>
            `)
            .click(function () {
                selectReceipt(receipt.receipt_id);
            });
        $receiptList.append($item);
    });

    $receiptList.removeClass('hidden');
    receiptListVisible = true;
}

// Select receipt and load transaction
function selectReceipt(receiptId) {
    $('#receiptId').val(receiptId);
    $('#receiptList').addClass('hidden');
    receiptListVisible = false;
    currentReceiptIndex = -1;

    // Load the transaction
    searchByReceiptId(receiptId);
}

// Search transaction by receipt ID
function searchByReceiptId(receiptId) {
    // Use the multi-item load function
    if (typeof loadTransactionMultiItem === 'function') {
        loadTransactionMultiItem(receiptId);
    } else {
        // Fallback to old method if multi-item function not available
        $.ajax({
            url: '',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'get_exchange_by_receipt_id',
                receipt_id: receiptId
            },
            success: function (response) {
                if (response.status === 'success') {
                    populateFormForEdit(response.data);
                    Swal.fire({
                        icon: 'success',
                        title: 'Receipt Found',
                        text: 'Transaction loaded for editing',
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Not Found',
                        text: response.message || 'Receipt not found'
                    });
                }
            },
            error: function () {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to search receipt'
                });
            }
        });
    }
}

// Populate form with transaction data for editing
function populateFormForEdit(data) {
    $('input[name="transaction_id"]').val(data.id);
    $('#receiptId').val(data.receipt_id);
    $('#partyNameInput').val(data.party_name || '');
    $('#receivedWeight').val(data.received_weight || 0);
    $('#purity').val(data.purity || 0);
    $('#issueWeight').val(data.delivered_weight || data.issue_weight || 0);
    $('#rate').val(data.rate || 0);
    $('#paymentAmount').val(data.payment_amount || 0);
    // Set default payment_method to 'Cash' if not provided
    $('select[name="payment_method"]').val(data.payment_method || 'Cash');
    $('input[name="payment_status"]').val(data.payment_status || 'Due');
    $('input[name="narration"]').val(data.narration || '');

    // Format date for datetime-local input
    if (data.date_of_transaction) {
        const dateStr = data.date_of_transaction.replace(' ', 'T').substring(0, 16);
        $('input[name="date_of_transaction"]').val(dateStr);
    }

    // Show delete button and change button text
    $('#deleteBtn').removeClass('hidden');
    $('#submitText').text('Update');
    $('#submitIcon').removeClass('fa-exchange-alt').addClass('fa-save');

    // Trigger calculations
    if (typeof calculateFineWeight === 'function') calculateFineWeight();
    if (typeof calculateDifference === 'function') calculateDifference();
    if (typeof calculateAmount === 'function') calculateAmount();

    // Update payment status after calculations
    if (typeof updatePaymentStatus === 'function') {
        updatePaymentStatus();
    }

    // Load party dues if party name exists
    if (data.party_name && typeof loadPartyDues === 'function') {
        loadPartyDues(data.party_name);
    }

    // Scroll to top of form
    $('html, body').animate({ scrollTop: 0 }, 500);
}

// Print exchange receipt (thermal printer compatible)
function printExchangeReceipt(exchangeData, companyName) {
    const transactionDate = exchangeData.date_of_transaction
        ? new Date(exchangeData.date_of_transaction).toLocaleString('en-IN')
        : new Date().toLocaleString('en-IN');

    // Build received items rows
    let receivedItemsHtml = '';
    let totalFine = 0;

    if (exchangeData.received_items && exchangeData.received_items.length > 0) {
        exchangeData.received_items.forEach((item, index) => {
            const fine = parseFloat(item.fine || item.fine_weight);
            totalFine += fine;

            receivedItemsHtml += `
            <tr>
                <td style="text-align:center; padding:4px 2px;">${index + 1}</td>
                <td style="text-align:right; padding:4px 2px;">${parseFloat(item.weight).toFixed(3)}</td>
                <td style="text-align:right; padding:4px 2px;">${parseFloat(item.purity).toFixed(2)}</td>
                <td style="text-align:right; padding:4px 2px;">${fine.toFixed(3)}</td>
            </tr>`;
        });
    } else {
        const weight = parseFloat(exchangeData.received_weight || 0);
        const purity = parseFloat(exchangeData.purity || 0);
        const fine = parseFloat(exchangeData.fine_weight || 0);
        totalFine = fine;

        receivedItemsHtml = `
        <tr>
            <td style="text-align:center; padding:4px 2px;">1</td>
            <td style="text-align:right; padding:4px 2px;">${weight.toFixed(3)}</td>
            <td style="text-align:right; padding:4px 2px;">${purity.toFixed(2)}</td>
            <td style="text-align:right; padding:4px 2px;">${fine.toFixed(3)}</td>
        </tr>`;
    }

    const amountVal = parseFloat(exchangeData.amount) || 0;
    const paidVal = parseFloat(exchangeData.payment_amount) || 0;
    const balanceVal = Math.max(0, parseFloat(exchangeData.due_amount ?? (amountVal - paidVal)) || 0);

    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Receipt - ${exchangeData.receipt_id}</title>
            <style>
                @page { size: 80mm auto; margin: 5mm; }
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: 'Courier New', monospace;
                    font-weight: bold;
                    font-size: 12pt;
                    width: 70mm;
                    margin: 0 auto;
                    padding: 5mm;
                }
                .receipt-header {
                    text-align: center;
                    border-bottom: 2px dashed #000;
                    padding-bottom: 8px;
                    margin-bottom: 8px;
                }
                .company-name { font-size: 16pt; font-weight: 900; margin-bottom: 3px; }
                .receipt-title { font-size: 11pt; font-weight: bold; }
                .receipt-body { font-size: 11pt; line-height: 1.4; font-weight: bold; }
                .receipt-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
                .receipt-label { color: #000; font-weight: bold; }
                .receipt-value { font-weight: 900; }
                .receipt-divider { border-top: 2px dashed #000; margin: 10px 0; }
                .receipt-section { margin: 10px 0; }
                .receipt-footer {
                    text-align: center;
                    border-top: 2px dashed #000;
                    padding-top: 10px;
                    margin-top: 10px;
                    font-size: 10pt;
                    font-weight: bold;
                }
                table { width: 100%; border-collapse: collapse; font-size: 11pt; font-weight: bold; }
                th { text-align: right; border-bottom: 2px dashed #000; padding: 4px 2px; }
                th:first-child { text-align: center; }
                td { padding: 4px 2px; }
                @media print {
                    body { width: 70mm; }
                    @page { margin: 0; }
                }
            </style>
        </head>
        <body>
            <div class="receipt-header">
                <div class="company-name">${companyName}</div>
                <div class="receipt-title">GOLD EXCHANGE RECEIPT</div>
            </div>
            
            <div class="receipt-body">
                <div class="receipt-row">
                    <span class="receipt-label">Receipt ID:</span>
                    <span class="receipt-value">${exchangeData.receipt_id}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Date:</span>
                    <span>${transactionDate}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Party:</span>
                    <span class="receipt-value">${exchangeData.party_name}</span>
                </div>
                
                <div class="receipt-divider"></div>
                <div style="font-size: 11pt; font-weight: 900; margin-bottom: 5px;">Received Items:</div>
                
                <table>
                    <thead>
                        <tr>
                            <th width="10%">#</th>
                            <th width="30%">Wt(g)</th>
                            <th width="25%">Pur%</th>
                            <th width="35%">Fine(g)</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${receivedItemsHtml}
                        <tr>
                            <td colspan="3" style="text-align:right; font-weight:900; border-top:2px dashed #000; padding-top:6px;">TOTAL FINE:</td>
                            <td style="text-align:right; font-weight:900; border-top:2px dashed #000; padding-top:6px;">${totalFine.toFixed(3)}</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="receipt-divider"></div>
                
                <div class="receipt-row">
                    <span class="receipt-label" style="font-weight:normal;">Issue Weight:</span>
                    <span class="receipt-value">${parseFloat(exchangeData.delivered_weight || exchangeData.issue_weight || 0).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label" style="font-weight:normal;">Difference:</span>
                    <span class="receipt-value">${parseFloat(exchangeData.difference_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label" style="font-weight:normal;">Rate:</span>
                    <span class="receipt-value">₹${parseFloat(exchangeData.rate).toLocaleString('en-IN', { minimumFractionDigits: 2 })}/g</span>
                </div>
                
                <div class="receipt-divider"></div>
                
                <div class="receipt-section">
                    <div class="receipt-row">
                        <span class="receipt-label">Amount:</span>
                        <span class="receipt-value" style="font-size: 14pt;">₹${amountVal.toLocaleString('en-IN')}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">${exchangeData.payment_type === 'Payment_In' ? 'Received' : 'Paid'}:</span>
                        <span style="font-weight:900;">₹${paidVal.toLocaleString('en-IN')}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Balance:</span>
                        <span class="receipt-value">₹${balanceVal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Pay Mode:</span>
                        <span>${exchangeData.payment_method}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Status:</span>
                        <span>${exchangeData.payment_status}</span>
                    </div>
                </div>
                
                ${exchangeData.narration ? `
                <div class="receipt-divider"></div>
                <div>
                    <div style="font-size: 10pt; font-weight: bold; margin-bottom: 2px;">Note:</div>
                    <div style="font-size: 11pt; font-weight: bold;">${exchangeData.narration}</div>
                </div>
                ` : ''}
            </div>
            
            <div class="receipt-footer">
                Thank you for your business!
            </div>
        </body>
        </html>
    `;

    if (window.GePrint && typeof window.GePrint.printHtml === 'function') {
        window.GePrint.printHtml(printContent);
        return;
    }

    const printWindow = window.open('', '_blank', 'width=300,height=600');
    printWindow.document.write(printContent);
    printWindow.document.close();

    setTimeout(() => {
        printWindow.focus();
        printWindow.print();
    }, 250);
}

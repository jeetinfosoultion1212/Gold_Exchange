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

    // Print receipt from list - Open thermal receipt PDF
    $(document).on('click', '.print-receipt', function () {
        const transactionId = $(this).data('id');
        if (transactionId) {
            // Open thermal receipt in same tab for direct printing
            window.open('print_exchange_receipt.php?id=' + transactionId, '_self');
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
    calculateFineWeight();
    calculateDifference();
    calculateAmount();

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

    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Receipt - ${exchangeData.receipt_id}</title>
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
                
                <div class="receipt-row">
                    <span class="receipt-label">Received Weight:</span>
                    <span class="receipt-value">${parseFloat(exchangeData.received_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Purity:</span>
                    <span>${parseFloat(exchangeData.purity).toFixed(2)}%</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Fine Weight:</span>
                    <span>${parseFloat(exchangeData.fine_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Issue Weight:</span>
                    <span>${parseFloat(exchangeData.issue_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Difference:</span>
                    <span class="receipt-value">${parseFloat(exchangeData.difference_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Rate:</span>
                    <span>₹${parseFloat(exchangeData.rate).toLocaleString('en-IN', { minimumFractionDigits: 2 })}/g</span>
                </div>
                
                <div class="receipt-divider"></div>
                
                <div class="receipt-section">
                    <div class="receipt-row">
                        <span class="receipt-label">Amount:</span>
                        <span class="receipt-value" style="font-size: 12pt;">₹${parseFloat(exchangeData.amount).toLocaleString('en-IN')}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">${exchangeData.payment_type === 'Payment_In' ? 'Received' : 'Paid'}:</span>
                        <span style="color: ${exchangeData.payment_type === 'Payment_In' ? '#28a745' : '#dc3545'};">₹${parseFloat(exchangeData.payment_amount).toLocaleString('en-IN')}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Payment Method:</span>
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
                    <div style="font-size: 9pt; color: #666; margin-bottom: 2px;">Note:</div>
                    <div style="font-size: 9pt;">${exchangeData.narration}</div>
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
    }, 250);
}

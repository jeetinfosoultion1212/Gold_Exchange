
$(document).ready(function() {
// Initialize payment modal
const paymentModal = document.getElementById('paymentModal');
if (paymentModal) {
paymentModal.addEventListener('show.bs.modal', function (event) {
const button = event.relatedTarget;

// Get values from data attributes
const transactionId = button.dataset.transactionId;
const totalAmount = parseFloat(button.dataset.totalAmount);
const paidAmount = parseFloat(button.dataset.paidAmount);
const dueAmount = parseFloat(button.dataset.dueAmount);
const partyName = button.dataset.partyName;

// Debug log
console.log('Payment Modal Data:', {
    transactionId,
    totalAmount,
    paidAmount,
    dueAmount,
    partyName
});

// Set values in the modal fields
document.getElementById('paymentTransactionId').value = transactionId;
document.getElementById('paymentPartyName').value = partyName;
document.getElementById('paymentTotalAmount').value = totalAmount.toFixed(2);
document.getElementById('paymentPaidAmount').value = paidAmount.toFixed(2);
document.getElementById('paymentDueAmount').value = dueAmount.toFixed(2);
document.getElementById('newPaymentAmount').value = dueAmount.toFixed(2);
});
}


// Handle payment amount validation
$('#newPaymentAmount').on('input', function() {
const dueAmount = parseFloat($('#paymentDueAmount').val());
const paymentAmount = parseFloat(this.value);

if (paymentAmount > dueAmount) {
this.value = dueAmount;
Swal.fire({
    icon: 'warning',
    title: 'Invalid Amount',
    text: 'Payment amount cannot exceed due amount'
});
}
});

// Handle payment submission
$('#submitPayment').click(function() {
const form = $('#paymentForm');

if (!form[0].checkValidity()) {
form[0].reportValidity();
return;
}

Swal.fire({
title: 'Confirm Payment',
text: 'Are you sure you want to process this payment?',
icon: 'question',
showCancelButton: true,
confirmButtonText: 'Yes, process payment',
cancelButtonText: 'Cancel'
}).then((result) => {
if (result.isConfirmed) {
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: form.serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Accepted',
                    text: 'The payment has been processed successfully!',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
            }
        },
        error: function() {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to process payment'
            });
        }
    });
}
});
});
});
$(document).ready(function() {
    // Generate receipt ID
    function generateReceiptId() {
        const prefix = 'R';
        const timestamp = new Date().getTime().toString().slice(-6);
        const random = Math.floor(Math.random() * 100).toString().padStart(2, '0');
        return `${prefix}-${timestamp}${random}`;
    }
    $('[name="receipt_id"]').val(generateReceiptId());

    // Set current date and time
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));

    let partyListVisible = false;
    let currentIndex = -1;

    $('#partyNameInput').on('input', function () {
        const term = $(this).val();
        if (term.length >= 2) {
            $.post('', {
                action: 'search_parties',
                term: term
            }, function (parties) {
                const partyList = $('#partyList');
                partyList.empty();
                parties.forEach((party, index) => {
                    partyList.append(`
                        <a href="#" class="list-group-item list-group-item-action" data-index="${index}" data-name="${party.party_name}" data-address="${party.address}">
                            <strong>${index + 1}. ${party.party_name}</strong><br>
                            <small>${party.address}</small>
                        </a>
                    `);
                });
                if (parties.length > 0) {
                    partyList.removeClass('d-none');
                    partyListVisible = true;
                    currentIndex = -1;
                } else {
                    partyList.addClass('d-none');
                    partyListVisible = false;
                }
            }, 'json');
        } else {
            $('#partyList').addClass('d-none');
            partyListVisible = false;
        }
    });

    $('#partyList').on('click', 'a', function (e) {
        e.preventDefault();
        const partyName = $(this).data('name');
        $('#partyNameInput').val(partyName);
        
        // Fetch due balances for selected party
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'get_party_dues',
                party_name: partyName
            },
            dataType: 'json',
            success: function(response) {
                // Update due info display
                $('#dueAmountValue').text('₹' + response.due_amount.toFixed(2));
                $('#dueGoldValue').text(response.due_gold.toFixed(3) + 'g');
                $('#partyDueInfo').removeClass('d-none');
            },
            error: function() {
                console.error('Failed to fetch due balances');
                $('#partyDueInfo').addClass('d-none');
            }
        });
        
        $('#partyList').addClass('d-none');
        partyListVisible = false;
        currentIndex = -1;
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#partyNameInput, #partyList').length && partyListVisible) {
            $('#partyList').addClass('d-none');
            partyListVisible = false;
        }
    });

    // Keyboard navigation for the party list
    $('#partyNameInput').on('keydown', function (e) {
        const partyList = $('#partyList');
        const partyItems = partyList.find('.list-group-item');
        if (!partyItems.length) return;

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            
            // Update current index
            if (e.key === 'ArrowDown') {
                currentIndex = (currentIndex + 1) % partyItems.length;
            } else {
                currentIndex = (currentIndex - 1 + partyItems.length) % partyItems.length;
            }

            // Remove active class from all items
            partyItems.removeClass('active');
            
            // Add active class to current item
            const currentItem = $(partyItems[currentIndex]);
            currentItem.addClass('active');

            // Calculate scroll position
            const itemHeight = currentItem.outerHeight();
            const listHeight = partyList.height();
            const itemTop = currentItem.position().top;
            const scrollTop = partyList.scrollTop();

            // Scroll into view if needed
            if (itemTop < 0) {
                // Scroll up if item is above visible area
                partyList.scrollTop(scrollTop + itemTop);
            } else if (itemTop + itemHeight > listHeight) {
                // Scroll down if item is below visible area
                partyList.scrollTop(scrollTop + itemTop - listHeight + itemHeight);
            }

            // Optional: Prevent focus from leaving input
            $('#partyNameInput').focus();
            
        } else if (e.key === 'Enter' && currentIndex >= 0) {
            e.preventDefault();
            const selectedParty = $(partyItems[currentIndex]);
            $('#partyNameInput').val(selectedParty.data('name'));
            partyList.addClass('d-none');
            partyListVisible = false;
            currentIndex = -1;
        }
    });
    
    // Open new party modal
    $('#addNewPartyBtn').on('click', function() {
        $('#partyModal').modal('show');
    });

    // Save new party
    $('#savePartyBtn').on('click', function() {
        const newPartyName = $('#newPartyName').val();
        const partyAddress = $('#partyAddress').val();
        const partyContact = $('#partyContact').val();
        const dueType = $('input[name="dueType"]:checked').val();
        
        if (!newPartyName) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Party name is required!'
            });
            return;
        }

        const data = {
            action: 'save_party',
            party_name: newPartyName,
            address: partyAddress,
            contact_no: partyContact
        };

        // Add due information if selected
        if (dueType === 'cash') {
            const dueAmount = parseFloat($('#initialDueAmount').val()) || 0;
            if (dueAmount > 0) {
                data.initial_due_amount = dueAmount;
                data.due_type = 'cash';
            }
        } else if (dueType === 'refine') {
            const goldWeight = parseFloat($('#initialGoldWeight').val()) || 0;
            if (goldWeight > 0) {
                data.initial_gold_weight = goldWeight;
                data.gold_purity = parseFloat($('#goldPurity').val()) || 24;
                data.due_type = 'refine';
            }
        }

        $.post('', data, function(response) {
            if (response.status === 'success') {
                $('#partyNameInput').val(newPartyName);
                $('#partyModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: 'New party added successfully!',
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
            }
        }, 'json');
    });

    // Calculate fine weight
    $('[name="received_weight"], [name="purity"]').on('input', function() {
        const receivedWeight = parseFloat($('[name="received_weight"]').val()) || 0;
        const purity = parseFloat($('[name="purity"]').val()) || 0;
        const fineWeight = (receivedWeight * purity) / 100;
        $('[name="fine_weight"]').val(fineWeight.toFixed(3));
        
        if($('[name="issue_weight"]').val()) {
            $('[name="issue_weight"]').trigger('input');
        }
    });

    // Calculate difference weight and amount
    $('[name="issue_weight"], [name="rate"], [name="fine_weight"]').on('input', function() {
        const fineWeight = parseFloat($('[name="fine_weight"]').val()) || 0;
        const issueWeight = parseFloat($('[name="issue_weight"]').val()) || 0;
        const rate = parseFloat($('[name="rate"]').val()) || 0;
        
        const difference = issueWeight - fineWeight;
        $('[name="difference_weight"]').val(difference.toFixed(3));
        
        const type = difference > 0 ? 'cash_liya' : 'cash_diya';
        $('[name="type"]').val(type);
        
        const amount = Math.abs(difference) * rate;
        $('[name="amount"]').val(amount.toFixed(2)); // Ensure amount is displayed with 2 decimal places
        
        // Set default payment amount to match total amount
        $('[name="payment_amount"]').val(amount.toFixed(2));
        updatePaymentStatus(amount, amount); // Pass total amount for initial status update
    });

    // Update payment status and due amount when payment amount changes
    $('[name="payment_amount"]').on('input', function() {
        const totalAmount = parseFloat($('[name="amount"]').val()) || 0; // Get amount dynamically
        const paymentAmount = parseFloat($(this).val()) || 0;
        updatePaymentStatus(paymentAmount, totalAmount);
    });

    // Function to update payment status and due amount
    function updatePaymentStatus(paymentAmount, totalAmount) {
        let status;
        if (paymentAmount === 0) {
            status = 'Due';
        } else if (paymentAmount < totalAmount) {
            status = 'Partial';
        } else {
            status = 'Paid';
        }
        
        // Update payment status field
        $('[name="payment_status"]').val(status);
        
        // Calculate and update due amount
        const dueAmount = Math.max(0, totalAmount - paymentAmount);
        $('[name="due_amount"]').val(dueAmount.toFixed(2));
    }

    // Form submission
    $('#transactionForm').on('submit', function(e) {
        e.preventDefault();
        console.log('Form submitted');
        
        // Check if SweetAlert2 is loaded
        if (typeof Swal === 'undefined') {
            console.error('SweetAlert2 is not loaded. Loading it now...');
            // Load SweetAlert2 dynamically
            $.getScript('https://cdn.jsdelivr.net/npm/sweetalert2@11.0.18/dist/sweetalert2.all.min.js', function() {
                console.log('SweetAlert2 loaded successfully');
                // Continue with form submission
                submitForm();
            });
        } else {
            // SweetAlert2 is already loaded, proceed with form submission
            submitForm();
        }
    });

    function submitForm() {
        const formData = $('#transactionForm').serialize();
        console.log('Form data:', formData);
        
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            dataType: 'json',
            beforeSend: function() {
                console.log('Sending AJAX request...');
            },
            success: function(response) {
                console.log('Response received:', response);
                if(response.status === 'success') {
                    const formData = {};
                    $('#transactionForm').serializeArray().forEach(item => {
                        formData[item.name] = item.value;
                    });
                    
                    showReceipt(formData);
                    
                    // Reload transaction data
                    reloadTransactionData();
                    
                    // Reset form and show success message
                    $('#transactionForm')[0].reset();
                    $('[name="receipt_id"]').val(generateReceiptId());
                    const now = new Date();
                    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                    $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: 'Transaction saved successfully!',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    console.error('Error response:', response);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'An error occurred while saving the transaction'
                    });
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', {xhr, status, error});
                console.error('Response text:', xhr.responseText);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to save transaction: ' + error
                });
            }
        });
    }

    // Function to reload transaction data
    function reloadTransactionData() {
        $.ajax({
            url: window.location.href,
            method: 'GET',
            success: function(response) {
                // Extract the table content from the response
                const parser = new DOMParser();
                const doc = parser.parseFromString(response, 'text/html');
                
                // Update the table body
                $('table tbody').html($(doc).find('table tbody').html());
                
                // Update the statistics
                $('.stats-container').html($(doc).find('.stats-container').html());
            },
            error: function() {
                console.error('Failed to reload transaction data');
            }
        });
    }

    // View transaction and Print button
    $('.view-btn, .print-btn').click(function() {
        const id = $(this).data('id');
        $.post('', {
            action: 'get_transaction', // Use the existing get_transaction for view/print
            id: id
        }, function(transaction) {
            showReceipt(transaction);
        }, 'json');
    });

  

    // Show receipt
    function showReceipt(data) {
        console.log('Receipt Data:', data); // Debug log
        
        // Generate QR code data
        const qrData = JSON.stringify({
            receipt_id: data.receipt_id,
            party_name: data.party_name,
            due_amount: data.due_amount,
            due_gold: data.due_gold
        });

        // Clear existing QR code
        $('#receipt-qr').empty();

        // Update modal content
        $('#receipt-id').text(data.receipt_id);
        $('#receipt-date').text(new Date(data.date_of_transaction).toLocaleString());
        $('#receipt-party').text(data.party_name);
        $('#receipt-type').text(data.type || 'Refine');
        $('#receipt-received-weight').text(`${data.received_weight} g`);
        $('#receipt-purity').text(`${data.purity}%`);
        $('#receipt-fine-weight').text(`${data.fine_weight} g`);
        $('#receipt-issue-weight').text(`${data.issue_weight} g`);
        $('#receipt-rate').text(`₹${parseFloat(data.rate).toFixed(2)}`);
        $('#receipt-amount').text(`₹${parseFloat(data.amount).toFixed(2)}`);
        $('#receipt-payment-amount').text(`₹${parseFloat(data.payment_amount || 0).toFixed(2)}`);
        $('#receipt-payment-status').text(data.payment_status || 'Due');
        $('#receipt-due-amount').text(`₹${parseFloat(data.due_amount || data.amount).toFixed(2)}`);
        $('#receipt-due-gold').text(`${parseFloat(data.due_gold || 0).toFixed(3)} g`);

        // Generate QR code
        try {
            new QRCode(document.getElementById('receipt-qr'), {
                text: qrData,
                width: 100,
                height: 100,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
        } catch (error) {
            console.error('QR Code generation failed:', error);
        }

        // Show the modal
        const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
        receiptModal.show();
    }

    // Search functionality
    let searchTimer;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const searchTerm = $(this).val();
            window.location.href = `?search=${searchTerm}`;
        }, 500);
    });

    // Add active class to current page button
    $('.btn-group .btn').click(function() {
        $('.btn-group .btn').removeClass('active');
        $(this).addClass('active');
    });

    // Initialize payment modal
    const paymentModal = document.getElementById('paymentModal');
    if (paymentModal) {
        paymentModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const transactionId = button.getAttribute('data-transaction-id');
            const totalAmount = parseFloat(button.getAttribute('data-total-amount'));
            const paidAmount = parseFloat(button.getAttribute('data-paid-amount'));
            const dueAmount = parseFloat(button.getAttribute('data-due-amount'));
            const partyName = button.getAttribute('data-party-name');
            
            // Set values in the modal
            document.getElementById('paymentTransactionId').value = transactionId;
            document.getElementById('paymentPartyName').value = partyName;
            document.getElementById('paymentTotalAmount').value = totalAmount.toFixed(2);
            document.getElementById('paymentPaidAmount').value = paidAmount.toFixed(2);
            document.getElementById('paymentDueAmount').value = dueAmount.toFixed(2);
            document.getElementById('newPaymentAmount').value = dueAmount.toFixed(2);
            
            // Reset form
            document.getElementById('paymentForm').reset();
        });
    }
    
    // Handle payment amount validation
    $('#newPaymentAmount').on('input', function() {
        const dueAmount = parseFloat($('#paymentDueAmount').val());
        const paymentAmount = parseFloat(this.value);
        
        if (paymentAmount > dueAmount) {
            this.value = dueAmount;
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Amount',
                text: 'Payment amount cannot exceed due amount'
            });
        }
    });
    
    // Handle payment submission
    $('#submitPayment').click(function() {
        const form = $('#paymentForm');
        
        if (!form[0].checkValidity()) {
            form[0].reportValidity();
            return;
        }
        
        Swal.fire({
            title: 'Confirm Payment',
            text: 'Are you sure you want to process this payment?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, process payment',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: form.serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Payment Accepted',
                                text: 'The payment has been processed successfully!',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to process payment'
                        });
                    }
                });
            }
        });
    });
});

// Print receipt
function printReceipt() {
    const printContent = document.querySelector('.receipt').cloneNode(true);
    const htmlDoc = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Receipt</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
            <style>
                @page {
                    size: 80mm 297mm;
                    margin: 0;
                }
                
                body {
                    font-family: 'Arial', sans-serif;
                    margin: 0;
                    padding: 0;
                    width: 80mm;
                    font-size: 10pt;
                }

                .print-receipt {
                    padding: 5mm;
                    background: white;
                }

                .print-header {
                    text-align: center;
                    border-bottom: 1px dashed #000;
                    padding-bottom: 3mm;
                    margin-bottom: 3mm;
                }

                .company-name {
                    font-size: 14pt;
                    font-weight: bold;
                    margin-bottom: 1mm;
                }

                .company-address {
                    font-size: 8pt;
                    margin-bottom: 2mm;
                }

                .receipt-number {
                    font-size: 10pt;
                    margin-bottom: 1mm;
                }

                .receipt-date {
                    font-size: 8pt;
                    margin-bottom: 2mm;
                }

                .party-details {
                    border-bottom: 1px dashed #000;
                    padding: 2mm 0;
                    margin-bottom: 2mm;
                }

                .party-name {
                    font-weight: bold;
                    font-size: 10pt;
                    margin-bottom: 1mm;
                }

                .transaction-type {
                    font-size: 8pt;
                    color: #666;
                }

                .details-section {
                    margin-bottom: 3mm;
                }

                .section-title {
                    font-size: 9pt;
                    font-weight: bold;
                    margin-bottom: 1mm;
                    padding-bottom: 0.5mm;
                    border-bottom: 1px solid #eee;
                }

                .detail-row {
                    display: flex;
                    justify-content: space-between;
                    font-size: 9pt;
                    margin-bottom: 0.5mm;
                }

                .detail-label {
                    color: #666;
                }

                .detail-value {
                    font-weight: bold;
                }

                .weight-details, .payment-details {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 2mm;
                    margin-bottom: 2mm;
                    padding: 1mm;
                    border: 1px solid #eee;
                    border-radius: 2mm;
                }

                .weight-item, .payment-item {
                    text-align: center;
                    padding: 1mm;
                }

                .item-label {
                    font-size: 8pt;
                    color: #666;
                }

                .item-value {
                    font-size: 10pt;
                    font-weight: bold;
                }

                .due-details {
                    background: #f8f8f8;
                    padding: 2mm;
                    border-radius: 2mm;
                    margin: 2mm 0;
                    display: flex;
                    justify-content: space-between;
                }

                .due-item {
                    text-align: center;
                }

                .due-label {
                    font-size: 8pt;
                    color: #666;
                }

                .due-value {
                    font-size: 11pt;
                    font-weight: bold;
                }

                .qr-section {
                    text-align: center;
                    margin: 3mm 0;
                }

                .qr-section img {
                    width: 20mm;
                    height: 20mm;
                }

                .print-footer {
                    text-align: center;
                    border-top: 1px dashed #000;
                    padding-top: 2mm;
                    margin-top: 3mm;
                    font-size: 8pt;
                }

                .footer-text {
                    margin-bottom: 1mm;
                }

                .footer-contact {
                    font-size: 7pt;
                    color: #666;
                }

                @media print {
                    * {
                        -webkit-print-color-adjust: exact !important;
                        color-adjust: exact !important;
                    }
                }
            </style>
        </head>
        <body>
            <div class="print-receipt">
                <div class="print-header">
                    <div class="company-name">🏆 GOLD REFINERY</div>
                    <div class="company-address">123 Gold Street, Jewellery Market</div>
                    <div class="receipt-number">Receipt #${document.getElementById('receipt-id').textContent}</div>
                    <div class="receipt-date">${document.getElementById('receipt-date').textContent}</div>
                </div>

                <div class="party-details">
                    <div class="party-name">
                        <i class="fas fa-user-circle"></i> 
                        ${document.getElementById('receipt-party').textContent}
                    </div>
                    <div class="transaction-type">
                        <i class="fas fa-exchange-alt"></i> 
                        ${document.getElementById('receipt-type').textContent}
                    </div>
                </div>

                <div class="details-section">
                    <div class="section-title">Weight Details</div>
                    <div class="weight-details">
                        <div class="weight-item">
                            <div class="item-label">Received</div>
                            <div class="item-value">${document.getElementById('receipt-received-weight').textContent}</div>
                        </div>
                        <div class="weight-item">
                            <div class="item-label">Purity</div>
                            <div class="item-value">${document.getElementById('receipt-purity').textContent}</div>
                        </div>
                        <div class="weight-item">
                            <div class="item-label">Fine</div>
                            <div class="item-value">${document.getElementById('receipt-fine-weight').textContent}</div>
                        </div>
                        <div class="weight-item">
                            <div class="item-label">Issue</div>
                            <div class="item-value">${document.getElementById('receipt-issue-weight').textContent}</div>
                        </div>
                    </div>
                </div>

                <div class="details-section">
                    <div class="section-title">Payment Details</div>
                    <div class="payment-details">
                        <div class="payment-item">
                            <div class="item-label">Rate</div>
                            <div class="item-value">${document.getElementById('receipt-rate').textContent}</div>
                        </div>
                        <div class="payment-item">
                            <div class="item-label">Amount</div>
                            <div class="item-value">${document.getElementById('receipt-amount').textContent}</div>
                        </div>
                        <div class="payment-item">
                            <div class="item-label">Paid</div>
                            <div class="item-value">${document.getElementById('receipt-payment-amount').textContent}</div>
                        </div>
                        <div class="payment-item">
                            <div class="item-label">Status</div>
                            <div class="item-value">${document.getElementById('receipt-payment-status').textContent}</div>
                        </div>
                    </div>
                </div>

                <div class="due-details">
                    <div class="due-item">
                        <div class="due-label">Due Amount</div>
                        <div class="due-value">${document.getElementById('receipt-due-amount').textContent}</div>
                    </div>
                    <div class="due-item">
                        <div class="due-label">Due Gold</div>
                        <div class="due-value">${document.getElementById('receipt-due-gold').textContent}</div>
                    </div>
                </div>

                <div class="qr-section">
                    ${document.getElementById('receipt-qr').innerHTML}
                </div>

                <div class="print-footer">
                    <div class="footer-text">Thank you for your business!</div>
                    <div class="footer-contact">
                        Contact: +1234567890 | Email: info@goldrefinery.com
                    </div>
                </div>
            </div>
        </body>
        </html>
    `;

    if (window.GePrint && typeof window.GePrint.printHtml === 'function') {
        window.GePrint.printHtml(htmlDoc);
        return;
    }

    const printWindow = window.open('', '', 'width=800,height=600');
    printWindow.document.write(htmlDoc);
    printWindow.document.close();
    printWindow.focus();
    
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 500);
}


// Add this to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
// Sidebar auto-hide functionality
const sidebar = document.getElementById('sidebar');
let timeoutId;

sidebar.addEventListener('mouseenter', () => {
clearTimeout(timeoutId);
sidebar.style.transform = 'translateX(0)';
});

sidebar.addEventListener('mouseleave', () => {
timeoutId = setTimeout(() => {
    sidebar.style.transform = 'translateX(-200px)';
}, 300);
});

// Form animations
const inputs = document.querySelectorAll('.form-control');
inputs.forEach(input => {
input.addEventListener('focus', () => {
    input.closest('.input-group').style.boxShadow = '0 0 0 2px var(--primary)';
});

input.addEventListener('blur', () => {
    input.closest('.input-group').style.boxShadow = '0 2px 4px rgba(0,0,0,0.04)';
});
});
});



$(document).ready(function() {
// Table update handling
$('.update-stock').on('click', function(e) {
e.preventDefault();
const id = $(this).data('id');
const newValue = $('#stock-' + id).val();

$.ajax({
url: 'update_stock.php',
type: 'POST',
data: {
    id: id,
    stock: newValue
},
dataType: 'json',
success: function(response) {
    if (response.status === 'success') {
        Swal.fire({
            icon: 'success',
            title: 'Updated!',
            text: 'Stock updated successfully'
        }).then(() => {
            updateTableRow(id, response.data);
        });
    } else {
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: response.message
        });
    }
},
error: function(xhr, status, error) {
    console.error('Update failed:', error);
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: 'Failed to update stock'
    });
}
});
});

function updateTableRow(id, data) {
const row = $(`#row-${id}`);
row.find('.stock-value').text(data.stock);
row.find('.last-updated').text(data.updated_at);
}


// Initialize tooltips
$(function () {
$('[data-bs-toggle="tooltip"]').tooltip();
});
});


// Toast notification function
function showToast(message, type = 'success') {
// Remove any existing toasts
$('.toast-container').remove();

const toastContainer = $('<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>');
const toast = $(`
<div class="toast align-items-center text-white bg-${type === 'success' ? 'success' : 'danger'} border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
        <div class="toast-body">
            ${message}
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
</div>
`);

toastContainer.append(toast);
$('body').append(toastContainer);

const bsToast = new bootstrap.Toast(toast[0], {
autohide: true,
delay: 3000
});

bsToast.show();

// Remove toast container after toast is hidden
toast.on('hidden.bs.toast', function() {
toastContainer.remove();
});
}

// Edit button click handler
$(document).on('click', '.edit-btn', function() {
const id = $(this).data('id');
$.ajax({
url: window.location.href.split('?')[0],
method: 'POST',
data: {
    action: 'get_transaction_details',
    id: id
},
success: function(response) {
    try {
        const data = typeof response === 'string' ? JSON.parse(response) : response;
        if (data) {
            // Populate form fields
            $('[name="receipt_id"]').val(data.receipt_id);
            $('[name="party_name"]').val(data.party_name);
            $('[name="date_of_transaction"]').val(data.date_of_transaction);
            $('[name="received_weight"]').val(data.received_weight);
            $('[name="purity"]').val(data.purity);
            $('[name="fine_weight"]').val(data.fine_weight);
            $('[name="issue_weight"]').val(data.delivered_weight);
            $('[name="rate"]').val(data.rate);
            $('[name="amount"]').val(data.amount);
            $('[name="payment_method"]').val(data.payment_method);
            $('[name="payment_amount"]').val(data.payment_amount);
            $('[name="payment_status"]').val(data.payment_status || 'Due');
            $('[name="narration"]').val(data.narration);
            
            // Calculate transaction type based on weight difference
            const fineWeight = parseFloat(data.fine_weight) || 0;
            const issueWeight = parseFloat(data.delivered_weight) || 0;
            const difference = issueWeight - fineWeight;
            const transactionType = difference > 0 ? 'cash_liya' : 'cash_diya';
            const amountType = difference > 0 ? 'cash_in' : 'cash_out';
            
            $('[name="transaction_type"]').val(transactionType);
            $('[name="amount_type"]').val(amountType);
            
            // Update amount type indicator
            const amountTypeIndicator = $('.amount-type-indicator');
            if (amountType === 'cash_in') {
                amountTypeIndicator.html('<span class="badge bg-success">Cash In</span>');
            } else {
                amountTypeIndicator.html('<span class="badge bg-danger">Cash Out</span>');
            }
            
            // Show success toast
            showToast('Transaction data loaded successfully');
            
            // Scroll to form
            $('html, body').animate({
                scrollTop: $("#transactionForm").offset().top - 100
            }, 500);
        } else {
            showToast('Error loading transaction data', 'danger');
        }
    } catch (e) {
        showToast('Error parsing transaction data', 'danger');
        console.error(e);
    }
},
error: function(xhr, status, error) {
    showToast('Error loading transaction data: ' + error, 'danger');
    console.error(xhr.responseText);
}
});
});

// Delete button click handler
$(document).on('click', '.delete-btn', function() {
const id = $(this).data('id');
const row = $(this).closest('tr');

if (confirm('Are you sure you want to delete this transaction?')) {
// Get the current script name without query parameters
const currentScript = window.location.pathname.split('/').pop();

$.ajax({
    url: currentScript,
    method: 'POST',
    data: {
        action: 'delete_transaction',
        id: id
    },
    dataType: 'json',
    success: function(response) {
        if (response.status === 'success') {
            // Remove the row from the table
            row.fadeOut(400, function() {
                $(this).remove();
            });
            showToast('Transaction deleted successfully');
        } else {
            showToast(response.message || 'Error deleting transaction', 'danger');
            console.error('Delete error:', response.message);
        }
    },
    error: function(xhr, status, error) {
        let errorMessage = 'Error deleting transaction';
        try {
            const response = JSON.parse(xhr.responseText);
            errorMessage = response.message || error;
        } catch (e) {
            errorMessage = error || 'Unknown error occurred';
        }
        showToast(errorMessage, 'danger');
        console.error('Delete error details:', {
            status: status,
            error: error,
            response: xhr.responseText
        });
    }
});
}
});

// Initialize tooltips
$(function() {
$('[data-bs-toggle="tooltip"]').tooltip();
});

// Add event handler for weight changes
$('[name="issue_weight"], [name="fine_weight"]').on('input', function() {
const fineWeight = parseFloat($('[name="fine_weight"]').val()) || 0;
const issueWeight = parseFloat($('[name="issue_weight"]').val()) || 0;
const difference = issueWeight - fineWeight;

// Update transaction type based on difference
const transactionType = difference > 0 ? 'cash_liya' : 'cash_diya';
const amountType = difference > 0 ? 'cash_in' : 'cash_out';

$('[name="transaction_type"]').val(transactionType);
$('[name="amount_type"]').val(amountType);

// Update amount type indicator
const amountTypeIndicator = $('.amount-type-indicator');
if (amountType === 'cash_in') {
amountTypeIndicator.html('<span class="badge bg-success">Cash In</span>');
} else {
amountTypeIndicator.html('<span class="badge bg-danger">Cash Out</span>');
}
});

// Handle due type selection
$('input[name="dueType"]').on('change', function() {
const dueType = $(this).val();
$('#cashDueFields, #refineDueFields').addClass('d-none');
if (dueType === 'cash') {
$('#cashDueFields').removeClass('d-none');
} else if (dueType === 'refine') {
$('#refineDueFields').removeClass('d-none');
}
});

// Save new party with initial due
$('#savePartyBtn').on('click', function() {
const newPartyName = $('#newPartyName').val();
const partyAddress = $('#partyAddress').val();
const partyContact = $('#partyContact').val();
const dueType = $('input[name="dueType"]:checked').val();

if (!newPartyName) {
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: 'Party name is required!'
});
return;
}

const data = {
action: 'save_party',
party_name: newPartyName,
address: partyAddress,
contact_no: partyContact
};

// Add due information if selected
if (dueType === 'cash') {
const dueAmount = parseFloat($('#initialDueAmount').val()) || 0;
if (dueAmount > 0) {
    data.initial_due_amount = dueAmount;
    data.due_type = 'cash';
}
} else if (dueType === 'refine') {
const goldWeight = parseFloat($('#initialGoldWeight').val()) || 0;
if (goldWeight > 0) {
    data.initial_gold_weight = goldWeight;
    data.gold_purity = parseFloat($('#goldPurity').val()) || 24;
    data.due_type = 'refine';
}
}

$.post('', data, function(response) {
if (response.status === 'success') {
    $('#partyNameInput').val(newPartyName);
    $('#partyModal').modal('hide');
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: 'New party added successfully!',
        timer: 2000,
        showConfirmButton: false
    });
} else {
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: response.message
    });
}
}, 'json');
});

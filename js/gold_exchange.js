// Gold Exchange JavaScript
$(document).ready(function () {
    // Generate receipt ID on page load
    generateReceiptId();

    // Set current date/time in Indian timezone
    const now = new Date();
    const indianTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
    const year = indianTime.getFullYear();
    const month = String(indianTime.getMonth() + 1).padStart(2, '0');
    const day = String(indianTime.getDate()).padStart(2, '0');
    const hours = String(indianTime.getHours()).padStart(2, '0');
    const minutes = String(indianTime.getMinutes()).padStart(2, '0');
    const dateTimeString = `${year}-${month}-${day}T${hours}:${minutes}`;
    $('input[name="date_of_transaction"]').val(dateTimeString);

    // Auto-focus on party name field when page loads
    setTimeout(function () {
        $('#partyNameInput').focus();
    }, 100);

    // Initialize payment status display
    updatePaymentStatus();

    // Auto-calculate fine weight when received weight or purity changes
    $('#receivedWeight, #purity').on('input', function () {
        calculateFineWeight();
    });

    // Auto-calculate difference when issue weight changes
    $('#issueWeight').on('input', function () {
        calculateDifference();
    });

    // Auto-calculate amount when rate or difference changes
    $('#rate').on('input', function () {
        calculateAmount();
    });

    // Auto-update payment status when payment amount changes
    $('#paymentAmount').on('input', function () {
        updatePaymentStatus();
    });

    // Party name autocomplete with keyboard navigation
    searchTimeout = null;
    partyListVisible = false;
    currentIndex = -1;
    selectedPartyName = '';

    $('#partyNameInput').on('input', function () {
        const searchTerm = $(this).val();

        // Reset selection if user modifies the selected party name
        if (searchTerm !== selectedPartyName) {
            selectedPartyName = '';
            $('#partyNameInput').removeClass('border-green-500');
        }

        if (searchTerm.length < 1) {
            $('#partyList').addClass('hidden').empty();
            $('#partyDueInfo').addClass('hidden');
            partyListVisible = false;
            currentIndex = -1;
            return;
        }

        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function () {
            searchParties(searchTerm);
        }, 300);
    });

    // Keyboard navigation for party list
    $('#partyNameInput').on('keydown', function (e) {
        // Alt+A to add new party
        if (e.altKey && (e.key === 'a' || e.key === 'A')) {
            e.preventDefault();
            e.stopPropagation();
            showAddPartyModal();
            return;
        }

        const partyItems = document.querySelectorAll('#partyList .party-item');

        // Arrow Down
        if (e.key === 'ArrowDown' && partyListVisible && partyItems.length > 0) {
            e.preventDefault();
            if (currentIndex < 0) {
                currentIndex = 0;
            } else {
                currentIndex = Math.min(currentIndex + 1, partyItems.length - 1);
            }
            updatePartyHighlight();
        }
        // Arrow Up
        else if (e.key === 'ArrowUp' && partyListVisible && partyItems.length > 0) {
            e.preventDefault();
            if (currentIndex <= 0) {
                currentIndex = -1;
            } else {
                currentIndex = Math.max(currentIndex - 1, 0);
            }
            updatePartyHighlight();
        }
        // Enter to select
        else if (e.key === 'Enter' && partyListVisible && currentIndex >= 0 && partyItems.length > 0) {
            e.preventDefault();
            const selectedItem = partyItems[currentIndex];

            // Check if this is the "Create New Party" item
            const isCreateNew = selectedItem.hasAttribute('data-create-new');

            if (isCreateNew) {
                // Directly call createNewPartyQuick with the current search term
                const term = selectedItem.getAttribute('data-name') || $('#partyNameInput').val().trim();
                createNewPartyQuick(term);
            } else {
                // Regular party selection
                const partyData = {
                    id: selectedItem.getAttribute('data-id'),
                    party_name: selectedItem.getAttribute('data-name'),
                    address: selectedItem.getAttribute('data-address')
                };
                selectParty(partyData);
            }
        }
    });

    // Update party highlight
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

    // Add new party button
    $('#addNewPartyBtn').click(function () {
        showAddPartyModal();
    });

    // Form submit
    $('#exchangeForm').submit(function (e) {
        e.preventDefault();
        saveTransaction();
    });

    // Reset form button
    $('#resetFormBtn').click(function () {
        resetForm();
    });

    // Delete button (for form)
    $('#deleteBtn').click(function () {
        const transactionId = $('input[name="transaction_id"]').val();
        if (transactionId) {
            deleteTransaction(transactionId);
        }
    });

    // Edit transaction
    $(document).on('click', '.edit-transaction', function () {
        const id = $(this).data('id');
        loadTransaction(id);
    });

    // Delete transaction
    $(document).on('click', '.delete-transaction', function () {
        const id = $(this).data('id');
        deleteTransaction(id);
    });

    // Hide party list when clicking outside
    $(document).click(function (e) {
        if (!$(e.target).closest('#partyNameInput, #partyList').length) {
            $('#partyList').addClass('hidden');
            partyListVisible = false;
            currentIndex = -1;
        }
    });
});

// Generate unique receipt ID
function generateReceiptId() {
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'get_next_receipt_id'
        },
        dataType: 'json',
        success: function (response) {
            $('input[name="receipt_id"]').val(response.receipt_id);
        },
        error: function () {
            // Fallback to timestamp-based ID if server fails
            const prefix = 'EX';
            const timestamp = Date.now().toString().slice(-6);
            $('input[name="receipt_id"]').val(`${prefix}${timestamp}`);
        }
    });
}

// Calculate fine weight
function calculateFineWeight() {
    const receivedWeight = parseFloat($('#receivedWeight').val()) || 0;
    const purity = parseFloat($('#purity').val()) || 0;
    const fineWeight = (receivedWeight * purity) / 100;
    $('#fineWeight').val(fineWeight.toFixed(3));
    calculateDifference();
}

// Calculate difference
function calculateDifference() {
    const fineWeight = parseFloat($('#fineWeight').val()) || 0;
    const issueWeight = parseFloat($('#issueWeight').val()) || 0;
    const difference = issueWeight - fineWeight;
    $('#differenceWeight').val(difference.toFixed(3));
    calculateAmount();
}

// Calculate amount
function calculateAmount() {
    const difference = parseFloat($('#differenceWeight').val()) || 0;
    const rate = parseFloat($('#rate').val()) || 0;
    const amount = Math.round(Math.abs(difference) * rate); // Round to whole number
    $('#amount').val(amount);

    // Color code the difference field
    const $diffField = $('#differenceWeight');
    $diffField.removeClass('text-red-600 text-green-600 font-bold');

    if (difference > 0) {
        // Positive difference - customer pays (green)
        $diffField.addClass('text-green-600 font-bold');
        $('#amountType').text('Cash In (Customer Pays)').removeClass('text-red-600').addClass('text-green-600');
        $('#paymentAmountLabel').html('<strong>Received Amount (₹)</strong>');
    } else if (difference < 0) {
        // Negative difference - you pay (red)
        $diffField.addClass('text-red-600 font-bold');
        $('#amountType').text('Cash Out (You Pay)').removeClass('text-green-600').addClass('text-red-600');
        $('#paymentAmountLabel').html('<strong>Paid Amount (₹)</strong>');
    } else {
        $('#amountType').text('No Exchange').removeClass('text-green-600 text-red-600').addClass('text-gray-600');
        $('#paymentAmountLabel').html('<strong>Paid Amount (₹)</strong>');
    }

    updatePaymentStatus();
}

// Update payment status
function updatePaymentStatus() {
    const amount = parseFloat($('#amount').val()) || 0;
    const paymentAmount = parseFloat($('#paymentAmount').val()) || 0;

    let status = 'Due';
    let statusClass = 'bg-red-100 text-red-700';
    let statusIcon = 'fa-exclamation-circle';

    if (amount > 0 && paymentAmount >= amount) {
        status = 'Paid';
        statusClass = 'bg-green-100 text-green-700';
        statusIcon = 'fa-check-circle';
    } else if (paymentAmount > 0) {
        status = 'Partial';
        statusClass = 'bg-yellow-100 text-yellow-700';
        statusIcon = 'fa-clock';
    } else {
        status = 'Due';
        statusClass = 'bg-red-100 text-red-700';
        statusIcon = 'fa-exclamation-circle';
    }

    // Update the hidden payment_status field
    $('input[name="payment_status"]').val(status);
}

// Search parties
function searchParties(searchTerm) {
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'search_parties',
            term: searchTerm
        },
        dataType: 'json',
        success: function (parties) {
            displayPartyList(parties);
        },
        error: function () {
            console.error('Error searching parties');
        }
    });
}

// Display party list
function displayPartyList(parties) {
    const $partyList = $('#partyList');
    $partyList.empty();
    currentIndex = -1;

    const searchTerm = $('#partyNameInput').val();

    if (parties.length === 0) {
        // No matches found - show "Create New Party" option
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

    // Add "Create New Party" option at the bottom
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

// Select party
function selectParty(party) {
    selectedPartyName = party.party_name;
    $('#partyNameInput').val(party.party_name).addClass('border-green-500');
    $('#partyList').addClass('hidden');
    partyListVisible = false;
    currentIndex = -1;

    // Load party dues
    loadPartyDues(party.party_name);

    // Focus next field
    $('#receivedWeight').focus();
}

// Load party dues
function loadPartyDues(partyName) {
    console.log('loadPartyDues called for:', partyName);

    // Check if element exists
    if ($('#partyDueInfoInline').length === 0) {
        console.error('partyDueInfoInline element not found in DOM');
        return;
    }

    if (!partyName || partyName.trim() === '') {
        console.log('Party name is empty, skipping');
        $('#partyDueInfoInline').addClass('hidden');
        // Update payment status visibility after hiding outstanding balance
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
            console.log('Party dues response:', data);
            if (data && (parseFloat(data.due_amount) > 0 || parseFloat(data.due_gold) > 0)) {
                $('#dueAmountValueInline').text('₹' + parseFloat(data.due_amount).toFixed(2));
                $('#dueGoldValueInline').text(parseFloat(data.due_gold).toFixed(3) + 'g');
                $('#partyDueInfoInline').removeClass('hidden');
                console.log('Outstanding balance displayed inline - Amount:', data.due_amount, 'Gold:', data.due_gold);
            } else {
                $('#partyDueInfoInline').addClass('hidden');
                console.log('No outstanding balance - Amount:', data.due_amount, 'Gold:', data.due_gold);
            }
            // Update payment status visibility after loading dues
            updatePaymentStatus();
        },
        error: function (xhr, status, error) {
            console.error('Error loading party dues:', error, xhr.responseText);
            $('#partyDueInfoInline').addClass('hidden');
            // Update payment status visibility after error
            updatePaymentStatus();
        }
    });
}

// Show add party modal
function showAddPartyModal() {
    SharedPartyHandler.showAddPartyModal({
        onSuccess: function(response, partyData) {
            $('#partyNameInput').val(partyData.party_name);
            $('#partyId').val(response.party_id);
            selectedPartyName = partyData.party_name;
            
            // Visual feedback
            $('#partyNameInput').addClass('border-green-500');
            setTimeout(() => $('#partyNameInput').removeClass('border-green-500'), 2000);
            
            // Load dues for the new party (now includes multi-metal)
            loadPartyDues(partyData.party_name);

            // Focus next field
            $('#receivedWeight').focus();
        }
    });
}


// Quick create party (auto-create from typed name)
function createNewPartyQuick(partyName) {
    if (!partyName || partyName.trim() === '') {
        return;
    }

    // Automatically create the party with just the name
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
                // Show brief success notification
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

                // Set the party name and proceed
                $('#partyNameInput').val(partyName).addClass('border-green-500');
                selectedPartyName = partyName;
                $('#partyList').addClass('hidden');
                partyListVisible = false;

                // Focus next field
                $('#receivedWeight').focus();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message,
                    width: '320px',
                    confirmButtonColor: '#ef4444',
                    customClass: {
                        title: 'text-sm font-bold',
                        htmlContainer: 'text-[11px]',
                        confirmButton: 'text-[10px] px-4 py-1.5 font-bold uppercase uppercase tracking-tighter'
                    }
                });
            }
        },
        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to create party',
                width: '320px',
                confirmButtonColor: '#ef4444',
                customClass: {
                    title: 'text-sm font-bold',
                    htmlContainer: 'text-[11px]',
                    confirmButton: 'text-[10px] px-4 py-1.5 font-bold uppercase uppercase tracking-tighter'
                }
            });
        }
    });
}


// Save transaction
function saveTransaction() {
    // Ensure payment status is updated before submission
    updatePaymentStatus();

    const formData = $('#exchangeForm').serialize();

    $.ajax({
        url: '',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
                // Show success message with print option
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    text: response.message,
                    width: '320px',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-print mr-1"></i>PRINT',
                    cancelButtonText: 'CLOSE',
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#64748b',
                    customClass: {
                        title: 'text-sm font-bold',
                        htmlContainer: 'text-[11px]',
                        confirmButton: 'text-[10px] px-3 py-1.5 font-bold uppercase tracking-tighter',
                        cancelButton: 'text-[10px] px-3 py-1.5 font-bold uppercase tracking-tighter'
                    }
                }).then((result) => {
                    if (result.isConfirmed && response.transaction_id) {
                        // Open thermal receipt in new window
                        window.open('print_exchange_receipt.php?id=' + response.transaction_id, '_blank');
                    }
                    // Reload page and focus on party field for next entry
                    window.location.href = window.location.pathname + '?focus=party';
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message,
                    width: '320px',
                    confirmButtonColor: '#ef4444',
                    customClass: {
                        title: 'text-sm font-bold',
                        htmlContainer: 'text-[11px]',
                        confirmButton: 'text-[10px] px-4 py-1.5 font-bold uppercase tracking-tighter'
                    }
                });
            }
        },
        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Operation failed',
                width: '320px',
                confirmButtonColor: '#ef4444',
                customClass: {
                    title: 'text-sm font-bold',
                    htmlContainer: 'text-[11px]',
                    confirmButton: 'text-[10px] px-4 py-1.5 font-bold uppercase tracking-tighter'
                }
            });
        }
    });
}

// Load transaction for editing
function loadTransaction(id) {
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'get_transaction_details',
            id: id
        },
        dataType: 'json',
        success: function (transaction) {
            // Populate form
            $('input[name="transaction_id"]').val(transaction.id);
            $('input[name="receipt_id"]').val(transaction.receipt_id);
            $('input[name="date_of_transaction"]').val(transaction.date_of_transaction.replace(' ', 'T'));
            $('input[name="party_name"]').val(transaction.party_name);
            $('#receivedWeight').val(transaction.received_weight);
            $('#purity').val(transaction.purity);
            $('#fineWeight').val(transaction.fine_weight);
            $('#issueWeight').val(transaction.delivered_weight);
            $('#differenceWeight').val(transaction.difference_weight);
            $('#rate').val(transaction.rate);
            $('#amount').val(transaction.amount);
            $('#paymentAmount').val(transaction.payment_amount);
            $('select[name="payment_method"]').val(transaction.payment_method);
            $('input[name="payment_status"]').val(transaction.payment_status);
            $('input[name="narration"]').val(transaction.narration);

            // Recalculate payment status to ensure it's correct
            updatePaymentStatus();

            // Show delete button and change submit text
            $('#deleteBtn').removeClass('hidden');
            $('#submitText').text('Update');
            $('#submitIcon').removeClass('fa-exchange-alt').addClass('fa-save');

            // Scroll to form
            $('html, body').animate({
                scrollTop: $('#exchangeForm').offset().top - 100
            }, 500);
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

// Delete transaction
function deleteTransaction(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will delete the transaction and restore the stock!",
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
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message,
                            confirmButtonColor: '#EAB308'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message,
                            width: '320px',
                            confirmButtonColor: '#EAB308',
                            customClass: {
                                title: 'text-sm font-bold',
                                htmlContainer: 'text-[11px]',
                                confirmButton: 'text-[10px] px-4 py-1.5 font-bold uppercase tracking-tighter'
                            }
                        });
                    }
                },
                error: function () {
                    Swal.fire({
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

// Reset form
function resetForm() {
    $('#exchangeForm')[0].reset();
    $('input[name="transaction_id"]').val('');
    generateReceiptId();

    // Set current date/time in Indian timezone
    const now = new Date();
    const indianTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
    const year = indianTime.getFullYear();
    const month = String(indianTime.getMonth() + 1).padStart(2, '0');
    const day = String(indianTime.getDate()).padStart(2, '0');
    const hours = String(indianTime.getHours()).padStart(2, '0');
    const minutes = String(indianTime.getMinutes()).padStart(2, '0');
    const dateTimeString = `${year}-${month}-${day}T${hours}:${minutes}`;
    $('input[name="date_of_transaction"]').val(dateTimeString);

    $('#partyDueInfo').addClass('hidden');
    $('#paymentStatusInfo').addClass('hidden');
    $('#amountType').text('');
    $('#partyNameInput').removeClass('border-green-500');
    selectedPartyName = '';

    // Hide delete button
    $('#deleteBtn').addClass('hidden');

    // Reset button to "Save"
    $('#submitText').text('Save');
    $('#submitIcon').removeClass('fa-save').addClass('fa-exchange-alt');

    // Reset difference field color
    $('#differenceWeight').removeClass('text-red-600 text-green-600 font-bold');

    // Reset payment status display
    updatePaymentStatus();
}

// Sale.php - Custom JavaScript for purity autocomplete and stock management
// This overrides/extends exchange.js for sale-specific functionality

$(document).ready(function () {
    console.log('Sale.js loaded - initializing sale-specific features');
    
    // Verify receiptId element exists
    if ($('#receiptId').length === 0) {
        console.error('ERROR: receiptId element not found!');
    } else {
        console.log('receiptId element found');
    }
    
    // Verify receiptSuggestions element exists
    if ($('#receiptSuggestions').length === 0) {
        console.error('ERROR: receiptSuggestions element not found!');
    } else {
        console.log('receiptSuggestions element found');
    }

    let availableStocks = []; // Store stocks globally
    let selectedIndex = -1; // Track selected suggestion for keyboard navigation

    // Load available stocks
    function loadPurityStocks() {
        $.ajax({
            url: '',
            type: 'POST',
            data: { action: 'get_stocks_by_purity' },
            success: function (response) {
                availableStocks = JSON.parse(response);
                console.log('Loaded', availableStocks.length, 'purity options');
            },
            error: function (xhr, status, error) {
                console.error('Failed to load stocks:', error);
            }
        });
    }

    // Show purity suggestions when user types
    $('#purity').on('input focus', function () {
        const inputValue = $(this).val().trim();
        const suggestionsDiv = $('#puritySuggestions');
        selectedIndex = -1; // Reset selection

        if (availableStocks.length === 0) {
            suggestionsDiv.addClass('hidden');
            return;
        }

        // Filter stocks based on input
        let filtered = availableStocks;
        if (inputValue) {
            filtered = availableStocks.filter(stock =>
                stock.purity.toString().includes(inputValue) ||
                stock.stock_name.toLowerCase().includes(inputValue.toLowerCase())
            );
        }

        if (filtered.length === 0) {
            suggestionsDiv.addClass('hidden');
            return;
        }

        // Build suggestions HTML
        let html = '';
        filtered.forEach((stock, index) => {
            const displayText = `${stock.purity}%`;
            const subText = `${stock.stock_name} (${parseFloat(stock.current_stock).toFixed(3)}g available)`;
            html += `
                <div class="purity-suggestion px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 transition" 
                     data-index="${index}"
                     data-purity="${stock.purity}" 
                     data-stock="${stock.current_stock}">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="font-bold text-sm text-gray-900">${displayText}</div>
                            <div class="text-xs text-gray-500 mt-0.5">${subText}</div>
                        </div>
                        <div class="text-green-600 text-xs font-semibold">
                            <i class="fas fa-check-circle"></i> ${parseFloat(stock.current_stock).toFixed(3)}g
                        </div>
                    </div>
                </div>
            `;
        });

        suggestionsDiv.html(html).removeClass('hidden');
    });

    // Keyboard navigation for suggestions - ONLY when dropdown is visible
    $('#purity').on('keydown', function (e) {
        const suggestionsDiv = $('#puritySuggestions');
        const suggestions = $('.purity-suggestion');

        // Only handle keyboard navigation if dropdown is visible
        if (suggestionsDiv.hasClass('hidden') || suggestions.length === 0) {
            return; // Allow normal form navigation (Tab, Enter go to next field)
        }

        // Arrow Down
        if (e.keyCode === 40) {
            e.preventDefault();
            selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
            updateSelectedSuggestion(suggestions);
        }
        // Arrow Up
        else if (e.keyCode === 38) {
            e.preventDefault();
            selectedIndex = Math.max(selectedIndex - 1, -1);
            updateSelectedSuggestion(suggestions);
        }
        // Enter - only if a suggestion is highlighted
        else if (e.keyCode === 13 && selectedIndex >= 0) {
            e.preventDefault();
            $(suggestions[selectedIndex]).click();
        }
        // Tab or Enter with no selection - close dropdown and allow normal navigation
        else if (e.keyCode === 9 || e.keyCode === 13) {
            suggestionsDiv.addClass('hidden');
            selectedIndex = -1;
            // Don't prevent default - allow tab/enter to work normally
        }
        // Escape
        else if (e.keyCode === 27) {
            suggestionsDiv.addClass('hidden');
            selectedIndex = -1;
        }
    });

    function updateSelectedSuggestion(suggestions) {
        // Remove previous highlight
        suggestions.removeClass('bg-blue-100');

        // Highlight current selection
        if (selectedIndex >= 0) {
            $(suggestions[selectedIndex]).addClass('bg-blue-100');
            // Scroll into view if needed
            suggestions[selectedIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    // Handle suggestion click
    $(document).on('click', '.purity-suggestion', function () {
        const purity = $(this).data('purity');
        const availableStock = $(this).data('stock');

        $('#purity').val(purity);
        $('#puritySuggestions').addClass('hidden');
        selectedIndex = -1;

        // Show available stock amount for this purity
        console.log(`Selected ${purity}% - Available: ${availableStock}g`);

        calculateAmount(); // Recalculate amount
    });

    // Hide suggestions when clicking outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#purity, #puritySuggestions').length) {
            $('#puritySuggestions').addClass('hidden');
            selectedIndex = -1;
        }
    });

    // Calculate amount when weight or rate changes
    function calculateAmount() {
        const weight = parseFloat($('#weight').val()) || 0;
        const rate = parseFloat($('#rate').val()) || 0;
        const amount = weight * rate;
        $('#amount').val(amount.toFixed(2));
        updatePaymentStatus(); // Update payment status when amount changes
    }

    // Update payment status dynamically
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
        
        // Update the visible payment status badge in outstanding balance section (if visible)
        const badgeHtml = `<span class="px-2 py-0.5 rounded-full ${statusClass} text-xs font-semibold whitespace-nowrap">
            <i class="fas ${statusIcon} mr-1"></i>${status}
        </span>`;
        $('#paymentStatusBadge').html(badgeHtml);
        
        // Update the standalone payment status badge (if outstanding balance is not shown)
        $('#paymentStatusBadgeStandalone').html(badgeHtml);
        
        // Show/hide payment status sections based on outstanding balance visibility
        const hasOutstanding = !$('#partyDueInfo').hasClass('hidden');
        if (hasOutstanding) {
            $('#paymentStatusInfo').addClass('hidden');
        } else {
            // Show standalone payment status if there's an amount to track
            if (amount > 0) {
                $('#paymentStatusInfo').removeClass('hidden');
            } else {
                $('#paymentStatusInfo').addClass('hidden');
            }
        }
    }

    // Validate stock before submission
    function validateStock() {
        const weight = parseFloat($('#weight').val()) || 0;
        const purity = parseFloat($('#purity').val()) || 0;

        const stock = availableStocks.find(s => parseFloat(s.purity) === purity);
        if (stock) {
            const available = parseFloat(stock.current_stock);
            if (weight > available) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Insufficient Stock',
                    text: `Only ${available.toFixed(3)}g available for ${purity}% purity`
                });
                return false;
            }
        }
        return true;
    }

    // Override the form submit to use 'weight' instead of 'received_weight'
    const originalSubmit = $('#exchangeForm').off('submit');
    $('#exchangeForm').on('submit', function (e) {
        e.preventDefault();

        // Validate stock first
        if (!validateStock()) {
            return false;
        }

        const formData = $(this).serializeArray();
        const data = {};
        formData.forEach(item => {
            data[item.name] = item.value;
        });

        // Ensure we have weight value from the weight field
        data.weight = $('#weight').val();
        data.action = 'save_transaction';

        // Calculate fine_weight on backend, but we can send it for reference
        const weight = parseFloat(data.weight) || 0;
        const purity = parseFloat(data.purity) || 0;
        data.fine_weight = (weight * (purity / 100)).toFixed(3);

        // Submit the sale transaction
        $.ajax({
            url: '',
            type: 'POST',
            data: data,
            success: function (response) {
                try {
                    const result = JSON.parse(response);
                    if (result.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: result.message,
                            showConfirmButton: false,
                            timer: 1500
                        }).then(() => {
                            location.reload(); // Reload to refresh transaction list and stats
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: result.message
                        });
                    }
                } catch (err) {
                    console.error('Parse error:', err, response);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Server returned invalid response. Check console for details.'
                    });
                }
            },
            error: function (xhr, status, error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to save transaction: ' + error
                });
            }
        });
    });

    // Event listeners for calculations
    $('#weight, #rate').on('input change', calculateAmount);
    
    // Auto-update payment status when payment amount changes
    $('#paymentAmount').on('input', function () {
        updatePaymentStatus();
    });
    
    // Initialize payment status display on page load
    updatePaymentStatus();

    // Prevent Enter key from submitting form on input fields - move to next field instead
    $('#exchangeForm input:not([type=submit])').on('keypress', function (e) {
        if (e.keyCode === 13) {
            e.preventDefault();
            // Move to next visible input field
            const inputs = $('#exchangeForm input:visible:not([readonly]):not([type=submit])');
            const index = inputs.index(this);
            if (index < inputs.length - 1) {
                inputs.eq(index + 1).focus();
            }
            return false;
        }
    });

    // Auto-generate receipt ID on page load
    function loadNextReceiptId() {
        $.ajax({
            url: '',
            type: 'POST',
            data: { action: 'get_next_receipt_id' },
            success: function (response) {
                try {
                    const result = JSON.parse(response);
                    $('#receiptId').val(result.receipt_id);
                    console.log('Generated receipt ID:', result.receipt_id);
                } catch (e) {
                    console.error('Failed to parse receipt ID response:', e);
                }
            },
            error: function (xhr, status, error) {
                console.error('Failed to get receipt ID:', error);
            }
        });
    }

    // Load stocks and receipt ID on page load
    loadPurityStocks();
    loadNextReceiptId();

    // Reload stocks every 30 seconds to keep current_stock updated
    setInterval(loadPurityStocks, 30000);

    // Handle Sale Receipt Printing
    $(document).on('click', '.print-sale-receipt', function (e) {
        e.preventDefault();
        const id = $(this).data('id');
        if (id) {
            window.open('print_sale_receipt.php?id=' + id, '_blank');
        }
    });

    // Handle Sale ID Search
    let receiptSearchTimeout = null;
    
    function loadReceiptSuggestions(term, showRecentIfEmpty = false) {
        console.log('loadReceiptSuggestions called with term:', term, 'showRecentIfEmpty:', showRecentIfEmpty);
        clearTimeout(receiptSearchTimeout);
        
        receiptSearchTimeout = setTimeout(function () {
            // If term is empty or we want to show recent, use empty string to get recent sales
            const searchTerm = (showRecentIfEmpty || !term) ? '' : term;
            console.log('Fetching receipts for term:', searchTerm || '(empty - showing recent)');
            
            $.ajax({
                url: '',
                type: 'POST',
                data: { action: 'search_receipt_ids', term: searchTerm },
                success: function (response) {
                    console.log('Receipt search response received:', response.substring(0, 200));
                    try {
                        const receipts = JSON.parse(response);
                        console.log('Parsed receipts count:', receipts.length, receipts);
                        const list = $('#receiptSuggestions');
                        
                        if (!list.length) {
                            console.error('ERROR: receiptSuggestions element not found in DOM!');
                            return;
                        }
                        
                        list.empty();

                        if (receipts.length > 0) {
                            receipts.forEach(function (r) {
                                const labelParts = r.label ? r.label.split(' - ') : [];
                                const partyInfo = labelParts.length > 1 ? labelParts[1] : (r.party_name || '');
                                list.append(`
                                    <div class="px-4 py-2 hover:bg-blue-50 cursor-pointer text-sm border-b border-gray-100 transition" 
                                         data-id="${r.receipt_id}">
                                        <div class="font-bold text-gray-800">${r.receipt_id}</div>
                                        <div class="text-xs text-gray-500">${partyInfo}</div>
                                    </div>
                                `);
                            });
                            list.removeClass('hidden').css('display', 'block');
                            console.log('✓ Suggestions shown, count:', receipts.length, 'Element visible:', list.is(':visible'), 'Has hidden class:', list.hasClass('hidden'));
                        } else {
                            // If no results and we were trying to show recent, that's fine - just hide
                            // But if user was searching, maybe try showing recent as fallback
                            if (showRecentIfEmpty && searchTerm === '') {
                                console.log('No recent receipts found in database');
                            }
                            list.addClass('hidden');
                            console.log('No receipts found - hiding dropdown');
                        }
                    } catch (e) {
                        console.error('Error parsing receipts', e, 'Response:', response);
                        $('#receiptSuggestions').addClass('hidden');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX Error searching receipts:', error, 'Status:', status, 'Response:', xhr.responseText);
                    $('#receiptSuggestions').addClass('hidden');
                }
            });
        }, 100);
    }
    
    // Separate handlers for better control
    $('#receiptId').on('focus click', function (e) {
        console.log('Sale ID field focused/clicked, current value:', $(this).val());
        // Always show recent sales on focus/click, regardless of current value
        loadReceiptSuggestions('', true);
    });
    
    $('#receiptId').on('input', function () {
        const term = $(this).val().trim();
        console.log('Input event, term:', term);
        // When typing, search for the term (don't force show recent)
        loadReceiptSuggestions(term, false);
    });

    // Handle suggestion selection
    $(document).on('click', '#receiptSuggestions > div', function (e) {
        e.preventDefault();
        e.stopPropagation();
        
        const receiptId = $(this).data('id');
        console.log('Receipt selected:', receiptId);
        
        // Hide dropdown immediately - use both methods to ensure it's hidden
        const suggestions = $('#receiptSuggestions');
        suggestions.addClass('hidden').css('display', 'none').hide();
        
        // Set the receipt ID
        $('#receiptId').val(receiptId);

        // Load transaction details
        $.ajax({
            url: '',
            type: 'POST',
            data: { action: 'get_exchange_by_receipt_id', receipt_id: receiptId }, // Using existing backend endpoint
            success: function (response) {
                try {
                    const res = JSON.parse(response);
                    if (res.status === 'success') {
                        const t = res.data;
                        // Populate form with Sale details
                        $('input[name="transaction_id"]').val(t.id);
                        $('input[name="date_of_transaction"]').val(t.date_of_transaction.replace(' ', 'T'));

                        // Handle Party
                        $('#partyNameInput').val(t.party_name).addClass('border-green-500');
                        window.selectedPartyName = t.party_name;

                        // Handle Sale Fields
                        $('#weight').val(t.gold_weight);
                        $('#purity').val(t.purity);
                        $('#currentRate').text(parseFloat(t.rate).toFixed(2));
                        $('#rate').val(t.rate);
                        $('#amount').val(t.gold_amount);

                        // Handle Payment
                        $('#paymentAmount').val(t.payment_amount || 0);
                        $('select[name="payment_method"]').val(t.payment_method || 'Cash');
                        if (t.payment_status) {
                            $('input[name="payment_status"]').val(t.payment_status);
                        } else {
                            // Determine payment status based on payment amount
                            const paymentAmount = parseFloat(t.payment_amount || 0);
                            const totalAmount = parseFloat(t.gold_amount || 0);
                            if (paymentAmount >= totalAmount) {
                                $('input[name="payment_status"]').val('Paid');
                            } else if (paymentAmount > 0) {
                                $('input[name="payment_status"]').val('Partial');
                            } else {
                                $('input[name="payment_status"]').val('Due');
                            }
                        }
                        $('input[name="narration"]').val(t.narration || '');
                        
                        // Recalculate amount type display
                        calculateAmount();

                        // Update UI state
                        $('#submitText').text('Update Sale');
                        $('#submitIcon').removeClass('fa-save').addClass('fa-edit');
                        $('#deleteBtn').removeClass('hidden');
                    }
                } catch (e) {
                    console.error('Error loading transaction', e);
                }
            }
        });
    });

    // Hide suggestions on click outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#receiptId, #receiptSuggestions').length) {
            $('#receiptSuggestions').addClass('hidden').css('display', 'none').hide();
        }
    });
    
    // Hide suggestions when input loses focus (with delay to allow click on suggestion)
    $('#receiptId').on('blur', function (e) {
        // Small delay to allow click events on suggestions to fire first
        setTimeout(function () {
            const activeElement = document.activeElement;
            // Only hide if focus didn't move to a suggestion item
            if (!$(activeElement).closest('#receiptSuggestions').length) {
                $('#receiptSuggestions').addClass('hidden').css('display', 'none').hide();
            }
        }, 200);
    });
    
    // Also hide when clicking on the input field itself (to prevent re-showing immediately)
    $('#receiptId').on('blur', function (e) {
        // Small delay to allow click events on suggestions to fire first
        setTimeout(function () {
            if (!$(document.activeElement).closest('#receiptSuggestions').length) {
                $('#receiptSuggestions').addClass('hidden').css('display', 'none');
            }
        }, 200);
    });

    // Handle Edit button click from transaction list
    $(document).on('click', '.edit-sale-btn', function (e) {
        e.preventDefault();
        const receiptId = $(this).data('receipt-id');
        if (receiptId) {
            $('#receiptId').val(receiptId);
            $('#receiptSuggestions').addClass('hidden');
            
            // Load transaction details directly
            $.ajax({
                url: '',
                type: 'POST',
                data: { action: 'get_exchange_by_receipt_id', receipt_id: receiptId },
                success: function (response) {
                    try {
                        const res = JSON.parse(response);
                        if (res.status === 'success') {
                            const t = res.data;
                            // Populate form with Sale details
                            $('input[name="transaction_id"]').val(t.id);
                            $('input[name="date_of_transaction"]').val(t.date_of_transaction.replace(' ', 'T'));

                            // Handle Party
                            $('#partyNameInput').val(t.party_name).addClass('border-green-500');
                            window.selectedPartyName = t.party_name;

                            // Handle Sale Fields
                            $('#weight').val(t.gold_weight);
                            $('#purity').val(t.purity);
                            $('#rate').val(t.rate);
                            $('#amount').val(t.gold_amount);

                            // Handle Payment
                            $('#paymentAmount').val(t.payment_amount || 0);
                            $('select[name="payment_method"]').val(t.payment_method || 'Cash');
                            $('input[name="payment_status"]').val(t.payment_status || 'Due');
                            $('input[name="narration"]').val(t.narration || '');
                            
                            // Recalculate amount type display
                            calculateAmount();

                            // Update UI state
                            $('#submitText').text('Update Sale');
                            $('#submitIcon').removeClass('fa-save').addClass('fa-edit');
                            $('#deleteBtn').removeClass('hidden');
                            
                            // Scroll to form
                            $('html, body').animate({
                                scrollTop: $('#exchangeForm').offset().top - 100
                            }, 500);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: res.message || 'Transaction not found'
                            });
                        }
                    } catch (e) {
                        console.error('Error loading transaction', e);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to load transaction details'
                        });
                    }
                },
                error: function (xhr, status, error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load transaction: ' + error
                    });
                }
            });
        }
    });

    // Handle Delete button click from transaction list
    $(document).on('click', '.delete-sale-btn', function (e) {
        e.preventDefault();
        const transactionId = $(this).data('id');
        const receiptId = $(this).data('receipt-id');
        
        Swal.fire({
            icon: 'warning',
            title: 'Delete Sale Transaction?',
            text: `Are you sure you want to delete sale ${receiptId}? This action cannot be undone.`,
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '',
                    type: 'POST',
                    data: {
                        action: 'delete_transaction',
                        id: transactionId
                    },
                    success: function (response) {
                        try {
                            const result = JSON.parse(response);
                            if (result.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted!',
                                    text: result.message,
                                    showConfirmButton: false,
                                    timer: 1500
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: result.message
                                });
                            }
                        } catch (e) {
                            console.error('Error parsing delete response', e);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to delete transaction'
                            });
                        }
                    },
                    error: function (xhr, status, error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to delete transaction: ' + error
                        });
                    }
                });
            }
        });
    });

    // Handle Delete button in form
    $('#deleteBtn').on('click', function (e) {
        e.preventDefault();
        const transactionId = $('input[name="transaction_id"]').val();
        
        if (!transactionId) {
            Swal.fire({
                icon: 'warning',
                title: 'No Transaction Selected',
                text: 'Please select a transaction to delete'
            });
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Delete Sale Transaction?',
            text: 'Are you sure you want to delete this sale transaction? This action cannot be undone.',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '',
                    type: 'POST',
                    data: {
                        action: 'delete_transaction',
                        id: transactionId
                    },
                    success: function (response) {
                        try {
                            const result = JSON.parse(response);
                            if (result.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted!',
                                    text: result.message,
                                    showConfirmButton: false,
                                    timer: 1500
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: result.message
                                });
                            }
                        } catch (e) {
                            console.error('Error parsing delete response', e);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to delete transaction'
                            });
                        }
                    },
                    error: function (xhr, status, error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to delete transaction: ' + error
                        });
                    }
                });
            }
        });
    });

    // Handle Reset Form button
    $('#resetFormBtn').on('click', function (e) {
        e.preventDefault();
        $('#exchangeForm')[0].reset();
        $('input[name="transaction_id"]').val('');
        $('#deleteBtn').addClass('hidden');
        $('#submitText').text('Save');
        $('#submitIcon').removeClass('fa-edit').addClass('fa-save');
        $('#partyNameInput').removeClass('border-green-500');
        $('#partyDueInfo').addClass('hidden');
        $('#paymentStatusInfo').addClass('hidden');
        loadNextReceiptId();
        updatePaymentStatus(); // Reset payment status display
    });
    
    // Load party dues when party is selected (override or extend exchange.js selectParty)
    // This will be called by exchange.js selectParty function, but we ensure it works
    if (typeof loadPartyDues === 'undefined') {
        window.loadPartyDues = function(partyName) {
            console.log('loadPartyDues (sale.js fallback) called for:', partyName);
            if (!partyName || partyName.trim() === '') {
                console.log('Party name is empty, skipping');
                $('#partyDueInfo').addClass('hidden');
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
                    console.log('Party dues response (sale.js):', data);
                    if (data && (parseFloat(data.due_amount) > 0 || parseFloat(data.due_gold) > 0)) {
                        $('#dueAmountValue').text('₹' + parseFloat(data.due_amount).toFixed(2));
                        $('#dueGoldValue').text(parseFloat(data.due_gold).toFixed(3) + 'g');
                        $('#partyDueInfo').removeClass('hidden').show();
                        $('#paymentStatusInfo').addClass('hidden'); // Hide standalone when outstanding is shown
                        console.log('Outstanding balance displayed (sale.js)');
                    } else {
                        $('#partyDueInfo').addClass('hidden');
                        console.log('No outstanding balance (sale.js)');
                    }
                    // Update payment status visibility after loading dues
                    updatePaymentStatus();
                },
                error: function (xhr, status, error) {
                    console.error('Error loading party dues (sale.js):', error, xhr.responseText);
                    $('#partyDueInfo').addClass('hidden');
                }
            });
        };
    } else {
        // Override the existing function to add logging
        const originalLoadPartyDues = window.loadPartyDues;
        window.loadPartyDues = function(partyName) {
            console.log('loadPartyDues (override) called for:', partyName);
            originalLoadPartyDues(partyName);
        };
    }
    
    // Also trigger on party input change (when manually typed and selected)
    $('#partyNameInput').on('blur', function() {
        const partyName = $(this).val().trim();
        console.log('Party input blurred, value:', partyName);
        if (partyName && typeof loadPartyDues === 'function') {
            loadPartyDues(partyName);
        }
    });
    
    // Also trigger when party is selected from dropdown (in case selectParty doesn't call it)
    $(document).on('click', '.party-item', function() {
        setTimeout(function() {
            const partyName = $('#partyNameInput').val().trim();
            console.log('Party item clicked, party name:', partyName);
            if (partyName && typeof loadPartyDues === 'function') {
                loadPartyDues(partyName);
            }
        }, 100);
    });
});

// Override saveTransaction to use Sale print receipt
window.saveTransaction = function () {
    const formData = $('#exchangeForm').serialize();

    $.ajax({
        url: '',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function (response) {
            if (response.status === 'success') {
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
                        window.open('print_sale_receipt.php?id=' + response.transaction_id, '_blank');
                    }
                    // Refresh party balance display before reload
                    const partyName = $('#partyNameInput').val().trim();
                    if (partyName && typeof loadPartyDues === 'function') {
                        loadPartyDues(partyName);
                    }
                    window.location.reload();
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
};

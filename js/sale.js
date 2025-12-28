// Sale.php - Custom JavaScript for purity autocomplete and stock management
// This overrides/extends gold_exchange.js for sale-specific functionality

$(document).ready(function () {
    console.log('Sale.js loaded - initializing sale-specific features');

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
    $('#receiptId').on('input focus', function () {
        const term = $(this).val();
        clearTimeout(receiptSearchTimeout);

        if (term.length < 1) {
            $('#receiptSuggestions').addClass('hidden');
            return;
        }

        receiptSearchTimeout = setTimeout(function () {
            $.ajax({
                url: '',
                type: 'POST',
                data: { action: 'search_receipt_ids', term: term },
                success: function (response) {
                    try {
                        const receipts = JSON.parse(response);
                        const list = $('#receiptSuggestions');
                        list.empty();

                        if (receipts.length > 0) {
                            receipts.forEach(function (r) {
                                list.append(`
                                    <div class="px-4 py-2 hover:bg-gray-100 cursor-pointer text-sm" 
                                         data-id="${r.receipt_id}">
                                        <div class="font-bold text-gray-800">${r.receipt_id}</div>
                                        <div class="text-xs text-gray-500">${r.label.split(' - ')[1] || ''}</div>
                                    </div>
                                `);
                            });
                            list.removeClass('hidden');
                        } else {
                            list.addClass('hidden');
                        }
                    } catch (e) {
                        console.error('Error parsing receipts', e);
                    }
                }
            });
        }, 300);
    });

    // Handle suggestion selection
    $(document).on('click', '#receiptSuggestions div', function () {
        const receiptId = $(this).data('id');
        $('#receiptId').val(receiptId);
        $('#receiptSuggestions').addClass('hidden');

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
                        $('#paidAmount').val(t.payment_amount);
                        $('select[name="payment_method"]').val(t.payment_method);
                        $('input[name="narration"]').val(t.narration);

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
    $(document).click(function (e) {
        if (!$(e.target).closest('#receiptId, #receiptSuggestions').length) {
            $('#receiptSuggestions').addClass('hidden');
        }
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

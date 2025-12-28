/**
 * Generic Keyboard Navigation Module
 * Can be configured for different forms (book, sell, purchase, etc.)
 */

const KeyboardNavigationGeneric = (() => {
    let config = {
        formId: '',
        fieldOrder: [],
        skipFields: [],
        submitButtonId: '',
        formName: ''
    };

    let form;
    let fields = {};
    let submitBtn;
    let validationErrors = {};
    let dropdownStates = {};

    /**
     * Initialize keyboard navigation with configuration
     */
    function init(customConfig) {
        config = { ...config, ...customConfig };
        
        form = document.getElementById(config.formId);
        if (!form) {
            console.warn(`[KeyboardNavigation] Form with ID "${config.formId}" not found`);
            return;
        }

        // Cache all form fields
        config.fieldOrder.forEach(fieldName => {
            const field = form.querySelector(`[name="${fieldName}"], #${fieldName}`);
            if (field) {
                fields[fieldName] = field;
            }
        });

        // Find submit button
        if (config.submitButtonId) {
            submitBtn = document.getElementById(config.submitButtonId);
        }
        // If not found by ID, try to find submit button in form
        if (!submitBtn && form) {
            submitBtn = form.querySelector('button[type="submit"]');
        }
        
        // Setup keyboard handlers for each field
        Object.keys(fields).forEach(fieldName => {
            const field = fields[fieldName];
            if (!field) return;

            // Add validation error container
            addValidationContainer(field);

            // Setup keyboard event listeners
            field.addEventListener('keydown', (e) => handleKeyDown(e, fieldName));
            field.addEventListener('blur', (e) => validateField(fieldName, field));
            field.addEventListener('input', (e) => clearValidationError(fieldName));

            // Special handling for dropdowns
            if (field.tagName === 'SELECT') {
                setupDropdownNavigation(fieldName, field);
            }
        });

        // Setup button keyboard handling
        if (submitBtn) {
            submitBtn.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    submitBtn.click();
                } else if (e.key === 'Tab' && e.shiftKey) {
                    // Shift+Tab from submit button should go to last field
                    e.preventDefault();
                    const lastFieldName = config.fieldOrder[config.fieldOrder.length - 1];
                    moveToPreviousField(lastFieldName);
                }
            });
        }

        // Handle modal keyboard shortcuts (Enter = confirm, Esc = cancel)
        setupModalKeyboardHandling();

        // Prevent default form submission
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            return false;
        });
    }

    /**
     * Handle keydown events for form fields
     */
    function handleKeyDown(e, fieldName) {
        const field = fields[fieldName];
        if (!field) return;

        // Handle Enter key
        if (e.key === 'Enter' && !e.shiftKey) {
            // Special handling for textarea - allow Enter for new lines
            if (field.tagName === 'TEXTAREA') {
                return; // Allow default behavior
            }

            // For readonly fields, just move to next field
            if (field.readOnly) {
                e.preventDefault();
                moveToNextField(fieldName);
                return;
            }

            // Special handling for dropdowns
            if (field.tagName === 'SELECT') {
                if (!dropdownStates[fieldName] || !dropdownStates[fieldName].isOpen) {
                    e.preventDefault();
                    openDropdown(fieldName);
                    return;
                }
                // If dropdown is open, Enter selects and moves to next
                e.preventDefault();
                closeDropdown(fieldName);
                moveToNextField(fieldName);
                return;
            }

            // For party name with dropdown
            if (fieldName === 'partyNameInput' || fieldName === 'party_name') {
                const partyList = document.getElementById('partyList');
                if (partyList && !partyList.classList.contains('hidden')) {
                    // Dropdown is open, let party search handle it
                    return;
                }
            }

            // Validate current field before moving
            if (!validateField(fieldName, field)) {
                e.preventDefault();
                return; // Stay in current field if validation fails
            }

            e.preventDefault();
            moveToNextField(fieldName);
            return;
        }

        // Handle Shift + Enter (move to previous field)
        if (e.key === 'Enter' && e.shiftKey) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            // For SELECT fields, close dropdown if open, then move backward
            if (field.tagName === 'SELECT') {
                if (dropdownStates[fieldName]?.isOpen) {
                    closeDropdown(fieldName);
                }
                moveToPreviousField(fieldName);
                return;
            }
            
            // Special handling for textarea - move to previous field
            if (field.tagName === 'TEXTAREA') {
                moveToPreviousField(fieldName);
                return;
            }
            
            // For all other fields including readonly, move to previous field
            moveToPreviousField(fieldName);
            return;
        }

        // Handle Tab (default behavior, but ensure validation)
        if (e.key === 'Tab' && !e.shiftKey) {
            if (!validateField(fieldName, field)) {
                e.preventDefault();
                return;
            }
            // Allow default Tab behavior
            return;
        }

        // Handle Shift + Tab
        if (e.key === 'Tab' && e.shiftKey) {
            if (!validateField(fieldName, field)) {
                e.preventDefault();
                return;
            }
            // Allow default Shift+Tab behavior
            return;
        }

        // Handle Escape to close dropdowns
        if (e.key === 'Escape') {
            if (field.tagName === 'SELECT' && dropdownStates[fieldName]?.isOpen) {
                e.preventDefault();
                closeDropdown(fieldName);
                field.blur();
            }
        }
    }

    /**
     * Move to next field in focus order
     */
    function moveToNextField(currentFieldName) {
        const currentIndex = config.fieldOrder.indexOf(currentFieldName);
        if (currentIndex === -1) return;

        // Find next focusable field (skip readonly fields and skip fields)
        for (let i = currentIndex + 1; i < config.fieldOrder.length; i++) {
            const nextFieldName = config.fieldOrder[i];
            
            // Skip fields that are in the skip list
            if (config.skipFields.includes(nextFieldName)) {
                continue;
            }
            
            const nextField = fields[nextFieldName];
            
            if (nextField && !nextField.readOnly && !nextField.disabled) {
                // Skip hidden payment fields
                if (nextField.closest('.hidden') || nextField.closest('.payment-field.hidden')) {
                    continue;
                }
                
                nextField.focus();
                // Select text if it's an input (but not number or readonly)
                if (nextField.tagName === 'INPUT' && nextField.type !== 'number' && !nextField.readOnly) {
                    nextField.select();
                }
                return;
            }
        }

        // If we've reached the end, focus submit button
        if (submitBtn && !submitBtn.classList.contains('hidden')) {
            submitBtn.focus();
        }
    }

    /**
     * Move to previous field in focus order
     */
    function moveToPreviousField(currentFieldName) {
        const currentIndex = config.fieldOrder.indexOf(currentFieldName);
        if (currentIndex === -1) {
            // If not in field order (e.g., submit button), go to last field
            const lastFieldName = config.fieldOrder[config.fieldOrder.length - 1];
            const lastField = fields[lastFieldName];
            if (lastField && !lastField.disabled) {
                // Skip readonly fields that are not focusable
                if (config.skipFields.includes(lastFieldName)) {
                    // Find the last non-skipped field
                    for (let i = config.fieldOrder.length - 1; i >= 0; i--) {
                        const fieldName = config.fieldOrder[i];
                        if (!config.skipFields.includes(fieldName)) {
                            const field = fields[fieldName];
                            if (field && !field.disabled && !field.closest('.hidden') && !field.closest('.payment-field.hidden')) {
                                field.focus();
                                if (field.tagName === 'INPUT' && field.type !== 'number' && !field.readOnly) {
                                    field.select();
                                }
                                return;
                            }
                        }
                    }
                } else {
                    if (!lastField.closest('.hidden') && !lastField.closest('.payment-field.hidden')) {
                        lastField.focus();
                        if (lastField.tagName === 'INPUT' && lastField.type !== 'number' && !lastField.readOnly) {
                            lastField.select();
                        }
                    }
                }
            }
            return;
        }

        // Find previous focusable field
        for (let i = currentIndex - 1; i >= 0; i--) {
            const prevFieldName = config.fieldOrder[i];
            
            // Skip fields that are in the skip list
            if (config.skipFields.includes(prevFieldName)) {
                continue;
            }
            
            const prevField = fields[prevFieldName];
            
            // Allow readonly fields if they're focusable (like saleIdInput)
            // Only skip if they're disabled or in the skip list
            if (prevField && !prevField.disabled) {
                // Skip hidden payment fields
                if (prevField.closest('.hidden') || prevField.closest('.payment-field.hidden')) {
                    continue;
                }
                
                prevField.focus();
                // Select text if it's an input (but not number or readonly)
                if (prevField.tagName === 'INPUT' && prevField.type !== 'number' && !prevField.readOnly) {
                    prevField.select();
                }
                return;
            }
        }
        
        // If we've reached the beginning and no previous field found, stay at current field
        const currentField = fields[currentFieldName];
        if (currentField) {
            currentField.focus();
        }
    }

    /**
     * Setup dropdown keyboard navigation
     */
    function setupDropdownNavigation(fieldName, field) {
        dropdownStates[fieldName] = {
            isOpen: false,
            selectedIndex: -1
        };

        field.addEventListener('focus', () => {
            // Don't auto-open on focus, wait for Enter
        });

        field.addEventListener('keydown', (e) => {
            // Handle Shift+Enter first - always go backward
            if (e.key === 'Enter' && e.shiftKey) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                if (dropdownStates[fieldName].isOpen) {
                    closeDropdown(fieldName);
                }
                // Call the main handler's moveToPreviousField function
                moveToPreviousField(fieldName);
                return;
            }
            
            if (!dropdownStates[fieldName].isOpen) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openDropdown(fieldName);
                }
                return;
            }

            // Handle arrow keys when dropdown is open
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                navigateDropdown(fieldName, 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                navigateDropdown(fieldName, -1);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                selectDropdownOption(fieldName);
                closeDropdown(fieldName);
                moveToNextField(fieldName);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeDropdown(fieldName);
            }
        });
    }

    /**
     * Open dropdown
     */
    function openDropdown(fieldName) {
        const field = fields[fieldName];
        if (!field || field.tagName !== 'SELECT') return;

        dropdownStates[fieldName].isOpen = true;
        dropdownStates[fieldName].selectedIndex = field.selectedIndex;
    }

    /**
     * Close dropdown
     */
    function closeDropdown(fieldName) {
        dropdownStates[fieldName].isOpen = false;
        dropdownStates[fieldName].selectedIndex = -1;
    }

    /**
     * Navigate dropdown options with arrow keys
     */
    function navigateDropdown(fieldName, direction) {
        const field = fields[fieldName];
        if (!field || field.tagName !== 'SELECT') return;

        const options = Array.from(field.options);
        let currentIndex = dropdownStates[fieldName].selectedIndex;

        if (currentIndex === -1) {
            currentIndex = field.selectedIndex;
        }

        currentIndex += direction;

        if (currentIndex < 0) {
            currentIndex = options.length - 1;
        } else if (currentIndex >= options.length) {
            currentIndex = 0;
        }

        dropdownStates[fieldName].selectedIndex = currentIndex;
        field.selectedIndex = currentIndex;
        
        // Trigger change event
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Select current dropdown option
     */
    function selectDropdownOption(fieldName) {
        const field = fields[fieldName];
        if (!field || field.tagName !== 'SELECT') return;

        if (dropdownStates[fieldName].selectedIndex >= 0) {
            field.selectedIndex = dropdownStates[fieldName].selectedIndex;
        }
        
        // Trigger change event
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Validate a single field
     */
    function validateField(fieldName, field) {
        if (!field) return true;

        // Skip validation for readonly fields
        if (field.readOnly) return true;

        // Skip validation for optional fields if empty
        if (fieldName === 'narration' && !field.value.trim()) {
            clearValidationError(fieldName);
            return true;
        }

        // Skip validation for hidden fields
        if (field.closest('.hidden') || field.closest('.payment-field.hidden')) {
            return true;
        }

        let isValid = true;
        let errorMessage = '';

        // Required field validation
        if (field.hasAttribute('required') && !field.value.trim()) {
            isValid = false;
            errorMessage = `${getFieldLabel(fieldName)} is required`;
        }

        // Numeric field validation
        if (field.type === 'number' && field.value) {
            const numValue = parseFloat(field.value);
            if (isNaN(numValue) || numValue < 0) {
                isValid = false;
                errorMessage = `${getFieldLabel(fieldName)} must be a positive number`;
            }
        }

        // Party name validation (must have party_id)
        if ((fieldName === 'partyNameInput' || fieldName === 'party_name') && field.value) {
            const partyId = document.getElementById('partyId');
            if (!partyId || !partyId.value) {
                isValid = false;
                errorMessage = 'Please select a party from the list';
            }
        }

        // Payment type validation
        if (fieldName === 'payment_type' && !field.value) {
            isValid = false;
            errorMessage = 'Please select a payment type';
        }

        if (isValid) {
            clearValidationError(fieldName);
        } else {
            showValidationError(fieldName, errorMessage);
        }

        return isValid;
    }

    /**
     * Show validation error inline
     */
    function showValidationError(fieldName, message) {
        const field = fields[fieldName];
        if (!field) return;

        validationErrors[fieldName] = message;

        // Add error styling
        field.classList.add('border-red-500');
        field.classList.remove('border-gray-300');

        // Show error message
        const errorContainer = field.parentElement.querySelector('.validation-error');
        if (errorContainer) {
            errorContainer.textContent = message;
            errorContainer.classList.remove('hidden');
        }

        // Prevent scrolling
        field.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /**
     * Clear validation error
     */
    function clearValidationError(fieldName) {
        const field = fields[fieldName];
        if (!field) return;

        delete validationErrors[fieldName];

        // Remove error styling
        field.classList.remove('border-red-500');
        field.classList.add('border-gray-300');

        // Hide error message
        const errorContainer = field.parentElement.querySelector('.validation-error');
        if (errorContainer) {
            errorContainer.textContent = '';
            errorContainer.classList.add('hidden');
        }
    }

    /**
     * Add validation error container to field
     */
    function addValidationContainer(field) {
        if (!field || !field.parentElement) return;

        // Check if container already exists
        if (field.parentElement.querySelector('.validation-error')) return;

        const errorDiv = document.createElement('div');
        errorDiv.className = 'validation-error text-xs text-red-600 mt-1 hidden';
        errorDiv.setAttribute('role', 'alert');
        field.parentElement.appendChild(errorDiv);
    }

    /**
     * Get human-readable field label
     */
    function getFieldLabel(fieldName) {
        const labels = {
            'saleIdInput': 'Sale ID',
            'purchaseIdInput': 'Purchase ID',
            'bookingIdInput': 'Booking ID',
            'date_of_transaction': 'Date',
            'partyNameInput': 'Party Name',
            'party_name': 'Party Name',
            'sell_weight': 'Weight',
            'purchase_weight': 'Weight',
            'booking_weight': 'Weight',
            'purity': 'Purity',
            'rate': 'Rate',
            'rateInput': 'Rate',
            'total_amount': 'Total',
            'amount': 'Amount',
            'totalAmountInput': 'Total Amount',
            'payment_type': 'Payment Type',
            'paymentTypeSelect': 'Payment Type',
            'booking_type': 'Booking Type',
            'bookingTypeSelect': 'Booking Type',
            'narration': 'Narration',
            'cash_amount': 'Cash Amount',
            'bank_amount': 'Bank Amount',
            'additional_cash': 'Cash Received',
            'additional_bank': 'Bank Received',
            'bank_payment_type': 'Bank Payment Type'
        };
        return labels[fieldName] || fieldName;
    }

    /**
     * Setup keyboard handling for modals (SweetAlert2)
     */
    function setupModalKeyboardHandling() {
        // Intercept SweetAlert2 modal creation
        const originalSwalFire = window.Swal?.fire;
        if (window.Swal && originalSwalFire) {
            window.Swal.fire = function(...args) {
                const result = originalSwalFire.apply(this, args);
                
                // Add keyboard handlers after modal is shown
                result.then(() => {
                    setTimeout(() => {
                        const modal = document.querySelector('.swal2-popup');
                        if (!modal) return;
                        
                        // Get all focusable buttons in the modal in visual order
                        const getFocusableButtons = () => {
                            const buttons = [];
                            const confirmBtn = modal.querySelector('.swal2-confirm');
                            const denyBtn = modal.querySelector('.swal2-deny');
                            const cancelBtn = modal.querySelector('.swal2-cancel');
                            
                            // Get all buttons from DOM in their actual order
                            const actionsContainer = modal.querySelector('.swal2-actions');
                            if (actionsContainer) {
                                // Get buttons in DOM order (which respects reverseButtons)
                                const allButtons = Array.from(actionsContainer.querySelectorAll('button'));
                                allButtons.forEach(btn => {
                                    if (btn && !btn.disabled && btn.offsetParent !== null) {
                                        buttons.push(btn);
                                    }
                                });
                            } else {
                                // Fallback: add buttons in default order if container not found
                                if (cancelBtn && !cancelBtn.disabled && cancelBtn.offsetParent !== null) buttons.push(cancelBtn);
                                if (denyBtn && !denyBtn.disabled && denyBtn.offsetParent !== null) buttons.push(denyBtn);
                                if (confirmBtn && !confirmBtn.disabled && confirmBtn.offsetParent !== null) buttons.push(confirmBtn);
                            }
                            
                            return buttons;
                        };
                        
                        // Focus trap: keep focus within modal
                        const trapFocus = (e) => {
                            if (e.key !== 'Tab') return;
                            
                            const focusableButtons = getFocusableButtons();
                            if (focusableButtons.length === 0) return;
                            
                            const firstButton = focusableButtons[0];
                            const lastButton = focusableButtons[focusableButtons.length - 1];
                            const activeElement = document.activeElement;
                            
                            // If Shift+Tab on first button, move to last
                            if (e.shiftKey && activeElement === firstButton) {
                                e.preventDefault();
                                lastButton.focus();
                            }
                            // If Tab on last button, move to first
                            else if (!e.shiftKey && activeElement === lastButton) {
                                e.preventDefault();
                                firstButton.focus();
                            }
                            // If focus is outside modal buttons, trap it
                            else if (!focusableButtons.includes(activeElement)) {
                                e.preventDefault();
                                firstButton.focus();
                            }
                        };
                        
                        // Handle keyboard navigation
                        const handleModalKey = (e) => {
                            const focusableButtons = getFocusableButtons();
                            if (focusableButtons.length === 0) return;
                            
                            const activeElement = document.activeElement;
                            const currentIndex = focusableButtons.indexOf(activeElement);
                            
                            // Arrow key navigation between buttons
                            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                                e.preventDefault();
                                e.stopPropagation();
                                
                                let nextIndex;
                                if (e.key === 'ArrowRight') {
                                    nextIndex = (currentIndex + 1) % focusableButtons.length;
                                } else {
                                    nextIndex = (currentIndex - 1 + focusableButtons.length) % focusableButtons.length;
                                }
                                
                                focusableButtons[nextIndex].focus();
                                return;
                            }
                            
                            // Enter key to activate focused button
                            if (e.key === 'Enter' && !e.shiftKey) {
                                if (focusableButtons.includes(activeElement)) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    activeElement.click();
                                    return;
                                }
                                // If no button is focused, focus and click the first available button
                                const confirmBtn = modal.querySelector('.swal2-confirm');
                                const cancelBtn = modal.querySelector('.swal2-cancel');
                                const denyBtn = modal.querySelector('.swal2-deny');
                                
                                if (confirmBtn && !confirmBtn.disabled) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    confirmBtn.focus();
                                    confirmBtn.click();
                                } else if (denyBtn && !denyBtn.disabled) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    denyBtn.focus();
                                    denyBtn.click();
                                } else if (cancelBtn) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    cancelBtn.focus();
                                    cancelBtn.click();
                                }
                                return;
                            }
                            
                            // Escape key to close/cancel
                            if (e.key === 'Escape') {
                                e.preventDefault();
                                e.stopPropagation();
                                
                                const cancelBtn = modal.querySelector('.swal2-cancel');
                                if (cancelBtn) {
                                    cancelBtn.click();
                                } else {
                                    // Close modal if no cancel button
                                    window.Swal.close();
                                }
                                return;
                            }
                            
                            // Tab key handling for focus trapping
                            if (e.key === 'Tab') {
                                trapFocus(e);
                            }
                        };
                        
                        // Add event listeners
                        modal.addEventListener('keydown', handleModalKey, true);
                        
                        // Also trap focus at document level when modal is open
                        const documentKeyHandler = (e) => {
                            if (!document.querySelector('.swal2-popup')) {
                                document.removeEventListener('keydown', documentKeyHandler, true);
                                return;
                            }
                            
                            // Only handle Tab key at document level for focus trapping
                            if (e.key === 'Tab') {
                                const modal = document.querySelector('.swal2-popup');
                                if (modal) {
                                    const focusableButtons = getFocusableButtons();
                                    if (focusableButtons.length === 0) return;
                                    
                                    const activeElement = document.activeElement;
                                    
                                    // If focus is outside modal, trap it
                                    if (!modal.contains(activeElement) || !focusableButtons.includes(activeElement)) {
                                        e.preventDefault();
                                        focusableButtons[0].focus();
                                    }
                                }
                            }
                        };
                        
                        document.addEventListener('keydown', documentKeyHandler, true);
                        
                        // Focus first button by default (or confirm button if available)
                        setTimeout(() => {
                            const focusableButtons = getFocusableButtons();
                            if (focusableButtons.length > 0) {
                                // Prefer confirm button, otherwise first button
                                const confirmBtn = modal.querySelector('.swal2-confirm');
                                const buttonToFocus = (confirmBtn && !confirmBtn.disabled) ? confirmBtn : focusableButtons[0];
                                buttonToFocus.focus();
                                
                                // Ensure button is visible and accessible
                                buttonToFocus.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }
                        }, 150);
                    }, 100);
                });
                
                return result;
            };
        }
    }

    /**
     * Validate all fields before submission
     */
    function validateAllFields() {
        let allValid = true;
        
        Object.keys(fields).forEach(fieldName => {
            const field = fields[fieldName];
            if (field && !field.readOnly && !field.closest('.hidden')) {
                if (!validateField(fieldName, field)) {
                    allValid = false;
                }
            }
        });

        return allValid;
    }

    /**
     * Get first invalid field
     */
    function getFirstInvalidField() {
        for (const fieldName of config.fieldOrder) {
            const field = fields[fieldName];
            if (field && !field.readOnly && validationErrors[fieldName]) {
                return field;
            }
        }
        return null;
    }

    // Public API
    return {
        init,
        validateAllFields,
        getFirstInvalidField,
        clearValidationError,
        validateField: (fieldName) => {
            const field = fields[fieldName];
            return field ? validateField(fieldName, field) : true;
        }
    };
})();


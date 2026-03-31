// Keyboard Navigation Enhancement - Tally-style bidirectional navigation
// Enter = Next field, Backspace (on empty) = Previous field

$(document).ready(function () {
    console.log('🎹 Keyboard Navigation Loading...');

    // Add keyboard navigation to ALL form inputs using event delegation
    $('#exchangeForm').on('keydown', 'input:not([type="hidden"]), select, textarea', function (e) {
        const $currentField = $(this);

        // Skip if field is disabled or readonly
        if ($currentField.is(':disabled') || $currentField.is('[readonly]')) {
            return;
        }

        const currentValue = $currentField.val() || '';

        // ENTER key - Move to next field
        if (e.key === 'Enter' && !e.shiftKey) {
            // Prevent default form submission
            e.preventDefault();

            // Special handling for party name input (autocomplete)
            if ($currentField.attr('id') === 'partyNameInput') {
                if ($('#partyList').is(':visible') && !$('#partyList').hasClass('hidden')) {
                    return; // Let party selection handle it
                }
            }

            // Special handling for receipt ID input (autocomplete)
            if ($currentField.attr('id') === 'receiptId') {
                if ($('#receiptList').is(':visible') && !$('#receiptList').hasClass('hidden')) {
                    return; // Let receipt selection handle it
                }
            }

            // Move to next field
            moveToNextField($currentField);
            return false;
        }

        // BACKSPACE key - Move to previous field (when field is empty)
        else if (e.key === 'Backspace') {
            // Only move back if field is completely empty
            if (currentValue.trim() === '') {
                e.preventDefault();
                moveToPreviousField($currentField);
                return false;
            }
        }

        // SHIFT + TAB - Move to previous field
        else if (e.key === 'Tab' && e.shiftKey) {
            e.preventDefault();
            moveToPreviousField($currentField);
            return false;
        }

        // TAB key - Move to next field
        else if (e.key === 'Tab' && !e.shiftKey) {
            e.preventDefault();
            moveToNextField($currentField);
            return false;
        }
    });

    // Function to move to next input field
    function moveToNextField($currentField) {
        const $allFields = $('#exchangeForm').find('input:not([type="hidden"]):visible, select:visible, textarea:visible').not(':disabled, [readonly]');
        const currentIndex = $allFields.index($currentField);

        console.log('Moving forward from index:', currentIndex, 'of', $allFields.length);

        if (currentIndex < $allFields.length - 1) {
            const $nextField = $allFields.eq(currentIndex + 1);
            $nextField.focus();

            // Select text if it's a text/number input
            if ($nextField.is('input[type="text"], input[type="number"]')) {
                setTimeout(function () {
                    $nextField.select();
                }, 10);
            }
        } else {
            // If last field, focus on Save button
            $('#exchangeForm button[type="submit"]').focus();
        }
    }

    // Function to move to previous input field
    function moveToPreviousField($currentField) {
        const $allFields = $('#exchangeForm').find('input:not([type="hidden"]):visible, select:visible, textarea:visible').not(':disabled, [readonly]');
        const currentIndex = $allFields.index($currentField);

        console.log('Moving backward from index:', currentIndex, 'of', $allFields.length);

        if (currentIndex > 0) {
            const $prevField = $allFields.eq(currentIndex - 1);
            $prevField.focus();

            // Select text if it's a text/number input
            if ($prevField.is('input[type="text"], input[type="number"]')) {
                setTimeout(function () {
                    $prevField.select();
                }, 10);
            }

            // Move cursor to end for text fields
            if ($prevField.is('input[type="text"]')) {
                setTimeout(function () {
                    const fieldValue = $prevField.val();
                    $prevField.val('').val(fieldValue); // Trick to move cursor to end
                }, 10);
            }
        }
    }

    // Arrow key navigation for table cells
    $('#exchangeForm').on('keydown', '.received-item-row input', function (e) {
        const $cell = $(this);
        const $row = $cell.closest('tr');

        // Skip readonly fields for arrow navigation
        if ($cell.is('[readonly]')) {
            return;
        }

        // Arrow Right - Move to next cell in same row
        if (e.key === 'ArrowRight' && !e.shiftKey && !e.ctrlKey) {
            const $nextInput = $cell.closest('td').nextAll().find('input:not([readonly])').first();
            if ($nextInput.length > 0) {
                e.preventDefault();
                $nextInput.focus().select();
            }
        }

        // Arrow Left - Move to previous cell in same row
        else if (e.key === 'ArrowLeft' && !e.shiftKey && !e.ctrlKey) {
            const $prevInput = $cell.closest('td').prevAll().find('input:not([readonly])').last();
            if ($prevInput.length > 0) {
                e.preventDefault();
                $prevInput.focus().select();
            }
        }

        // Arrow Down - Move to same cell in next row
        else if (e.key === 'ArrowDown' && !e.shiftKey && !e.ctrlKey) {
            const cellIndex = $cell.closest('td').index();
            const $nextRow = $row.next('.received-item-row');
            if ($nextRow.length > 0) {
                e.preventDefault();
                const $nextInput = $nextRow.find('td').eq(cellIndex).find('input:not([readonly])');
                if ($nextInput.length > 0) {
                    $nextInput.focus().select();
                }
            }
        }

        // Arrow Up - Move to same cell in previous row
        else if (e.key === 'ArrowUp' && !e.shiftKey && !e.ctrlKey) {
            const cellIndex = $cell.closest('td').index();
            const $prevRow = $row.prev('.received-item-row');
            if ($prevRow.length > 0) {
                e.preventDefault();
                const $prevInput = $prevRow.find('td').eq(cellIndex).find('input:not([readonly])');
                if ($prevInput.length > 0) {
                    $prevInput.focus().select();
                }
            }
        }
    });

    // Quick keyboard shortcuts (global)
    $(document).on('keydown', function (e) {
        // Only if not typing in an input
        const $focused = $(':focus');

        // Alt + S - Save form
        if (e.altKey && e.key.toLowerCase() === 's') {
            e.preventDefault();
            $('#exchangeForm').submit();
        }

        // Alt + R - Reset form
        else if (e.altKey && e.key.toLowerCase() === 'r') {
            e.preventDefault();
            if (typeof resetForm === 'function') {
                resetForm();
            }
        }

        // Alt + N - Add new item
        else if (e.altKey && e.key.toLowerCase() === 'n') {
            e.preventDefault();
            if (typeof addReceivedItem === 'function') {
                addReceivedItem();
            }
        }

        // ESC - Clear current field
        else if (e.key === 'Escape' && $focused.is('input[type="text"], input[type="number"], textarea')) {
            $focused.val('').trigger('input');
        }
    });

    // Show keyboard shortcuts hint in console
    setTimeout(function () {
        console.log('%c🎹 Keyboard Shortcuts Active!', 'color: #22c55e; font-weight: bold; font-size: 16px;');
        console.log('%c━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'color: #6b7280;');
        console.log('%cNavigation:', 'color: #3b82f6; font-weight: bold;');
        console.log('%c  ⏎ Enter → Next field', 'color: #3b82f6;');
        console.log('%c  ⌫ Backspace (empty) → Previous field', 'color: #3b82f6;');
        console.log('%c  ⇥ Tab → Next field', 'color: #3b82f6;');
        console.log('%c  ⇧ Shift+Tab → Previous field', 'color: #3b82f6;');
        console.log('%c  ← → ↑ ↓ Arrow keys → Navigate table cells', 'color: #3b82f6;');
        console.log('%c━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'color: #6b7280;');
        console.log('%cActions:', 'color: #f59e0b; font-weight: bold;');
        console.log('%c  Alt+S → Save', 'color: #f59e0b;');
        console.log('%c  Alt+R → Reset', 'color: #f59e0b;');
        console.log('%c  Alt+N → Add new item', 'color: #f59e0b;');
        console.log('%c  ESC → Clear field', 'color: #ef4444;');
        console.log('%c━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'color: #6b7280;');
    }, 500);
});

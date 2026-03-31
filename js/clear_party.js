// Clear Party Selection Feature
$(document).ready(function () {
    // Show/hide clear button when party is selected/cleared
    $('#partyNameInput').on('input', function () {
        const value = $(this).val().trim();
        if (value.length > 0) {
            $('#clearPartyBtn').removeClass('hidden');
        } else {
            $('#clearPartyBtn').addClass('hidden');
        }
    });

    // Add clear button to party field if it doesn't exist
    if ($('#clearPartyBtn').length === 0) {
        const clearBtn = `<button type="button" id="clearPartyBtn" onclick="clearPartySelection()" class="hidden absolute inset-y-0 right-0 pr-2 flex items-center text-gray-400 hover:text-red-600 transition-colors z-10" title="Clear party">
            <i class="fas fa-times-circle text-lg"></i>
        </button>`;
        $('#partyNameInput').parent().addClass('relative').append(clearBtn);
    }
});

// Clear party selection function
function clearPartySelection() {
    // Clear party name and ID
    $('#partyNameInput').val('').removeClass('border-green-500');
    $('input[name="party_id"]').val('');

    // Hide outstanding balance
    $('#partyDueInfoInline').addClass('hidden');

    // Hide clear button
    $('#clearPartyBtn').addClass('hidden');

    // Reset selected party name
    if (typeof selectedPartyName !== 'undefined') {
        selectedPartyName = '';
    }

    // Focus on party input
    $('#partyNameInput').focus();

    // Show notification
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 1500,
        timerProgressBar: true
    });

    Toast.fire({
        icon: 'info',
        title: 'Party cleared'
    });
}

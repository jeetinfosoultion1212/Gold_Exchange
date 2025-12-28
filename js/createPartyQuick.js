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
                    confirmButtonColor: '#EAB308'
                });
            }
        },
        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to create party',
                confirmButtonColor: '#EAB308'
            });
        }
    });
}

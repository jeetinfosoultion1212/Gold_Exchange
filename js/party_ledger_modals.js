/**
 * Party Ledger — modals module.
 * Add/Edit Party, Cut Vow, and Clear Balance dialogs. Exposes window.PL.modals.
 * Depends on window.PL.render (formatters) and window.PL.core (reload/state),
 * both loaded before this file.
 */
(function () {
    'use strict';

    window.PL = window.PL || {};

    function fmt(amount) {
        return PL.render.formatIndianCurrency(amount);
    }

    function reloadCurrentLedger() {
        const partyId = PL.core && PL.core.getCurrentPartyId ? PL.core.getCurrentPartyId() : null;
        if (partyId && PL.core && PL.core.loadPartyLedger) {
            PL.core.loadPartyLedger(partyId);
        }
    }

    /* ---------------- New Party modal ---------------- */

    function updateTotalBalance() {
        const cashBalance = parseFloat($('#prevCashBalance').val()) || 0;
        const bankBalance = parseFloat($('#prevBankBalance').val()) || 0;
        $('#prevTotalBalance').text('₹' + fmt(cashBalance + bankBalance));
    }

    function showNewPartyModal() {
        $('#prevCashBalance').val('0.00');
        $('#prevBankBalance').val('0.00');
        $('#prevGoldBalance').val('0.000');
        updateTotalBalance();
        $('#newPartyModal').removeClass('hidden').addClass('flex');
        setTimeout(() => $('#newPartyName').focus(), 100);
    }

    function hideNewPartyModal() {
        $('#newPartyModal').removeClass('flex').addClass('hidden');
        $('#newPartyForm')[0].reset();
    }

    function saveNewParty() {
        const partyName = $('#newPartyName').val().trim();
        const address = $('#newPartyAddress').val().trim();
        const contactNo = $('#newPartyContact').val().trim();
        const cashBalance = parseFloat($('#prevCashBalance').val()) || 0;
        const bankBalance = parseFloat($('#prevBankBalance').val()) || 0;
        const goldBalance = parseFloat($('#prevGoldBalance').val()) || 0;

        if (!partyName) {
            Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Party name is required!' });
            $('#newPartyName').focus();
            return;
        }

        Swal.fire({
            title: 'Saving...',
            text: 'Please wait while we create the party',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        $.post('party_ledger_api.php', {
            action: 'save_party',
            party_name: partyName,
            address: address,
            contact_no: contactNo,
            cash_balance: cashBalance,
            bank_balance: bankBalance,
            current_gold_balance: goldBalance
        }, function (response) {
            Swal.close();
            if (response.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Party created successfully!',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    hideNewPartyModal();
                    if (PL.core && PL.core.selectParty) {
                        PL.core.selectParty(response.party_id);
                    }
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to create party' });
            }
        }, 'json').fail(function () {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while creating the party. Please try again.' });
        });
    }

    /* ---------------- Edit Party modal ---------------- */

    function updateEditTotalBalance() {
        const cashBalance = parseFloat($('#editCashBalance').val()) || 0;
        const bankBalance = parseFloat($('#editBankBalance').val()) || 0;
        $('#editTotalBalance').text('₹' + fmt(cashBalance + bankBalance));
    }

    function showEditPartyModal() {
        const d = window.currentLedgerData;
        if (!d || !d.party) {
            Swal.fire({ icon: 'warning', title: 'No party loaded', text: 'Open a party ledger first.' });
            return;
        }
        const p = d.party;
        const s = d.summary || {};
        const leg = parseFloat(s.current_balance != null ? s.current_balance : (parseFloat(p.cash_balance) || 0) + (parseFloat(p.bank_balance) || 0)) || 0;
        const legLbl = leg > 0 ? 'due' : leg < 0 ? 'credit' : 'clear';
        const au = parseFloat(s.gold_balance != null ? s.gold_balance : p.gold_balance) || 0;

        $('#editPartyStatStrip').html(
            `<span class="font-semibold text-gray-800">#${p.id}</span> · ` +
            `Ledger <span class="tabular-nums font-medium">₹${fmt(Math.abs(leg))}</span> <span class="text-gray-400">(${legLbl})</span> · ` +
            `Au <span class="tabular-nums font-medium">${au.toFixed(3)}g</span> · ` +
            `Booked <span class="tabular-nums">${(parseFloat(s.booked_weight) || 0).toFixed(2)}g</span> / Sold <span class="tabular-nums">${(parseFloat(s.sold_weight) || 0).toFixed(2)}g</span>`
        );
        $('#editPartyId').val(p.id);
        $('#editPartyName').val(p.party_name || '');
        $('#editPartyAddress').val(p.address || '');
        $('#editPartyContact').val(p.contact_no || '');
        $('#editPartyCity').val(p.city || '');
        $('#editPartyState').val(p.state || '');
        $('#editPartyGstin').val((p.gstin || '').toUpperCase());
        $('#editPartyBankName').val(p.bank_name || '');
        $('#editPartyIfsc').val((p.ifsc_code || '').toUpperCase());
        $('#editPartyAccountNo').val(p.account_no || '');
        $('#editCashBalance').val((parseFloat(p.cash_balance) || 0).toFixed(2));
        $('#editBankBalance').val((parseFloat(p.bank_balance) || 0).toFixed(2));
        $('#editGoldBalance').val((parseFloat(p.gold_balance) || 0).toFixed(3));
        updateEditTotalBalance();
        $('#editPartyModal').removeClass('hidden').addClass('flex');
        setTimeout(() => $('#editPartyName').focus(), 100);
    }

    function hideEditPartyModal() {
        $('#editPartyModal').removeClass('flex').addClass('hidden');
    }

    /* ---------------- Cut Vow modal ---------------- */

    function showCutVowModal(transId, weight) {
        $('#cutVowTransactionId').val(transId);
        $('#cutVowWeight').text(parseFloat(weight).toFixed(3) + 'g');
        $('#cutVowRate').val('');
        $('#cutVowTotalAmount').text('₹0.00');
        $('#cutVowModal').removeClass('hidden').addClass('flex');
        setTimeout(() => $('#cutVowRate').focus(), 100);
    }

    function hideCutVowModal() {
        $('#cutVowModal').removeClass('flex').addClass('hidden');
        $('#cutVowForm')[0].reset();
    }

    /* ---------------- Clear Balance ---------------- */

    function showClearBalanceConfirm() {
        const d = window.currentLedgerData;
        if (!d || !d.party) {
            Swal.fire({ icon: 'warning', title: 'No party loaded', text: 'Open a party ledger first.' });
            return;
        }
        const partyName = d.party.party_name || 'this party';
        Swal.fire({
            icon: 'warning',
            title: 'Clear all balances?',
            html: `This resets <b>${partyName}</b>'s cash, bank, gold and silver balances to zero. This cannot be undone.`,
            showCancelButton: true,
            confirmButtonText: 'Clear balances',
            confirmButtonColor: '#dc2626',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (!result.isConfirmed) return;

            Swal.fire({ title: 'Clearing…', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });

            $.post('party_ledger_api.php', { action: 'clear_party_balance', party_id: d.party.id }, function (response) {
                Swal.close();
                if (response.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Cleared', text: response.message, timer: 1800, showConfirmButton: false })
                        .then(() => reloadCurrentLedger());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to clear balances' });
                }
            }, 'json').fail(function () {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' });
            });
        });
    }

    /* ---------------- Wiring ---------------- */

    $(document).ready(function () {
        $('#prevCashBalance, #prevBankBalance').on('input', updateTotalBalance);
        $('#editCashBalance, #editBankBalance').on('input', updateEditTotalBalance);

        $('#addNewPartyBtn').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            showNewPartyModal();
        });
        $('#closeNewPartyModal, #cancelNewPartyBtn').on('click', hideNewPartyModal);
        $('#newPartyModal').on('click', function (e) {
            if (e.target === this) hideNewPartyModal();
        });
        $('#newPartyModal').on('keydown', function (e) {
            if (e.key === 'Escape') {
                hideNewPartyModal();
                return;
            }
            if (e.key === 'Tab') {
                const focusable = $('#newPartyModal').find('input, textarea, button, [tabindex]:not([tabindex="-1"])').filter(':visible');
                const first = focusable.first();
                const last = focusable.last();
                if (e.shiftKey) {
                    if (document.activeElement === first[0]) { e.preventDefault(); last.focus(); }
                } else if (document.activeElement === last[0]) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
        $('#newPartyForm').on('submit', function (e) {
            e.preventDefault();
            saveNewParty();
        });
        $('#saveNewPartyBtn').on('click', function (e) {
            e.preventDefault();
            saveNewParty();
        });
        $('#newPartyForm').on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                $('#saveNewPartyBtn').click();
            }
        });

        $('#openEditPartyBtn').on('click', function (e) {
            e.preventDefault();
            showEditPartyModal();
        });
        $('#closeEditPartyModal, #cancelEditPartyBtn').on('click', hideEditPartyModal);
        $('#editPartyModal').on('click', function (e) {
            if (e.target === this) hideEditPartyModal();
        });
        $('#editPartyModal').on('keydown', function (e) {
            if (e.key === 'Escape') {
                hideEditPartyModal();
                return;
            }
            if (e.key === 'Tab') {
                const focusable = $('#editPartyModal').find('input, textarea, button, [tabindex]:not([tabindex="-1"])').filter(':visible');
                const first = focusable.first();
                const last = focusable.last();
                if (e.shiftKey) {
                    if (document.activeElement === first[0]) { e.preventDefault(); last.focus(); }
                } else if (document.activeElement === last[0]) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
        $('#editPartyForm').on('submit', function (e) {
            e.preventDefault();
            const partyId = parseInt($('#editPartyId').val(), 10);
            const partyName = $('#editPartyName').val().trim();
            if (!partyName) {
                Swal.fire({ icon: 'error', title: 'Validation', text: 'Party name is required.' });
                $('#editPartyName').focus();
                return;
            }
            Swal.fire({ title: 'Saving…', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
            $.post('party_ledger_api.php', {
                action: 'update_party',
                party_id: partyId,
                party_name: partyName,
                address: $('#editPartyAddress').val().trim(),
                contact_no: $('#editPartyContact').val().trim(),
                city: $('#editPartyCity').val().trim(),
                state: $('#editPartyState').val().trim(),
                gstin: $('#editPartyGstin').val().trim(),
                bank_name: $('#editPartyBankName').val().trim(),
                account_no: $('#editPartyAccountNo').val().trim(),
                ifsc_code: $('#editPartyIfsc').val().trim(),
                cash_balance: parseFloat($('#editCashBalance').val()) || 0,
                bank_balance: parseFloat($('#editBankBalance').val()) || 0,
                current_gold_balance: parseFloat($('#editGoldBalance').val()) || 0
            }, function (response) {
                Swal.close();
                if (response.status === 'success') {
                    hideEditPartyModal();
                    Swal.fire({ icon: 'success', title: 'Saved', timer: 1500, showConfirmButton: false });
                    reloadCurrentLedger();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Update failed' });
                }
            }, 'json').fail(function () {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' });
            });
        });

        $(document).on('click', '.cut-vow-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            showCutVowModal($(this).data('trans-id'), $(this).data('weight'));
        });
        $('#closeCutVowModal, #cancelCutVowBtn').on('click', hideCutVowModal);
        $('#cutVowModal').on('click', function (e) {
            if (e.target === this) hideCutVowModal();
        });
        $('#cutVowModal').on('keydown', function (e) {
            if (e.key === 'Escape') hideCutVowModal();
        });
        $('#cutVowRate').on('input', function () {
            const rate = parseFloat($(this).val()) || 0;
            const weight = parseFloat($('#cutVowWeight').text().replace('g', '')) || 0;
            $('#cutVowTotalAmount').text('₹' + fmt(rate * weight));
        });
        $('#cutVowForm').on('submit', function (e) {
            e.preventDefault();
            const transId = $('#cutVowTransactionId').val();
            const rate = parseFloat($('#cutVowRate').val());

            if (!transId || !rate || rate <= 0) {
                Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Please enter a valid rate!' });
                return;
            }

            Swal.fire({ title: 'Processing...', text: 'Please wait while we cut the vow', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });

            $.post('party_ledger_api.php', {
                action: 'cut_vow',
                sale_transaction_id: transId,
                rate: rate
            }, function (response) {
                Swal.close();
                if (response.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false })
                        .then(() => {
                            hideCutVowModal();
                            reloadCurrentLedger();
                        });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to cut vow' });
                }
            }, 'json').fail(function () {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while cutting the vow. Please try again.' });
            });
        });

        $('#clearBalanceBtn').on('click', function (e) {
            e.preventDefault();
            showClearBalanceConfirm();
        });
    });

    window.PL.modals = {
        showNewPartyModal,
        hideNewPartyModal,
        showEditPartyModal,
        hideEditPartyModal,
        showCutVowModal,
        hideCutVowModal,
        showClearBalanceConfirm
    };
})();

/**
 * shared-party-handler.js
 * 
 * A unified handler for the "Add New Party" modal across the application.
 * This ensures consistency in UI, validation, and backend communication.
 */

const SharedPartyHandler = (() => {
    /**
     * Shows a beautiful, comprehensive modal to add a new party.
     * @param {Object} options - Configuration options
     * @param {Function} options.onSuccess - Callback function called with (response, partyData)
     * @param {string} options.apiPath - Optional custom path for the AJAX request
     */
    function showAddPartyModal(options = {}) {
        if (typeof Swal === 'undefined') {
            console.error('SweetAlert2 is required for SharedPartyHandler');
            return;
        }

        Swal.fire({
            title: '<div class="flex items-center text-slate-800 text-[11px] font-bold uppercase tracking-tight"><i class="fas fa-user-plus mr-1.5 text-blue-600"></i>Add New Party</div>',
            html: `
                <div class="text-left" style="font-family: 'Poppins', sans-serif;">
                    <div class="grid grid-cols-6 gap-x-2 gap-y-1">
                        <!-- Row 1: Party Name -->
                        <div class="col-span-6">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Party Name *</label>
                            <input type="text" id="newPartyName" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded focus:ring-1 focus:ring-blue-500 transition text-[11px] font-bold h-7" placeholder="Full name of party">
                        </div>
                        
                        <!-- Row 2: Address -->
                        <div class="col-span-6">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Address</label>
                            <input type="text" id="newPartyAddress" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded focus:ring-1 focus:ring-blue-500 transition text-[11px] font-bold h-7" placeholder="Local address">
                        </div>

                        <!-- Row 3: City & State (Increased Width) -->
                        <div class="col-span-3">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">City</label>
                            <input type="text" id="newPartyCity" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded text-[11px] font-bold h-7" placeholder="City">
                        </div>
                        <div class="col-span-3">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">State</label>
                            <select id="newPartyState" class="w-full px-1 py-1 bg-slate-50 border border-slate-200 rounded text-[11px] font-bold h-7 appearance-none">
                                <option value="">Select State</option>
                                <option value="Andhra Pradesh">Andhra Pradesh</option>
                                <option value="Arunachal Pradesh">Arunachal Pradesh</option>
                                <option value="Assam">Assam</option>
                                <option value="Bihar">Bihar</option>
                                <option value="Chhattisgarh">Chhattisgarh</option>
                                <option value="Goa">Goa</option>
                                <option value="Gujarat">Gujarat</option>
                                <option value="Haryana">Haryana</option>
                                <option value="Himachal Pradesh">Himachal Pradesh</option>
                                <option value="Jharkhand">Jharkhand</option>
                                <option value="Karnataka">Karnataka</option>
                                <option value="Kerala">Kerala</option>
                                <option value="Madhya Pradesh">Madhya Pradesh</option>
                                <option value="Maharashtra">Maharashtra</option>
                                <option value="Manipur">Manipur</option>
                                <option value="Meghalaya">Meghalaya</option>
                                <option value="Mizoram">Mizoram</option>
                                <option value="Nagaland">Nagaland</option>
                                <option value="Odisha">Odisha</option>
                                <option value="Punjab">Punjab</option>
                                <option value="Rajasthan">Rajasthan</option>
                                <option value="Sikkim">Sikkim</option>
                                <option value="Tamil Nadu">Tamil Nadu</option>
                                <option value="Telangana">Telangana</option>
                                <option value="Tripura">Tripura</option>
                                <option value="Uttar Pradesh">Uttar Pradesh</option>
                                <option value="Uttarakhand">Uttarakhand</option>
                                <option value="West Bengal">West Bengal</option>
                                <option value="Andaman and Nicobar Islands">Andaman and Nicobar Islands</option>
                                <option value="Chandigarh">Chandigarh</option>
                                <option value="Dadra and Nagar Haveli and Daman and Diu">Dadra and Nagar Haveli and Daman and Diu</option>
                                <option value="Delhi">Delhi</option>
                                <option value="Jammu and Kashmir">Jammu and Kashmir</option>
                                <option value="Ladakh">Ladakh</option>
                                <option value="Lakshadweep">Lakshadweep</option>
                                <option value="Puducherry">Puducherry</option>
                            </select>
                        </div>

                        <!-- Row 4: Contact & GSTIN -->
                        <div class="col-span-3">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Contact No</label>
                            <input type="text" id="newPartyContact" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded text-[11px] font-bold h-7" placeholder="Mobile / Phone">
                        </div>
                        <div class="col-span-3">
                            <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">GSTIN</label>
                            <input type="text" id="newPartyGSTIN" class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded text-[11px] font-bold h-7 uppercase" placeholder="GST Number">
                        </div>

                        <!-- Row 5: Initial Outstandings (Balances) - Simplified -->
                        <div class="col-span-6 border-t border-slate-100 pt-2 mt-1">
                            <label class="block text-[9px] font-black text-blue-600 uppercase tracking-widest mb-2 border-b border-blue-50 pb-1">Initial Outstandings</label>
                            
                            <div class="grid grid-cols-2 gap-3 mb-2">
                                <!-- Metal Balances -->
                                <div>
                                    <label class="block text-[8px] font-bold text-amber-600 mb-0.5 uppercase tracking-tighter">Gold Balance (g)</label>
                                    <input type="number" step="0.001" id="newPartyGoldBal" class="w-full px-2 py-1.5 bg-amber-50 border border-amber-200 rounded text-xs font-black text-amber-900 h-8" placeholder="0.000">
                                </div>
                                <div>
                                    <label class="block text-[8px] font-bold text-slate-500 mb-0.5 uppercase tracking-tighter">Silver Balance (g)</label>
                                    <input type="number" step="0.001" id="newPartySilverBal" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-200 rounded text-xs font-black text-slate-700 h-8" placeholder="0.000">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <!-- Cash Balances -->
                                <div>
                                    <label class="block text-[8px] font-bold text-green-600 mb-0.5 uppercase tracking-tighter">Cash Balance (₹)</label>
                                    <input type="number" step="0.01" id="newPartyCashBal" class="w-full px-2 py-1.5 bg-green-50 border border-green-200 rounded text-xs font-black text-green-800 h-8" placeholder="0.00">
                                </div>
                                <div>
                                    <label class="block text-[8px] font-bold text-blue-600 mb-0.5 uppercase tracking-tighter">Bank Balance (₹)</label>
                                    <input type="number" step="0.01" id="newPartyBankBal" class="w-full px-2 py-1.5 bg-blue-50 border border-blue-200 rounded text-xs font-black text-blue-800 h-8" placeholder="0.00">
                                </div>
                            </div>
                        </div>

                        <!-- Row 6: Bank Account setup -->
                        <div class="col-span-6 border-t border-slate-100 pt-1 mt-1">
                            <label class="block text-[8.5px] font-bold text-slate-400 uppercase tracking-tight mb-1">Bank Information</label>
                            <div class="grid grid-cols-3 gap-1.5">
                                <input type="text" id="newPartyBankName" class="w-full px-1.5 py-1 bg-slate-50 border border-slate-200 rounded text-[10px] font-bold h-7 uppercase tracking-tighter" placeholder="Bank Name">
                                <input type="text" id="newPartyAccountNo" class="w-full px-1.5 py-1 bg-slate-50 border border-slate-200 rounded text-[10px] font-bold h-7 uppercase tracking-tighter" placeholder="A/C No">
                                <input type="text" id="newPartyIFSC" class="w-full px-1.5 py-1 bg-slate-50 border border-slate-200 rounded text-[10px] font-bold h-7 uppercase tracking-tighter" placeholder="IFSC">
                            </div>
                        </div>
                    </div>
                </div>
            `,
            width: '380px',
            showCancelButton: true,
            confirmButtonText: 'Create',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#64748b',
            padding: '0.4rem',
            customClass: {
                title: 'p-0 mb-1',
                confirmButton: 'text-[10px] px-3 py-1 font-bold uppercase tracking-tighter mx-1',
                cancelButton: 'text-[10px] px-3 py-1 font-bold uppercase tracking-tighter mx-1'
            },
            didOpen: () => {
                // Auto-focus the first field
                setTimeout(() => {
                    if (options.prefillName) {
                        $('#newPartyName').val(options.prefillName);
                        $('#newPartyAddress').focus(); // Focus address if name is prefilled
                    } else {
                        $('#newPartyName').focus();
                    }
                }, 100);

                // Keyboard Navigation (Enter moves to next field)
                const inputs = [
                    '#newPartyName', '#newPartyAddress', '#newPartyCity', '#newPartyState', 
                    '#newPartyContact', '#newPartyGSTIN', '#newPartyCashBal', 
                    '#newPartyBankBal', '#newPartyGoldBal', '#newPartyBankName', 
                    '#newPartyAccountNo', '#newPartyIFSC'
                ];

                inputs.forEach((selector, index) => {
                    $(selector).on('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            if (index < inputs.length - 1) {
                                $(inputs[index + 1]).focus();
                            } else {
                                Swal.clickConfirm();
                            }
                        }
                    });
                });
            },
            preConfirm: () => {
                const partyData = {
                    party_name: $('#newPartyName').val().trim(),
                    address: $('#newPartyAddress').val().trim(),
                    contact_no: $('#newPartyContact').val().trim(),
                    city: $('#newPartyCity').val().trim(),
                    state: $('#newPartyState').val(),
                    gstin: $('#newPartyGSTIN').val().trim() || 'N/A',
                    cash_balance: parseFloat($('#newPartyCashBal').val()) || 0,
                    bank_balance: parseFloat($('#newPartyBankBal').val()) || 0,
                    cash_gold_balance: parseFloat($('#newPartyGoldBal').val()) || 0,
                    bank_gold_balance: 0,
                    cash_silver_balance: parseFloat($('#newPartySilverBal').val()) || 0,
                    bank_silver_balance: 0,
                    gold_balance: parseFloat($('#newPartyGoldBal').val()) || 0,
                    silver_balance: parseFloat($('#newPartySilverBal').val()) || 0,
                    bank_name: $('#newPartyBankName').val() || '',
                    account_no: $('#newPartyAccountNo').val() || '',
                    ifsc_code: $('#newPartyIFSC').val() || ''
                };

                if (!partyData.party_name) {
                    Swal.showValidationMessage('Name required!');
                    return false;
                }

                return partyData;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                saveParty(result.value, options);
            }
        });
    }

    /**
     * Sends the party data to the backend.
     */
    function saveParty(partyData, options) {
        const apiPath = options.apiPath || ''; // Default to current page
        
        $.ajax({
            url: apiPath,
            type: 'POST',
            data: {
                action: 'save_party',
                ...partyData
            },
            dataType: 'json',
            success: function (response) {
                if (response.status === 'success' || response.party_id) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Party Added!',
                        text: response.message || 'Party has been successfully created.',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    if (typeof options.onSuccess === 'function') {
                        options.onSuccess(response, partyData);
                    }
                } else {
                    Swal.fire('Error', response.message || 'Could not add party.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Server connection failed. Please try again.', 'error');
            }
        });
    }

    return {
        showAddPartyModal
    };
})();

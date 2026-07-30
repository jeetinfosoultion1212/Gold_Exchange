/**
 * Party Ledger — core module.
 * Page init, party search/select, ledger loading, tab switching, keyboard
 * shortcuts, PDF export. Delegates rendering to window.PL.render and
 * dialogs to window.PL.modals (both loaded before this file).
 */
(function () {
    'use strict';

    window.PL = window.PL || {};

    const API_URL = 'party_ledger_api.php';

    let currentPartyId = null;
    let partyListVisible = false;
    let currentIndex = -1;
    let searchTimer = null;
    window.lastSalesSubTab = window.lastSalesSubTab || 'cash';
    window.lastPurchasesSubTab = window.lastPurchasesSubTab || 'cash';

    function fmt(amount) {
        return PL.render.formatIndianCurrency(amount);
    }

    /* ---------------- Party search / list ---------------- */

    function searchParties(term) {
        $.post(API_URL, { action: 'search_parties', term: term }, function (parties) {
            renderPartiesDropdown(parties);
        }, 'json');
    }

    function renderPartiesDropdown(parties) {
        const partyList = $('#partyList');
        partyList.empty();
        currentIndex = -1;

        if (!parties.length) {
            partyList.html(`
                <div class="px-4 py-3 text-center text-sm text-gray-500">
                    <i class="fas fa-search text-gray-300 mb-2"></i>
                    <p>No parties found</p>
                    <p class="text-xs mt-1">Try a different search term or create a new party</p>
                </div>
            `).removeClass('hidden');
            partyListVisible = true;
            return;
        }

        const headerText = parties.length >= 50
            ? 'Showing first 50 parties. Type to filter.'
            : `Showing ${parties.length} ${parties.length === 1 ? 'party' : 'parties'}. Type to filter.`;

        partyList.append(`
            <div class="px-2 py-1 bg-blue-50 border-b border-blue-200 text-[11px] text-blue-700 sticky top-0 z-10 leading-tight">
                <div class="flex items-center justify-between gap-2">
                    <div class="truncate"><i class="fas fa-info-circle mr-0.5"></i>${headerText}</div>
                    <div class="flex items-center shrink-0 gap-1 text-[10px]">
                        <span class="hidden sm:inline"><kbd class="px-1 py-0 bg-white border border-blue-300 rounded font-mono">↑↓</kbd></span>
                        <span class="hidden sm:inline"><kbd class="px-1 py-0 bg-white border border-blue-300 rounded font-mono">↵</kbd></span>
                        <span><kbd class="px-1 py-0 bg-white border border-blue-300 rounded font-mono">Esc</kbd></span>
                    </div>
                </div>
            </div>
        `);

        parties.forEach((party, index) => {
            const cb = parseFloat(party.cash_balance) || 0;
            const bb = parseFloat(party.bank_balance) || 0;
            const ledgerTotal = (parseFloat(party.current_balance) || 0) || (cb + bb);
            const goldBal = parseFloat(party.gold_balance) || 0;
            const statusClass = ledgerTotal > 0 ? 'text-red-600' : ledgerTotal < 0 ? 'text-green-600' : 'text-gray-600';
            const statusBadge = ledgerTotal > 0 ? 'bg-red-100 text-red-800' : ledgerTotal < 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
            const statusText = ledgerTotal > 0 ? 'Due' : ledgerTotal < 0 ? 'Credit' : 'Clear';
            const rupeeMain = (ledgerTotal < 0 ? '−' : '') + '₹' + fmt(Math.abs(ledgerTotal));
            const goldBit = Math.abs(goldBal) > 0.0005
                ? ` · <span class="text-amber-700">${goldBal < 0 ? '−' : ''}${Math.abs(goldBal).toFixed(3)}g</span>`
                : '';
            const metaTitle = [party.contact_no, party.address].filter(Boolean).join(' · ')
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

            const partyItem = document.createElement('div');
            partyItem.className = 'px-2 py-1.5 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors duration-150 party-item group leading-tight min-w-0';
            partyItem.setAttribute('data-index', index);
            partyItem.setAttribute('data-id', party.id || '');
            partyItem.setAttribute('data-name', party.party_name || '');
            if (metaTitle) partyItem.setAttribute('title', metaTitle);

            const serialNo = index + 1;
            partyItem.innerHTML = `
                <div class="flex items-center justify-between gap-1.5 min-w-0 w-full">
                    <div class="flex items-center flex-1 min-w-0 gap-1.5">
                        <span class="w-5 shrink-0 text-right text-[10px] font-semibold text-gray-400 tabular-nums select-none" aria-hidden="true">${serialNo}</span>
                        <div class="w-7 h-7 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white text-[10px] font-semibold shadow-sm shrink-0">
                            ${(party.party_name || 'U').charAt(0).toUpperCase()}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-baseline gap-1 min-w-0">
                                <span class="text-sm font-semibold text-gray-900 truncate">${party.party_name || 'Unknown Party'}</span>
                                <span class="text-[10px] text-gray-500 font-mono shrink-0">#${party.id}</span>
                            </div>
                        </div>
                    </div>
                    <div class="text-right shrink min-w-0 max-w-[12rem]">
                        <div class="flex items-center justify-end gap-1 min-w-0">
                            <span class="text-xs font-bold ${statusClass} tabular-nums truncate">${rupeeMain}</span>
                            <span class="text-[10px] ${statusBadge} px-1 py-0 rounded font-medium shrink-0">${statusText}</span>
                        </div>
                        <div class="text-[10px] text-gray-500 mt-0.5 leading-snug tabular-nums break-words text-right">
                            C ₹${fmt(cb)} · B ₹${fmt(bb)}${goldBit}
                        </div>
                    </div>
                    <div class="flex items-center shrink-0 text-gray-400 pl-0.5">
                        <i class="fas fa-chevron-right text-[10px]" aria-hidden="true"></i>
                    </div>
                </div>
            `;

            partyItem.addEventListener('click', (e) => {
                e.stopPropagation();
                const partyId = partyItem.getAttribute('data-id');
                if (partyId) selectParty(partyId);
            });

            partyList[0].appendChild(partyItem);
        });

        partyList.removeClass('hidden');
        partyListVisible = true;
    }

    function updatePartyHighlight() {
        const partyItems = document.querySelectorAll('#partyList .party-item');
        partyItems.forEach((item, index) => {
            if (index === currentIndex && currentIndex >= 0) {
                item.classList.add('bg-blue-100', 'border-l-4', 'border-blue-500', 'ring-2', 'ring-blue-300');
                item.classList.remove('hover:bg-blue-50');
            } else {
                item.classList.remove('bg-blue-100', 'border-l-4', 'border-blue-500', 'ring-2', 'ring-blue-300');
                item.classList.add('hover:bg-blue-50');
            }
        });
        if (currentIndex >= 0 && currentIndex < partyItems.length) {
            partyItems[currentIndex].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function selectParty(partyId) {
        $('#partyList').addClass('hidden');
        partyListVisible = false;
        currentIndex = -1;
        $('#partySearchInput').val('');
        loadPartyLedger(parseInt(partyId, 10));
    }

    /* ---------------- Ledger load / display ---------------- */

    function loadPartyLedger(partyId) {
        currentPartyId = partyId;

        $.post(API_URL, { action: 'get_party_ledger', party_id: partyId }, function (response) {
            if (response.status === 'success') {
                showPartyLedger(response);
            } else {
                Swal.fire({ icon: 'error', title: 'Error loading party ledger', text: response.message || 'Unknown error' });
            }
        }, 'json').fail(function (xhr, status, error) {
            Swal.fire({ icon: 'error', title: 'Error loading party ledger', text: error || 'Request failed' });
        });
    }

    function showPartyLedger(data) {
        const party = data.party;
        const summary = data.summary;

        $('#partyName').text(party.party_name);
        $('#partyContact').text(party.contact_no || 'No contact');
        $('#partyAddress').text(party.address || 'No address');
        $('#partyId').text(party.id);

        const currentBalance = summary.current_balance || 0;
        const statusClass = currentBalance > 0 ? 'status-due' : 'status-clear';
        const statusText = currentBalance > 0 ? 'Amount Due' : currentBalance < 0 ? 'Credit Balance' : 'Account Clear';
        $('#accountStatus').removeClass('status-due status-clear').addClass(statusClass).text(statusText);

        PL.render.renderSummaryCards(summary);
        PL.render.renderTransactionTables(data);

        $('#partyListContainer').addClass('hidden');
        $('#ledgerContainer').removeClass('hidden');

        window.currentLedgerData = data;
    }

    /* ---------------- Tabs ---------------- */

    function switchSalesSubTab(sub) {
        sub = sub === 'bank' ? 'bank' : 'cash';
        window.lastSalesSubTab = sub;
        $('.sales-subtab-btn').removeClass('bg-blue-600 text-white').addClass('bg-white text-blue-600 hover:bg-blue-50');
        $(`.sales-subtab-btn[data-sales-sub="${sub}"]`).removeClass('bg-white text-blue-600 hover:bg-blue-50').addClass('bg-blue-600 text-white');
        $('#salesCashPanel').toggleClass('hidden', sub !== 'cash');
        $('#salesBankPanel').toggleClass('hidden', sub !== 'bank');
    }

    function switchPurchasesSubTab(sub) {
        sub = sub === 'bank' ? 'bank' : 'cash';
        window.lastPurchasesSubTab = sub;
        $('.purchases-subtab-btn').removeClass('bg-blue-600 text-white').addClass('bg-white text-blue-600 hover:bg-blue-50');
        $(`.purchases-subtab-btn[data-purchases-sub="${sub}"]`).removeClass('bg-white text-blue-600 hover:bg-blue-50').addClass('bg-blue-600 text-white');
        $('#purchasesCashPanel').toggleClass('hidden', sub !== 'cash');
        $('#purchasesBankPanel').toggleClass('hidden', sub !== 'bank');
    }

    const TAB_PANEL_MAP = {
        'all': '#allTransactionsTable',
        'bookings': '#bookingsTable',
        'sales': '#salesTable',
        'purchases': '#purchasesTable',
        'gold-exchange': '#goldExchangeTable',
        'payments': '#paymentsTable',
        'gold-received': '#goldReceivedTable'
    };
    const TAB_ORDER = ['all', 'bookings', 'sales', 'purchases', 'gold-exchange', 'payments', 'gold-received'];

    function switchTab(tab) {
        $('.tab-btn').removeClass('active');
        $(`.tab-btn[data-tab="${tab}"]`).addClass('active');

        $.each(TAB_PANEL_MAP, function (_, sel) {
            $(sel).addClass('hidden');
        });
        const sel = TAB_PANEL_MAP[tab] || ('#' + tab + 'Table');
        $(sel).removeClass('hidden');

        if (tab === 'sales') {
            switchSalesSubTab(window.lastSalesSubTab);
        } else if (tab === 'purchases') {
            switchPurchasesSubTab(window.lastPurchasesSubTab);
        }
    }

    /* ---------------- PDF export ---------------- */

    function exportToPDF(data) {
        if (!data || !data.party || !data.party.id) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'No party data available to export' });
            return;
        }

        Swal.fire({
            title: 'Generating PDF...',
            text: 'Please wait while we prepare your document',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        const partyId = data.party.id;
        const pdfUrl = `export_party_ledger_pdf.php?party_id=${partyId}`;

        fetch(pdfUrl)
            .then((response) => {
                if (!response.ok) throw new Error('PDF generation failed');
                return response.blob();
            })
            .then((blob) => {
                const url = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = `Ledger_${data.party.party_name.replace(/[^A-Za-z0-9_]/g, '_')}_${partyId}_${new Date().toISOString().split('T')[0]}.pdf`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);

                Swal.close();
                Swal.fire({ icon: 'success', title: 'PDF Exported!', text: 'Your ledger report has been downloaded successfully.', timer: 2000, showConfirmButton: false });
            })
            .catch((error) => {
                console.error('PDF export error:', error);
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Export Failed',
                    html: 'Failed to generate PDF. Please check:<br><br>1. TCPDF library is installed<br>2. Server has write permissions<br>3. Party data is valid',
                    confirmButtonText: 'OK'
                });
            });
    }

    /* ---------------- Init ---------------- */

    $(document).ready(function () {
        const urlParams = new URLSearchParams(window.location.search);
        const partyIdFromUrl = urlParams.get('party_id');
        if (partyIdFromUrl) {
            loadPartyLedger(parseInt(partyIdFromUrl, 10));
        }

        $('#partySearchInput').on('focus', function () {
            const currentValue = $(this).val().trim();
            if (!partyListVisible || $('#partyList').hasClass('hidden') || $('#partyList .party-item').length === 0) {
                searchParties(currentValue || '');
            }
        });

        $('#partySearchInput').on('input', function () {
            const term = $(this).val().trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => searchParties(term), 150);
        });

        setTimeout(function () {
            if ($('#partyListContainer').is(':visible') && !$('#ledgerContainer').is(':visible')) {
                $('#partySearchInput').focus();
                searchParties('');
            }
        }, 100);

        $(document).on('keydown', function (e) {
            if ($('#partyListContainer').is(':visible') && !$('#ledgerContainer').is(':visible')) {
                if (e.altKey && e.key.toLowerCase() === 'a' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    PL.modals.showNewPartyModal();
                }
            }
        });

        $('#partySearchInput').on('keydown', function (e) {
            const partyItems = document.querySelectorAll('#partyList .party-item');
            if (!partyListVisible || !partyItems.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                e.stopPropagation();
                currentIndex = currentIndex < 0 ? 0 : Math.min(currentIndex + 1, partyItems.length - 1);
                updatePartyHighlight();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                e.stopPropagation();
                currentIndex = currentIndex <= 0 ? -1 : Math.max(currentIndex - 1, 0);
                updatePartyHighlight();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
                if (currentIndex < 0 && partyItems.length > 0) {
                    currentIndex = 0;
                    updatePartyHighlight();
                }
                if (currentIndex >= 0 && currentIndex < partyItems.length) {
                    const partyId = partyItems[currentIndex].getAttribute('data-id');
                    if (partyId) selectParty(partyId);
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                if (partyListVisible) {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    currentIndex = -1;
                } else {
                    searchParties($('#partySearchInput').val().trim() || '');
                }
            }
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('#partySearchInput, #partyList').length && partyListVisible) {
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
            }
        });

        $(document).on('click', '.tab-btn', function () {
            switchTab($(this).data('tab'));
        });
        $(document).on('click', '.sales-subtab-btn', function () {
            switchSalesSubTab($(this).data('sales-sub'));
        });
        $(document).on('click', '.purchases-subtab-btn', function () {
            switchPurchasesSubTab($(this).data('purchases-sub'));
        });

        $('#exportPdfBtn').on('click', function () {
            if (!window.currentLedgerData) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'No ledger data available to export' });
                return;
            }
            exportToPDF(window.currentLedgerData);
        });

        $(document).on('keydown', function (e) {
            if ($('#ledgerContainer').is(':visible')) {
                if (e.ctrlKey || e.metaKey) {
                    const idx = parseInt(e.key, 10);
                    if (idx >= 1 && idx <= TAB_ORDER.length) {
                        e.preventDefault();
                        switchTab(TAB_ORDER[idx - 1]);
                    }
                }

                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    e.preventDefault();
                    const currentTab = $('.tab-btn.active').data('tab');
                    const idx = TAB_ORDER.indexOf(currentTab);
                    let newIndex;
                    if (e.key === 'ArrowLeft') {
                        newIndex = idx > 0 ? idx - 1 : TAB_ORDER.length - 1;
                    } else {
                        newIndex = idx < TAB_ORDER.length - 1 ? idx + 1 : 0;
                    }
                    switchTab(TAB_ORDER[newIndex]);
                }
            }

            if (e.key === '/' && e.target.tagName !== 'INPUT' && !$('#ledgerContainer').is(':visible')) {
                e.preventDefault();
                $('#partySearchInput').focus();
                if (!partyListVisible || $('#partyList').hasClass('hidden')) {
                    searchParties($('#partySearchInput').val().trim() || '');
                }
            }

            if (e.key === 'Escape' && $('#ledgerContainer').is(':visible')) {
                $('#ledgerContainer').addClass('hidden');
                $('#partyListContainer').removeClass('hidden');
                currentPartyId = null;
                $('#partySearchInput').val('');
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
                $('#partySearchInput').focus();
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'p' && $('#ledgerContainer').is(':visible')) {
                e.preventDefault();
                $('#exportPdfBtn').click();
            }
        });
    });

    window.PL.core = {
        loadPartyLedger,
        selectParty,
        searchParties,
        switchTab,
        getCurrentPartyId: () => currentPartyId
    };
})();

(function () {
    'use strict';

    const cfg = window.LM_CONFIG || { nextReceiptId: '', categories: [], jewelleryItems: [], purityOptions: [], products: [] };
    const PURITY_OPTIONS = (cfg.purityOptions && cfg.purityOptions.length)
        ? cfg.purityOptions.slice()
        : ['22K', '20K', '18K', '14K', '9K', '999', '925', '875'];
    let categories = cfg.categories.slice();
    const jewelleryItems = (cfg.jewelleryItems || []).slice();
    let products = [];
    let categoryRates = []; // effective rates for selected jeweller (custom || default)
    let itemRowIndex = 0;
    let partyListVisible = false;
    let receiptListVisible = false;
    let lmSaving = false;
    let lmSaveLocked = false;
    let lmPartyIndex = -1;
    let lmReceiptIndex = -1;
    let lmMoveNextField = null;
    let lmFocusFieldFn = null;
    const LM_SAVE_BTN_HTML = '<i class="fas fa-save mr-1"></i> Save';

    function unlockSaveUi($saveBtn, saveBtnHtml) {
        lmSaveLocked = false;
        lmSaving = false;
        const $btn = $saveBtn && $saveBtn.length ? $saveBtn : $('#lmSaveBtn');
        $('#lmForm').find('input, select, textarea, button').prop('disabled', false);
        $btn.prop('disabled', false).html(saveBtnHtml || LM_SAVE_BTN_HTML);
    }

    function nowLocalDatetime() {
        const d = new Date();
        d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
        return d.toISOString().slice(0, 16);
    }

    function formatInr(n) {
        return '₹' + Math.round(parseFloat(n) || 0).toLocaleString('en-IN');
    }

    function formatListDateParts(value) {
        const s = String(value || '').trim();
        if (!s) return { date: '', time: '' };
        const d = new Date(s.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return { date: '', time: '' };
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const time = d.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZone: 'Asia/Kolkata'
        });
        return {
            date: String(d.getDate()).padStart(2, '0') + ' ' + months[d.getMonth()],
            time: time
        };
    }

    function buildListIdCell(receiptId, requestDate) {
        const dt = formatListDateParts(requestDate);
        const idLine = esc(receiptId);
        const when = dt.date && dt.time
            ? esc(dt.date) + ' · ' + esc(dt.time)
            : '—';
        return '<div class="text-[10px] font-bold text-indigo-700 truncate leading-tight">#' + idLine + '</div>' +
            '<div class="text-[8px] font-semibold text-slate-400 leading-tight tabular-nums whitespace-nowrap">' + when + '</div>';
    }

    function paymentStatusLabel(status, total, paid) {
        const s = String(status || '').trim();
        if (s) return s;
        const t = parseFloat(total) || 0;
        const p = parseFloat(paid) || 0;
        if (t <= 0) return 'Pending';
        if (p >= t - 0.009) return 'Paid';
        if (p > 0) return 'Partial';
        return 'Due';
    }

    function buildListStatusCell(status, paid) {
        const s = String(status || '').toLowerCase();
        let statusCls = 'text-red-600 font-bold';
        if (s === 'paid') statusCls = 'text-emerald-700 font-bold';
        else if (s === 'partial') statusCls = 'text-amber-700 font-bold';

        const statusLine = esc(status);
        const paidLine = formatInr(paid);
        const paidClass = (parseFloat(paid) || 0) > 0
            ? 'text-emerald-700'
            : 'text-slate-400';
        return '<div class="text-[10px] leading-tight whitespace-nowrap ' + statusCls + '">' + statusLine + '</div>' +
            '<div class="text-[8px] font-semibold ' + paidClass + ' leading-tight tabular-nums whitespace-nowrap">' + paidLine + '</div>';
    }

    function updateDashboardStats(stats) {
        if (!stats) return;
        $('#lmStatTotal').text(formatInr(stats.today_total));
        $('#lmStatReceived').text(formatInr(stats.today_received));
        $('#lmStatCash').text(formatInr(stats.cash_in_hand));
        $('#lmStatBank').text(formatInr(stats.bank_balance));
    }

    function loadDashboardStats() {
        $.post('', { action: 'get_dashboard_stats', date: $('#lmStartDate').val() || '' }, function (res) {
            if (res.status === 'success') updateDashboardStats(res.data);
        }, 'json');
    }

    function finishSaveAndNewForm(nextReceiptId) {
        if (nextReceiptId) {
            cfg.nextReceiptId = nextReceiptId;
        }
        unlockSaveUi();
        resetForm();
        setTimeout(function () {
            $('#lmJewellerName').trigger('focus');
        }, 80);
    }

    function calcLineTotal(pieces, weight, rate, rateBasis) {
        const p = parseFloat(pieces) || 0;
        const w = parseFloat(weight) || 0;
        const r = parseFloat(rate) || 0;
        if (rateBasis === 'per_gram') {
            return Math.round(w * r * 100) / 100;
        }
        return Math.round(p * r * 100) / 100;
    }

    function esc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function toPartyNameUpper(val) {
        return String(val || '').trim().toUpperCase();
    }

    function categoryOptionsHtml(selected) {
        if (!categories.length) {
            return '<option value="">— Add category —</option><option value="__add_cat__">+ Add category...</option>';
        }
        const pick = selected || categories[0].category_name;
        let html = '';
        categories.forEach(function (c) {
            const sel = c.category_name === pick ? ' selected' : '';
            html += '<option value="' + esc(c.category_name) + '" data-rate="' + c.default_rate + '" data-basis="' + c.rate_basis + '"' + sel + '>' + esc(c.category_name) + '</option>';
        });
        html += '<option value="__add_cat__">+ Add category...</option>';
        return html;
    }

    const DEFAULT_ITEM_NAME = 'Gold Ornament';

    function defaultCategoryName() {
        return categories.length ? categories[0].category_name : '';
    }

    function normalizePurity(val) {
        const s = String(val ?? '').trim();
        if (!s) return '';
        if (PURITY_OPTIONS.indexOf(s) >= 0) return s;
        const n = parseFloat(s);
        if (!isNaN(n)) {
            const k = Math.round(n) + 'K';
            if (PURITY_OPTIONS.indexOf(k) >= 0) return k;
            const plain = String(Math.round(n));
            if (PURITY_OPTIONS.indexOf(plain) >= 0) return plain;
        }
        return s;
    }

    function purityOptionsHtml(selected) {
        const pick = normalizePurity(selected);
        let html = '<option value="">Select</option>';
        PURITY_OPTIONS.forEach(function (p) {
            const sel = p === pick ? ' selected' : '';
            html += '<option value="' + esc(p) + '"' + sel + '>' + esc(p) + '</option>';
        });
        return html;
    }

    function allItemNames() {
        const seen = new Map();
        const add = function (name) {
            const n = String(name || '').trim();
            if (!n) return;
            const key = n.toLowerCase();
            if (!seen.has(key)) seen.set(key, n);
        };
        jewelleryItems.forEach(add);
        products.forEach(function (p) {
            const pname = String(p.product_name || '').trim();
            const cname = String(p.category_name || '').trim();
            // Skip category-rate marker rows (product_name == category_name)
            if (cname && pname.toLowerCase() === cname.toLowerCase()) return;
            add(pname);
        });
        return Array.from(seen.values());
    }

    function productOptionsHtml(selectedName) {
        const sel = selectedName || DEFAULT_ITEM_NAME;
        const names = allItemNames();
        if (!names.some(function (n) { return n.toLowerCase() === sel.toLowerCase(); })) {
            names.unshift(sel);
        }
        let html = '';
        names.forEach(function (name) {
            const picked = name.toLowerCase() === sel.toLowerCase();
            html += '<option value="' + esc(name) + '"' + (picked ? ' selected' : '') + '>' + esc(name) + '</option>';
        });
        return html;
    }

    function openLogoMarkingReceiptPrint(requestId) {
        if (!requestId) return null;
        const url = 'print_logo_marking_receipt.php?id=' + encodeURIComponent(requestId);
        if (window.GePrint && typeof window.GePrint.printReceipt === 'function') {
            return window.GePrint.printReceipt(url);
        }
        const width = Math.min(1100, window.screen.availWidth - 20);
        const height = Math.min(820, window.screen.availHeight - 40);
        const left = window.screenX + Math.max(0, (window.outerWidth - width) / 2);
        const top = window.screenY + 20;
        const features = [
            'popup=yes', 'width=' + width, 'height=' + height,
            'left=' + Math.round(left), 'top=' + Math.round(top),
            'scrollbars=yes', 'resizable=yes', 'toolbar=no', 'menubar=no', 'location=no', 'status=no'
        ].join(',');
        const win = window.open(url, 'logoMarkingPrint_' + requestId, features);
        if (!win) {
            lmAlert('warning', 'Popup Blocked', 'Allow popups or open: ' + url);
            return null;
        }
        win.focus();
        return win;
    }

    const LM_SWAL_BASE = {
        width: '300px',
        padding: '0.55rem 0.65rem 0.5rem',
        buttonsStyling: true,
        customClass: {
            popup: 'lm-swal-popup',
            title: 'p-0',
            htmlContainer: 'p-0 m-0',
            actions: 'mt-1',
            confirmButton: 'lm-swal-btn lm-swal-btn-primary',
            cancelButton: 'lm-swal-btn lm-swal-btn-cancel',
            validationMessage: 'text-[10px]'
        }
    };

    function lmModalTitle(icon, text) {
        return '<div class="flex items-center text-slate-800 text-[11px] font-bold uppercase tracking-tight" style="font-family:Poppins,sans-serif">' +
            '<i class="fas fa-' + icon + ' mr-1.5 text-indigo-600 text-[10px]"></i>' + esc(text) + '</div>';
    }

    function lmToast(icon, title, timer) {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: timer || 1400,
            timerProgressBar: true,
            width: 'auto',
            padding: '0.45rem 0.65rem',
            customClass: {
                popup: 'lm-swal-popup',
                title: 'text-[11px] font-semibold p-0 m-0',
                icon: 'swal2-icon-sm'
            }
        });
        return Toast.fire({ icon: icon, title: title });
    }

    function lmAlert(icon, title, text) {
        return Swal.fire(Object.assign({}, LM_SWAL_BASE, {
            icon: icon,
            title: lmModalTitle(icon === 'warning' ? 'exclamation-triangle' : icon === 'error' ? 'times-circle' : 'info-circle', title),
            html: text ? '<p class="text-[10px] text-slate-600 leading-snug m-0">' + esc(text) + '</p>' : undefined,
            confirmButtonText: 'OK',
            showCancelButton: false
        }));
    }

    function lmConfirm(title, text, confirmText, confirmColor) {
        return Swal.fire(Object.assign({}, LM_SWAL_BASE, {
            title: lmModalTitle('question-circle', title),
            html: text ? '<p class="text-[10px] text-slate-600 leading-snug m-0">' + esc(text) + '</p>' : undefined,
            showCancelButton: true,
            confirmButtonText: confirmText || 'Confirm',
            cancelButtonText: 'Cancel',
            confirmButtonColor: confirmColor || '#4f46e5'
        }));
    }

    function updateGrandTotal() {
        let total = 0;
        let totalPcs = 0;
        let totalWt = 0;
        $('#lmItemsBody tr').each(function () {
            total += parseFloat($(this).find('.lm-amount').val()) || 0;
            totalPcs += parseInt($(this).find('.lm-pieces').val(), 10) || 0;
            totalWt += parseFloat($(this).find('.lm-weight').val()) || 0;
        });
        const txt = total ? total.toFixed(2) : '0';
        $('#lmGrandTotalInput').val(txt);
        $('#lmGrandTotalReadonly').val(txt);
        $('#lmTotalPcs').text(totalPcs || '0');
        $('#lmTotalWeight').text(totalWt ? totalWt.toFixed(3) : '0');
        $('#lmTotalAmount').text(txt);
        updateBalanceDisplay();
    }

    function updateBalanceDisplay() {
        const total = parseFloat($('#lmGrandTotalInput').val()) || 0;
        const received = parseFloat($('#lmReceivedAmount').val()) || 0;
        const bal = total - received;
        $('#lmBalanceDisplay').text(formatInr(bal));
    }

    function recalcRow($row, force) {
        const pieces = $row.find('.lm-pieces').val();
        const weight = $row.find('.lm-weight').val();
        const rate = $row.find('.lm-rate').val();
        const basis = $row.find('.lm-category').find(':selected').data('basis') || $row.data('rateBasis') || 'per_piece';
        const total = calcLineTotal(pieces, weight, rate, basis);
        $row.find('.lm-amount').val(total ? total.toFixed(2) : '');
        updateGrandTotal();
    }

    function resolveCategoryRate(categoryName) {
        const name = String(categoryName || '').trim();
        const cat = categories.find(function (c) { return c.category_name === name; });
        const basis = (cat && cat.rate_basis) || 'per_piece';
        const defaultRate = cat ? (parseFloat(cat.default_rate) || 0) : 0;

        if (!name) {
            return { rate: defaultRate, basis: basis, source: 'default' };
        }

        const rateRow = categoryRates.find(function (r) {
            return r.category_name === name || (cat && Number(r.id) === Number(cat.id));
        });
        if (rateRow && rateRow.custom_rate != null && rateRow.custom_rate !== '') {
            return {
                rate: parseFloat(rateRow.custom_rate) || 0,
                basis: rateRow.rate_basis || basis,
                source: 'custom'
            };
        }

        const prod = products.find(function (p) {
            const cid = Number(p.category_id || 0);
            const sameCat = cat && cid === Number(cat.id);
            const sameName = String(p.product_name || '').toLowerCase() === name.toLowerCase();
            return sameCat && sameName;
        }) || products.find(function (p) {
            return String(p.product_name || '').toLowerCase() === name.toLowerCase();
        });
        if (prod && prod.rate != null && prod.rate !== '') {
            return {
                rate: parseFloat(prod.rate) || 0,
                basis: prod.rate_basis || basis,
                source: 'custom'
            };
        }

        return { rate: defaultRate, basis: basis, source: 'default' };
    }

    function applyCategoryDefaults($row, forceRate) {
        const catName = $row.find('.lm-category').val() || '';
        const resolved = resolveCategoryRate(catName);
        $row.data('rateBasis', resolved.basis);
        const $rate = $row.find('.lm-rate');
        if (forceRate || !$rate.val() || parseFloat($rate.val()) === 0) {
            $rate.val(resolved.rate ? Number(resolved.rate).toFixed(2) : '');
        }
    }

    function applyPartyRatesToRows() {
        $('#lmItemsBody tr').each(function () {
            applyCategoryDefaults($(this), true);
            recalcRow($(this));
        });
    }

    function addItemRow(data, focusField) {
        itemRowIndex += 1;
        const idx = itemRowIndex;
        data = data || {};
        const catDefault = data.item_category || defaultCategoryName();
        const nameDefault = data.item_name || DEFAULT_ITEM_NAME;
        const $row = $('<tr class="lm-item-row" data-row="' + idx + '"></tr>');
        $row.html(
            '<td class="lm-col-num text-[10px]">' + idx + '</td>' +
            '<td class="lm-col-cat"><select class="lm-field lm-category">' + categoryOptionsHtml(catDefault) + '</select></td>' +
            '<td class="lm-col-name"><select class="lm-field lm-item-name">' + productOptionsHtml(nameDefault) + '</select></td>' +
            '<td class="lm-col-pcs"><input type="text" inputmode="numeric" class="lm-field lm-num-field lm-pieces" placeholder="" value="' + (data.pieces != null && data.pieces !== '' ? data.pieces : '') + '"></td>' +
            '<td class="lm-col-wt"><input type="text" inputmode="decimal" class="lm-field lm-num-field lm-weight" placeholder="0" value="' + (data.weight ?? '') + '"></td>' +
            '<td class="lm-col-pur"><select class="lm-field lm-purity">' + purityOptionsHtml(data.purity) + '</select></td>' +
            '<td class="lm-col-rate"><input type="text" inputmode="decimal" class="lm-field lm-num-field lm-rate" placeholder="0" value="' + (data.rate_per_piece ?? '') + '"></td>' +
            '<td class="lm-col-amt"><input type="text" class="lm-field lm-amount" readonly tabindex="-1" value="' + (data.total_amount ?? '') + '"></td>' +
            '<td class="lm-col-del"><button type="button" class="lm-remove-row text-red-500 hover:text-red-700" tabindex="-1"><i class="fas fa-times"></i></button></td>'
        );
        $('#lmItemsBody').append($row);

        if (data.total_amount != null && data.total_amount !== '') {
            $row.find('.lm-amount').val(parseFloat(data.total_amount).toFixed(2));
        }

        applyCategoryDefaults($row, data.rate_per_piece == null || data.rate_per_piece === '');
        syncProductMetaToRow($row, false);
        if (!data.total_amount) recalcRow($row);

        if (focusField) {
            setTimeout(function () { $row.find(focusField).focus().select(); }, 30);
        }
    }

    function syncProductMetaToRow($row, forceRate) {
        const name = $row.find('.lm-item-name').val();
        const prod = products.find(function (p) {
            return String(p.product_name || '').toLowerCase() === String(name || '').toLowerCase();
        });
        if (!prod) return;
        // Skip category-rate rows (product_name == category_name)
        if (prod.category_name && String(prod.product_name).toLowerCase() === String(prod.category_name).toLowerCase()) {
            return;
        }
        if (prod.category_name) {
            $row.find('.lm-category').val(prod.category_name);
        }
        if (prod.rate_basis) $row.data('rateBasis', prod.rate_basis);
        if (forceRate || !$row.find('.lm-rate').val() || parseFloat($row.find('.lm-rate').val()) === 0) {
            if (prod.rate != null && prod.rate !== '') {
                $row.find('.lm-rate').val(Number(prod.rate).toFixed(2));
            } else {
                applyCategoryDefaults($row, true);
            }
        }
    }

    function renumberRows() {
        $('#lmItemsBody tr').each(function (i) {
            $(this).find('td:first').text(i + 1);
        });
    }

    function collectItems() {
        const items = [];
        $('#lmItemsBody tr').each(function () {
            const $r = $(this);
            const name = $r.find('.lm-item-name').val();
            if (!name) return;
            items.push({
                item_name: name,
                item_category: $r.find('.lm-category').val() || '',
                pieces: parseInt($r.find('.lm-pieces').val(), 10) || 0,
                weight: parseFloat($r.find('.lm-weight').val()) || 0,
                purity: ($r.find('.lm-purity').val() || '').trim(),
                rate_per_piece: parseFloat($r.find('.lm-rate').val()) || 0,
                total_amount: parseFloat($r.find('.lm-amount').val()) || 0,
                status: 'Pending'
            });
        });
        return items;
    }

    function loadProducts(jewellerId, afterLoad) {
        if (!jewellerId) {
            products = [];
            categoryRates = categories.map(function (c) {
                return {
                    id: c.id,
                    category_name: c.category_name,
                    default_rate: c.default_rate,
                    rate_basis: c.rate_basis,
                    custom_rate: null,
                    effective_rate: c.default_rate
                };
            });
            refreshItemDropdowns();
            if (typeof afterLoad === 'function') afterLoad();
            return;
        }
        $.post('', { action: 'get_products', jeweller_id: jewellerId }, function (res) {
            if (res.status === 'success') {
                products = res.data || [];
                categoryRates = res.category_rates || [];
                refreshItemDropdowns();
                applyPartyRatesToRows();
            }
            if (typeof afterLoad === 'function') afterLoad();
        }, 'json');
    }

    function refreshItemDropdowns() {
        $('#lmItemsBody .lm-item-name').each(function () {
            const cur = $(this).val() || DEFAULT_ITEM_NAME;
            $(this).html(productOptionsHtml(cur));
            $(this).val(cur);
        });
    }

    function categoryRatesModalHtml() {
        if (!categories.length) {
            return '<div class="text-[10px] text-amber-700 bg-amber-50 border border-amber-100 rounded px-2 py-1.5">' +
                'No categories yet. Add a category first to set party rates.</div>';
        }
        let rows = '';
        categories.forEach(function (c, i) {
            const rate = c.default_rate != null ? Number(c.default_rate).toFixed(2) : '0.00';
            const basis = c.rate_basis === 'per_gram' ? '/g' : '/pc';
            rows +=
                '<div class="lm-party-rate-row grid grid-cols-12 gap-1.5 items-center py-1 border-b border-slate-100">' +
                '<div class="col-span-6 min-w-0">' +
                '<div class="text-[10px] font-bold text-slate-700 truncate">' + esc(c.category_name) + '</div>' +
                '<div class="text-[8px] text-slate-400">Default ₹' + esc(rate) + ' ' + basis + '</div>' +
                '</div>' +
                '<div class="col-span-6">' +
                '<input type="text" inputmode="decimal" class="lm-modal-input lm-num-field lm-party-cat-rate text-right" ' +
                'data-cat-id="' + esc(c.id) + '" data-cat-name="' + esc(c.category_name) + '" ' +
                'value="' + esc(rate) + '" placeholder="0.00">' +
                '</div></div>';
        });
        return rows;
    }

    function showLmPartyModal(prefillName) {
        Swal.fire(Object.assign({}, LM_SWAL_BASE, {
            width: '360px',
            title: lmModalTitle('user-plus', 'Add Jeweller'),
            html:
                '<div class="text-left" style="font-family:Inter,sans-serif">' +
                '<div class="mb-1.5">' +
                '<label class="lm-modal-label">Party Name *</label>' +
                '<input id="lmPartyName" class="lm-modal-input uppercase" placeholder="Jeweller / party name" value="' + esc(toPartyNameUpper(prefillName || '')) + '">' +
                '</div>' +
                '<div class="grid grid-cols-2 gap-x-2 mb-1.5">' +
                '<div><label class="lm-modal-label">Mobile</label>' +
                '<input id="lmPartyContact" class="lm-modal-input" placeholder="Mobile no"></div>' +
                '<div><label class="lm-modal-label">City</label>' +
                '<input id="lmPartyCity" class="lm-modal-input" placeholder="City"></div>' +
                '</div>' +
                '<div class="mb-1.5">' +
                '<label class="lm-modal-label">Address</label>' +
                '<input id="lmPartyAddress" class="lm-modal-input" placeholder="Address (optional)">' +
                '</div>' +
                '<div class="grid grid-cols-2 gap-x-2 mb-2">' +
                '<div><label class="lm-modal-label">Logo</label>' +
                '<input id="lmPartyLogo" class="lm-modal-input" placeholder="Logo mark"></div>' +
                '<div><label class="lm-modal-label">Cash Balance (₹)</label>' +
                '<input id="lmPartyCashBal" type="text" inputmode="decimal" class="lm-modal-input lm-num-field text-right" placeholder="0.00"></div>' +
                '</div>' +
                '<div class="border border-indigo-100 rounded bg-indigo-50/40 px-2 py-1.5">' +
                '<div class="flex items-center justify-between mb-1">' +
                '<label class="text-[9px] font-black text-indigo-700 uppercase tracking-wide m-0">Category Rates</label>' +
                '<span class="text-[8px] text-slate-400">Custom rate for this party</span>' +
                '</div>' +
                '<div class="grid grid-cols-12 gap-1.5 pb-0.5 mb-0.5">' +
                '<div class="col-span-6 text-[8px] font-bold text-slate-400 uppercase">Category</div>' +
                '<div class="col-span-6 text-[8px] font-bold text-slate-400 uppercase text-right">Rate (₹)</div>' +
                '</div>' +
                '<div class="max-h-36 overflow-y-auto">' + categoryRatesModalHtml() + '</div>' +
                '</div></div>',
            showCancelButton: true,
            confirmButtonText: 'Create',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#4f46e5',
            didOpen: function () {
                setTimeout(function () {
                    const $name = $('#lmPartyName');
                    if ($name.val()) $('#lmPartyContact').focus();
                    else $name.focus().select();
                }, 80);
                const fields = [
                    '#lmPartyName', '#lmPartyContact', '#lmPartyCity', '#lmPartyAddress',
                    '#lmPartyLogo', '#lmPartyCashBal'
                ];
                fields.forEach(function (sel, idx) {
                    $(sel).on('keydown', function (e) {
                        if (e.key !== 'Enter') return;
                        e.preventDefault();
                        if (idx < fields.length - 1) $(fields[idx + 1]).focus();
                        else $('.lm-party-cat-rate').first().focus().select();
                    });
                });
                $('#lmPartyName').on('input', function () {
                    const el = this;
                    const start = el.selectionStart;
                    const end = el.selectionEnd;
                    const upper = toPartyNameUpper(el.value);
                    if (el.value !== upper) {
                        el.value = upper;
                        if (start != null && end != null) {
                            el.setSelectionRange(start, end);
                        }
                    }
                });
                $(document).off('keydown.lmPartyRate').on('keydown.lmPartyRate', '.lm-party-cat-rate', function (e) {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    const $all = $('.lm-party-cat-rate');
                    const i = $all.index(this);
                    if (i < $all.length - 1) $all.eq(i + 1).focus().select();
                    else Swal.clickConfirm();
                });
            },
            willClose: function () {
                $(document).off('keydown.lmPartyRate');
            },
            preConfirm: function () {
                const party_name = toPartyNameUpper($('#lmPartyName').val());
                if (!party_name) {
                    Swal.showValidationMessage('Party name required');
                    return false;
                }
                const category_rates = [];
                $('.lm-party-cat-rate').each(function () {
                    const $el = $(this);
                    category_rates.push({
                        category_id: parseInt($el.data('cat-id'), 10) || 0,
                        category_name: String($el.data('cat-name') || ''),
                        rate: parseFloat($el.val()) || 0
                    });
                });
                return {
                    party_name: party_name,
                    contact_no: $('#lmPartyContact').val().trim(),
                    city: $('#lmPartyCity').val().trim(),
                    address: $('#lmPartyAddress').val().trim(),
                    logo: $('#lmPartyLogo').val().trim(),
                    cash_balance: parseFloat($('#lmPartyCashBal').val()) || 0,
                    state: '',
                    gstin: 'N/A',
                    category_rates: category_rates
                };
            }
        })).then(function (result) {
            if (!result.isConfirmed) return;
            const data = result.value;
            $.ajax({
                url: '',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'save_party',
                    party_name: data.party_name,
                    contact_no: data.contact_no,
                    city: data.city,
                    address: data.address,
                    logo: data.logo,
                    cash_balance: data.cash_balance,
                    state: data.state,
                    gstin: data.gstin,
                    category_rates: JSON.stringify(data.category_rates)
                },
                success: function (res) {
                    if (res.status === 'success' || res.party_id) {
                        lmToast('success', 'Party added');
                        selectParty({
                            id: res.party_id,
                            party_name: data.party_name,
                            contact_no: data.contact_no,
                            logo: data.logo
                        });
                    } else {
                        lmAlert('error', 'Error', res.message || 'Could not add party');
                    }
                },
                error: function () {
                    lmAlert('error', 'Error', 'Server connection failed');
                }
            });
        });
    }

    function showCategoryModal(onSaved) {
        Swal.fire(Object.assign({}, LM_SWAL_BASE, {
            title: lmModalTitle('tags', 'Add Category'),
            html:
                '<div class="text-left" style="font-family:Inter,sans-serif">' +
                '<div class="mb-1.5">' +
                '<label class="lm-modal-label">Category Name *</label>' +
                '<input id="lmCatName" class="lm-modal-input" placeholder="Ring, Chain, Bangle">' +
                '</div>' +
                '<div class="grid grid-cols-2 gap-x-2">' +
                '<div><label class="lm-modal-label">Default Rate</label>' +
                '<input id="lmCatRate" type="text" inputmode="decimal" class="lm-modal-input lm-num-field" value="0"></div>' +
                '<div><label class="lm-modal-label">Rate Basis</label>' +
                '<select id="lmCatBasis" class="lm-modal-input">' +
                '<option value="per_piece">Per Piece</option>' +
                '<option value="per_gram">Per Gram</option>' +
                '</select></div></div></div>',
            showCancelButton: true,
            confirmButtonText: 'Save',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#4f46e5',
            didOpen: function () {
                setTimeout(function () { $('#lmCatName').focus(); }, 80);
                $('#lmCatName, #lmCatRate, #lmCatBasis').on('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); Swal.clickConfirm(); }
                });
            },
            preConfirm: function () {
                const name = $('#lmCatName').val().trim();
                if (!name) {
                    Swal.showValidationMessage('Name required');
                    return false;
                }
                return {
                    category_name: name,
                    default_rate: parseFloat($('#lmCatRate').val()) || 0,
                    rate_basis: $('#lmCatBasis').val()
                };
            }
        })).then(function (result) {
            if (!result.isConfirmed) return;
            $.post('', { action: 'save_category', ...result.value }, function (res) {
                if (res.status === 'success') {
                    const exists = categories.some(function (c) { return c.id === res.data.id; });
                    if (!exists) categories.push(res.data);
                    else {
                        categories = categories.map(function (c) {
                            return c.id === res.data.id ? res.data : c;
                        });
                    }
                    $('#lmItemsBody .lm-category').each(function () {
                        const cur = $(this).val();
                        $(this).html(categoryOptionsHtml(cur));
                    });
                    lmToast('success', 'Category saved');
                    if (typeof onSaved === 'function') onSaved(res.data);
                } else {
                    lmAlert('error', 'Error', res.message);
                }
            }, 'json');
        });
    }

    function focusAfterPartySelect() {
        setTimeout(function () {
            if ($('#lmMobile').val()) {
                lmFocusFieldFn($('#lmLogo'));
            } else {
                lmFocusFieldFn($('#lmMobile'));
            }
        }, 60);
    }

    function updateLmPartyHighlight() {
        const items = document.querySelectorAll('#lmPartyList .party-item');
        items.forEach(function (item, index) {
            if (index === lmPartyIndex) {
                item.classList.add('bg-yellow-50');
                if (item.tagName === 'TR') {
                    item.classList.add('ring-1', 'ring-yellow-300');
                } else {
                    item.classList.add('border-yellow-300');
                }
                item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                item.classList.remove('bg-yellow-50', 'border-yellow-300', 'ring-1', 'ring-yellow-300');
            }
        });
    }

    function pickPartyFromListItem(el) {
        const $el = $(el);
        if ($el.data('create-new')) {
            const term = $el.data('name') || $('#lmJewellerName').val().trim();
            showLmPartyModal(term);
            return;
        }
        selectParty({
            id: $el.data('id'),
            party_name: $el.data('name'),
            contact_no: $el.data('contact'),
            logo: $el.data('logo')
        });
    }

    function selectParty(party) {
        $('#lmJewellerId').val(party.id);
        $('#lmJewellerName').val(party.party_name).addClass('border-green-500');
        if (party.contact_no) $('#lmMobile').val(party.contact_no);
        if (party.logo) $('#lmLogo').val(party.logo);
        $('#lmPartyList').addClass('hidden');
        partyListVisible = false;
        lmPartyIndex = -1;
        loadProducts(party.id);
        setTimeout(function () { $('#lmJewellerName').removeClass('border-green-500'); }, 1500);
        focusAfterPartySelect();
    }

    function searchParties(term) {
        term = String(term || '').trim();
        if (!term) {
            $('#lmPartyList').addClass('hidden').empty();
            partyListVisible = false;
            return;
        }
        $.ajax({
            url: '',
            type: 'POST',
            dataType: 'json',
            data: { action: 'search_parties', term: term },
            success: function (parties) {
                if (!Array.isArray(parties)) {
                    parties = [];
                }
                let html = '';
                if (parties.length === 0) {
                    html = '<div class="p-2 text-gray-500 party-item cursor-pointer hover:bg-yellow-50 border-b" data-create-new="1" data-name="' + esc(term) + '">' +
                        '<i class="fas fa-plus-circle text-indigo-500"></i> Create "' + esc(term) + '"</div>';
                } else {
                    html += '<table class="w-full lm-party-table border-collapse">' +
                        '<thead><tr class="text-[8px] uppercase text-slate-400 bg-slate-50">' +
                        '<th class="p-1 w-6 text-center">#</th>' +
                        '<th class="p-1 w-10 text-center">ID</th>' +
                        '<th class="p-1 text-left">Party</th>' +
                        '<th class="p-1 text-left">Mobile</th>' +
                        '</tr></thead><tbody>';
                    parties.forEach(function (p, i) {
                        html += '<tr class="party-item cursor-pointer hover:bg-indigo-50 border-t border-slate-100" data-id="' + p.id +
                            '" data-name="' + esc(p.party_name) +
                            '" data-contact="' + esc(p.contact_no || '') +
                            '" data-logo="' + esc(p.logo || '') + '">' +
                            '<td class="p-1.5 text-center text-slate-400 font-bold">' + (i + 1) + '</td>' +
                            '<td class="p-1.5 text-center text-slate-500">' + p.id + '</td>' +
                            '<td class="p-1.5 font-bold text-[11px] text-slate-800">' + esc(p.party_name) + '</td>' +
                            '<td class="p-1.5 text-[10px] text-slate-500">' + esc(p.contact_no || '—') + '</td>' +
                            '</tr>';
                    });
                    html += '</tbody></table>';
                }
                $('#lmPartyList').html(html).removeClass('hidden');
                partyListVisible = true;
                lmPartyIndex = -1;
            },
            error: function () {
                $('#lmPartyList').html(
                    '<div class="p-2 text-gray-500 party-item cursor-pointer hover:bg-yellow-50 border-b" data-create-new="1" data-name="' + esc(term) + '">' +
                    '<i class="fas fa-plus-circle text-indigo-500"></i> Create "' + esc(term) + '"</div>'
                ).removeClass('hidden');
                partyListVisible = true;
                lmPartyIndex = -1;
            }
        });
    }

    function resetForm() {
        $('#lmRequestId').val('');
        $('#lmReceiptId').val(cfg.nextReceiptId);
        $('#lmRequestDate').val(nowLocalDatetime());
        $('#lmJewellerId').val('');
        $('#lmJewellerName').val('');
        $('#lmMobile, #lmLogo, #lmBoxNo').val('');
        $('#lmReceivedAmount').val('');
        $('#lmPaymentMethod').val('Cash');
        $('#lmItemsBody').empty();
        itemRowIndex = 0;
        products = [];
        categoryRates = [];
        $('#lmEditBadge, #lmDeleteBtn').addClass('hidden');
        $('#lmGrandTotalInput').val('0');
        $('#lmGrandTotalReadonly').val('0');
        $('#lmTotalPcs').text('0');
        $('#lmTotalWeight').text('0');
        $('#lmTotalAmount').text('0');
        updateBalanceDisplay();
        addItemRow();
        loadRecentList();
    }

    function populateForm(data) {
        $('#lmRequestId').val(data.id);
        $('#lmReceiptId').val(data.receipt_id);
        $('#lmRequestDate').val((data.request_date || '').replace(' ', 'T').slice(0, 16));
        $('#lmJewellerId').val(data.jeweller_id);
        $('#lmJewellerName').val(data.jeweller_name || '');
        $('#lmMobile').val(data.mobile || '');
        $('#lmLogo').val(data.logo || '');
        $('#lmBoxNo').val(data.box_no || '');
        $('#lmReceivedAmount').val(data.received_amount != null && data.received_amount !== '' ? data.received_amount : '');
        $('#lmPaymentMethod').val(data.payment_method || 'Cash');
        $('#lmEditBadge, #lmDeleteBtn').removeClass('hidden');

        loadProducts(data.jeweller_id);
        $('#lmItemsBody').empty();
        itemRowIndex = 0;
        (data.items || []).forEach(function (it) { addItemRow(it); });
        if ((data.items || []).length === 0) addItemRow();
        updateGrandTotal();
    }

    function loadRecentList() {
        const $tbody = $('#lmRecentList');
        $tbody.html('<tr><td colspan="8" class="p-3 text-center text-gray-400">Loading…</td></tr>');
        $.post('', {
            action: 'list_requests',
            start_date: $('#lmStartDate').val(),
            end_date: $('#lmEndDate').val(),
            search: ($('#lmListSearch').val() || '').trim()
        }, function (res) {
            if (!res || res.status !== 'success') {
                const msg = (res && res.message) ? esc(res.message) : 'Could not load list';
                $tbody.html('<tr><td colspan="8" class="p-3 text-center text-red-500">' + msg + '</td></tr>');
                return;
            }
            const rows = Array.isArray(res.data) ? res.data : [];
            let html = '';
            if (!rows.length) {
                html = '<tr><td colspan="8" class="p-4 text-center text-gray-400">No requests found</td></tr>';
            } else {
                rows.forEach(function (r, idx) {
                    const paid = parseFloat(r.received_amount) || 0;
                    const total = parseFloat(r.total_amount) || 0;
                    const pcs = parseInt(r.total_pcs, 10) || 0;
                    const status = paymentStatusLabel(r.status, total, paid);
                    html += '<tr class="border-t hover:bg-gray-50 lm-list-row">' +
                        '<td class="py-1.5 px-1 align-top text-center lm-serial-col"><span class="text-[9px] font-bold text-slate-400 tabular-nums">' + (idx + 1) + '</span></td>' +
                        '<td class="py-1.5 px-1.5 align-top lm-id-col">' + buildListIdCell(r.receipt_id, r.request_date) + '</td>' +
                        '<td class="py-1.5 px-1.5 align-top lm-party-col"><div class="truncate font-semibold text-slate-800" title="' + esc(r.jeweller_name) + '">' + esc(r.jeweller_name) + '</div></td>' +
                        '<td class="py-1.5 px-1 align-top lm-box-col truncate">' + esc(r.box_no || '—') + '</td>' +
                        '<td class="py-1.5 px-1 align-top text-center lm-pcs-col font-bold tabular-nums">' + pcs + '</td>' +
                        '<td class="py-1.5 px-1 align-top lm-amt-col font-bold tabular-nums whitespace-nowrap">' + formatInr(total) + '</td>' +
                        '<td class="py-1.5 px-1 align-top lm-status-col">' + buildListStatusCell(status, paid) + '</td>' +
                        '<td class="py-1.5 px-1 align-top lm-action-col">' +
                        '<div class="lm-row-actions">' +
                        '<button type="button" class="lm-edit-req text-indigo-600 font-bold" data-id="' + r.id + '" title="Edit"><i class="fas fa-edit"></i></button>' +
                        '<button type="button" class="lm-print-req text-emerald-600 font-bold" data-id="' + r.id + '" title="Print"><i class="fas fa-print"></i></button>' +
                        '</div></td>' +
                        '</tr>';
                });
            }
            $tbody.html(html);
        }, 'json').fail(function () {
            $tbody.html('<tr><td colspan="8" class="p-3 text-center text-red-500">Could not load list</td></tr>');
        });
    }

    function saveRequest() {
        if (lmSaving || lmSaveLocked) {
            return;
        }
        if ($('.swal2-container:visible').length) {
            return;
        }
        const jewellerId = parseInt($('#lmJewellerId').val(), 10);
        if (!jewellerId) {
            lmAlert('warning', 'Required', 'Please select a jeweller.');
            return;
        }
        const items = collectItems();
        if (!items.length) {
            lmAlert('warning', 'Required', 'Add at least one item with a name.');
            return;
        }

        lmSaving = true;
        const editingId = parseInt($('#lmRequestId').val(), 10) || 0;
        const isEdit = editingId > 0;
        const $saveBtn = $('#lmSaveBtn');
        const saveBtnHtml = $saveBtn.html();
        $saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');

        $.post('', {
            action: 'save_request',
            request_id: editingId,
            receipt_id: $('#lmReceiptId').val(),
            request_date: $('#lmRequestDate').val(),
            jeweller_id: jewellerId,
            mobile: $('#lmMobile').val(),
            logo: $('#lmLogo').val(),
            box_no: $('#lmBoxNo').val(),
            received_amount: $('#lmReceivedAmount').val(),
            payment_method: $('#lmPaymentMethod').val(),
            items: JSON.stringify(items)
        }, function (res) {
            if (res.status !== 'success') {
                lmAlert('error', 'Error', res.message);
                return;
            }

            if (res.request_id) {
                $('#lmRequestId').val(res.request_id);
            }
            if (res.receipt_id) {
                $('#lmReceiptId').val(res.receipt_id);
            }
            if (res.stats) {
                updateDashboardStats(res.stats);
            }
            loadRecentList();

            if (isEdit) {
                unlockSaveUi($saveBtn, saveBtnHtml);
                Swal.fire({
                    icon: 'success',
                    title: 'Updated!',
                    text: res.message || 'Request updated',
                    width: '320px',
                    timer: 1600,
                    showConfirmButton: false
                });
                return;
            }

            lmSaveLocked = true;
            $('#lmForm').find('input, select, textarea, button').prop('disabled', true);
            const savedMsg = res.duplicate
                ? 'This entry was already saved. Duplicate not created.'
                : res.message;
            Swal.fire({
                icon: 'success',
                title: res.duplicate ? 'Already Saved' : 'Saved!',
                text: savedMsg,
                width: '320px',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-print mr-1"></i> PRINT',
                cancelButtonText: 'CLOSE',
                confirmButtonColor: '#4f46e5',
                cancelButtonColor: '#64748b',
                allowOutsideClick: false,
                stopKeydownPropagation: true,
                returnFocus: false
            }).then(function (result) {
                if (result.isConfirmed && res.request_id) {
                    openLogoMarkingReceiptPrint(res.request_id);
                }
                finishSaveAndNewForm(res.next_receipt_id || cfg.nextReceiptId);
            });
        }, 'json').fail(function () {
            lmAlert('error', 'Error', 'Could not save request.');
        }).always(function () {
            if (!lmSaveLocked) {
                unlockSaveUi($saveBtn, saveBtnHtml);
            }
        });
    }

    $(function () {
        $('#lmRequestDate').val(nowLocalDatetime());

        if (categories.length === 0) {
            setTimeout(function () {
                Swal.fire(Object.assign({}, LM_SWAL_BASE, {
                    title: lmModalTitle('layer-group', 'Setup'),
                    html: '<p class="text-[10px] text-slate-600 leading-snug m-0">Add your first item category to start logo marking entries.</p>',
                    showCancelButton: true,
                    confirmButtonText: 'Create',
                    cancelButtonText: 'Later',
                    confirmButtonColor: '#4f46e5'
                })).then(function (r) {
                    if (r.isConfirmed) showCategoryModal();
                    else setTimeout(function () { $('#lmJewellerName').trigger('focus'); }, 80);
                });
            }, 400);
        } else {
            setTimeout(function () { $('#lmJewellerName').trigger('focus'); }, 80);
        }

        addItemRow();

        $('#lmAddItemBtn').on('click', function () { addItemRow(null, '.lm-category'); });

        $('#lmItemsBody').on('click', '.lm-remove-row', function () {
            $(this).closest('tr').remove();
            renumberRows();
            updateGrandTotal();
        });

        $('#lmItemsBody').on('input change', '.lm-pieces, .lm-weight, .lm-rate', function () {
            recalcRow($(this).closest('tr'));
        });

        $('#lmItemsBody').on('change', '.lm-category', function () {
            const $row = $(this).closest('tr');
            const val = $(this).val();
            if (val === '__add_cat__') {
                const prev = $(this).data('prevCat') || defaultCategoryName();
                $(this).html(categoryOptionsHtml(prev)).val(prev);
                showCategoryModal();
                return;
            }
            $(this).data('prevCat', val);
            applyCategoryDefaults($row, true);
            recalcRow($row);
        });

        $('#lmReceivedAmount').on('input', updateBalanceDisplay);

        $('#lmPaymentMethod').on('change', updateBalanceDisplay);

        $('#lmItemsBody').on('change', '.lm-item-name', function () {
            const $row = $(this).closest('tr');
            syncProductMetaToRow($row, true);
            if (!$row.find('.lm-rate').val()) applyCategoryDefaults($row, true);
            recalcRow($row);
        });

        initLmKeyboardNav();

        $('#lmJewellerName').on('input', function () {
            const el = this;
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const upper = toPartyNameUpper(el.value);
            if (el.value !== upper) {
                el.value = upper;
                if (start != null && end != null) {
                    el.setSelectionRange(start, end);
                }
            }
            const term = upper.trim();
            $('#lmJewellerId').val('');
            lmPartyIndex = -1;
            if (term.length >= 1) searchParties(term);
            else $('#lmPartyList').addClass('hidden');
        });

        $('#lmJewellerName').on('keydown', function (e) {
            const partyItems = document.querySelectorAll('#lmPartyList .party-item');

            if (e.key === 'ArrowDown' && partyListVisible && partyItems.length > 0) {
                e.preventDefault();
                lmPartyIndex = lmPartyIndex < 0 ? 0 : Math.min(lmPartyIndex + 1, partyItems.length - 1);
                updateLmPartyHighlight();
                return;
            }
            if (e.key === 'ArrowUp' && partyListVisible && partyItems.length > 0) {
                e.preventDefault();
                lmPartyIndex = lmPartyIndex <= 0 ? -1 : Math.max(lmPartyIndex - 1, 0);
                updateLmPartyHighlight();
                return;
            }
            if (e.key === 'Enter' && partyListVisible && partyItems.length > 0) {
                e.preventDefault();
                e.stopImmediatePropagation();
                const idx = lmPartyIndex >= 0 ? lmPartyIndex : 0;
                pickPartyFromListItem(partyItems[idx]);
                return;
            }
            if (e.key === 'Escape') {
                $('#lmPartyList').addClass('hidden');
                partyListVisible = false;
                lmPartyIndex = -1;
            }
        });

        $('#lmPartyList').on('click', '.party-item', function () {
            pickPartyFromListItem(this);
        });

        $('#lmAddPartyBtn').on('click', function () {
            showLmPartyModal($('#lmJewellerName').val().trim());
        });

        $('#lmAddCategoryBtn').on('click', function () { showCategoryModal(); });

        $('#lmForm').on('submit', function (e) {
            e.preventDefault();
            if (lmSaveLocked || $('.swal2-container:visible').length) {
                return false;
            }
            saveRequest();
            return false;
        });

        $('#lmResetBtn').on('click', function () {
            lmConfirm('Reset Form', 'Clear all fields and start a new request?', 'Reset').then(function (r) {
                if (r.isConfirmed) resetForm();
            });
        });

        $('#lmDeleteBtn').on('click', function () {
            const id = $('#lmRequestId').val();
            if (!id) return;
            lmConfirm('Delete Request', 'This cannot be undone.', 'Delete', '#dc2626').then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'delete_request', request_id: id }, function (res) {
                    if (res.status === 'success') {
                        if (res.stats) updateDashboardStats(res.stats);
                        resetForm();
                        loadRecentList();
                    } else {
                        lmAlert('error', 'Error', res.message);
                    }
                }, 'json');
            });
        });

        $('#lmFilterBtn').on('click', function () {
            loadRecentList();
            loadDashboardStats();
        });

        $('#lmListSearch').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadRecentList();
            }
        });

        $(document).on('click', '.lm-print-req', function () {
            openLogoMarkingReceiptPrint($(this).data('id'));
        });

        $(document).on('click', '.lm-edit-req', function () {
            const id = $(this).data('id');
            $.post('', { action: 'get_request_by_id', request_id: id }, function (res) {
                if (res.status === 'success') populateForm(res.data);
                else lmAlert('error', 'Error', res.message);
            }, 'json');
        });

        $('#lmShowReceiptListBtn, #lmReceiptId').on('click', function (e) {
            e.preventDefault();
            $.post('', {
                action: 'list_requests',
                start_date: $('#lmStartDate').val(),
                end_date: $('#lmEndDate').val(),
                limit: 20
            }, function (res) {
                if (res.status !== 'success') return;
                let html = '';
                res.data.forEach(function (r) {
                    html += '<div class="p-2 border-b cursor-pointer hover:bg-indigo-50 receipt-item" data-receipt="' + esc(r.receipt_id) + '">' +
                        '<span class="font-bold">' + esc(r.receipt_id) + '</span> — ' + esc(r.jeweller_name) + '</div>';
                });
                $('#lmReceiptList').html(html || '<div class="p-2 text-gray-400">No receipts</div>').toggleClass('hidden', false);
                receiptListVisible = true;
            }, 'json');
        });

        $('#lmReceiptList').on('click', '.receipt-item', function () {
            const rid = $(this).data('receipt');
            $.post('', { action: 'get_request_by_receipt', receipt_id: rid }, function (res) {
                if (res.status === 'success') populateForm(res.data);
                else lmAlert('error', 'Error', res.message);
            }, 'json');
            $('#lmReceiptList').addClass('hidden');
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('#lmJewellerName, #lmPartyList').length) {
                $('#lmPartyList').addClass('hidden');
                partyListVisible = false;
            }
            if (!$(e.target).closest('#lmReceiptId, #lmReceiptList, #lmShowReceiptListBtn').length) {
                $('#lmReceiptList').addClass('hidden');
                receiptListVisible = false;
            }
        });

        loadRecentList();

        // Focus jeweller/party field on page load (after setup prompt if shown)
        setTimeout(function () {
            if ($('.swal2-container').length) return;
            $('#lmJewellerName').trigger('focus');
        }, 120);
    });

    /** Tally-style keyboard nav (Enter/Tab next, Backspace prev, arrows in item rows) — like exchange. */
    function initLmKeyboardNav() {
        function lmAllFields() {
            return $('#lmForm').find('input:not([type="hidden"]):visible, select:visible, textarea:visible')
                .not(':disabled, [readonly], [tabindex="-1"], .lm-amount');
        }

        function lmFocusField($field) {
            if (!$field || !$field.length) return;
            $field.focus();
            if ($field.is('input[type="text"], input[type="number"], input[type="datetime-local"]')) {
                setTimeout(function () { $field.select(); }, 10);
            }
        }
        lmFocusFieldFn = lmFocusField;

        function lmMoveNext($current) {
            const $all = lmAllFields();
            const idx = $all.index($current);
            if (idx >= 0 && idx < $all.length - 1) {
                lmFocusField($all.eq(idx + 1));
                return;
            }
            $('#lmSaveBtn').focus();
        }
        lmMoveNextField = lmMoveNext;

        function lmMovePrev($current) {
            const $all = lmAllFields();
            const idx = $all.index($current);
            if (idx > 0) {
                lmFocusField($all.eq(idx - 1));
            }
        }

        $('#lmForm').on('keydown', 'input:not([type="hidden"]), select, textarea', function (e) {
            const $field = $(this);
            if ($field.is(':disabled') || $field.is('[readonly]')) return;

            const val = ($field.val() || '').toString();

            if (e.key === 'Enter' && !e.shiftKey) {
                if ($field.attr('id') === 'lmJewellerName' && partyListVisible && !$('#lmPartyList').hasClass('hidden')) {
                    return;
                }
                if ($field.attr('id') === 'lmReceiptId' && receiptListVisible && !$('#lmReceiptList').hasClass('hidden')) {
                    return;
                }
                if ($field.is('select')) {
                    e.preventDefault();
                    lmMoveNext($field);
                    return false;
                }
                if ($field.is('textarea')) return;
                e.preventDefault();
                lmMoveNext($field);
                return false;
            }

            if (e.key === 'Backspace' && val.trim() === '') {
                e.preventDefault();
                lmMovePrev($field);
                return false;
            }

            if (e.key === 'Tab') {
                e.preventDefault();
                if (e.shiftKey) lmMovePrev($field);
                else lmMoveNext($field);
                return false;
            }
        });

        $('#lmForm').on('keydown', '.lm-item-row input, .lm-item-row select', function (e) {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            if (e.shiftKey || e.ctrlKey || e.altKey) return;

            const $cell = $(this);
            const $row = $cell.closest('tr');
            const cellIndex = $cell.closest('td').index();

            if (e.key === 'ArrowRight') {
                const $next = $cell.closest('td').nextAll('td').find('input, select').first();
                if ($next.length) { e.preventDefault(); lmFocusField($next); }
            } else if (e.key === 'ArrowLeft') {
                const $prev = $cell.closest('td').prevAll('td').find('input, select').last();
                if ($prev.length) { e.preventDefault(); lmFocusField($prev); }
            } else if (e.key === 'ArrowDown') {
                const $nextRow = $row.next('.lm-item-row');
                if ($nextRow.length) {
                    const $target = $nextRow.find('td').eq(cellIndex).find('input, select').first();
                    if ($target.length) { e.preventDefault(); lmFocusField($target); }
                }
            } else if (e.key === 'ArrowUp') {
                const $prevRow = $row.prev('.lm-item-row');
                if ($prevRow.length) {
                    const $target = $prevRow.find('td').eq(cellIndex).find('input, select').first();
                    if ($target.length) { e.preventDefault(); lmFocusField($target); }
                }
            }
        });

        $('#lmSaveBtn').on('click', function (e) {
            e.preventDefault();
            saveRequest();
        });

        $('#lmSaveBtn').on('keydown', function (e) {
            if (lmSaveLocked || $('.swal2-container:visible').length) {
                e.preventDefault();
                return false;
            }
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                saveRequest();
            }
        });

        $(document).on('keydown', function (e) {
            if ($(e.target).closest('.swal2-container').length) {
                return;
            }
            if (lmSaveLocked) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
                return;
            }
            if (e.altKey && e.key.toLowerCase() === 's') {
                e.preventDefault();
                saveRequest();
            } else if (e.altKey && e.key.toLowerCase() === 'a') {
                e.preventDefault();
                addItemRow(null, '.lm-category');
            } else if (e.altKey && e.key.toLowerCase() === 'r') {
                e.preventDefault();
                resetForm();
            }
        });
    }
})();

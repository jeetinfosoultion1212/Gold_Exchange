/**
 * Party Ledger — rendering module.
 * Formatters, dashboard summary cards, and all transaction table renderers.
 * Exposes its API on window.PL.render so party_ledger.js can call it.
 */
(function () {
    'use strict';

    window.PL = window.PL || {};

    /** Single Indian-currency formatter — every screen number goes through this. */
    function formatIndianCurrency(amount) {
        if (amount === undefined || amount === null) return '0.00';

        let num = parseFloat(String(amount).replace(/,/g, ''));
        if (isNaN(num)) return '0.00';

        num = Math.round(num * 100) / 100;
        const str = num.toFixed(2);
        const parts = str.split('.');
        let integerPart = parts[0];
        const decimalPart = parts[1];

        if (integerPart.length > 3) {
            const lastThree = integerPart.slice(-3);
            const remaining = integerPart.slice(0, -3);

            if (remaining.length > 2) {
                const lastTwo = remaining.slice(-2);
                const beforeLastTwo = remaining.slice(0, -2);
                integerPart = beforeLastTwo + ',' + lastTwo + ',' + lastThree;
            } else {
                integerPart = remaining + ',' + lastThree;
            }
        }

        return integerPart + '.' + decimalPart;
    }

    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return isNaN(date.getTime()) ? '-' : date.toLocaleDateString('en-GB');
    }

    function formatTime(dateString) {
        const date = new Date(dateString);
        return isNaN(date.getTime()) ? '-' : date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    function formatRupee(amount) {
        const num = parseFloat(amount);
        if (isNaN(num)) return '₹0.00';
        const sign = num < 0 ? '−' : '';
        return sign + '₹' + formatIndianCurrency(Math.abs(num));
    }

    function getTransactionTypeClass(type) {
        switch (type) {
            case 'Booking': return 'bg-blue-100 text-blue-800';
            case 'Sale': return 'bg-green-100 text-green-800';
            case 'Purchase': return 'bg-purple-100 text-purple-800';
            case 'Payment': return 'bg-yellow-100 text-yellow-800';
            case 'Gold_Received': return 'bg-yellow-100 text-yellow-800';
            case 'Exchange': return 'bg-orange-100 text-orange-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    }

    function getTransactionTypeIcon(type) {
        switch (type) {
            case 'Booking': return 'fas fa-book';
            case 'Sale': return 'fas fa-shopping-cart';
            case 'Purchase': return 'fas fa-truck-loading';
            case 'Payment': return 'fas fa-credit-card';
            case 'Gold_Received': return 'fas fa-coins';
            case 'Exchange': return 'fas fa-exchange-alt';
            default: return 'fas fa-file-alt';
        }
    }

    /** Channel (cash/bank) a sale line item belongs to. */
    function saleLineChannel(row) {
        const ch = row.booking_type || row.receipt_method || row.mode || 'Cash';
        return String(ch).toLowerCase() === 'bank' ? 'bank' : 'cash';
    }

    /** Sum of gold_sale_items line amounts per transaction (for proportional GST split). */
    function saleTxnLineSumMap(saleLineItems) {
        const map = {};
        (saleLineItems || []).forEach((r) => {
            const tid = String(r.transaction_id);
            const sid = parseInt(r.sale_item_id, 10) || 0;
            const amt = parseFloat(r.amount) || 0;
            const gst = parseFloat(r.total_gst) || 0;
            if (!map[tid]) {
                map[tid] = { sumLines: 0, totalGst: gst };
            }
            map[tid].totalGst = parseFloat(r.total_gst) || map[tid].totalGst;
            if (sid > 0) {
                map[tid].sumLines += amt;
            }
        });
        return map;
    }

    /**
     * Single GST-split helper for a Sale line item (replaces the old three
     * near-duplicate functions saleDisplayAmount / saleLineTaxableAmount /
     * saleLineGstAmount). Returns { taxable, gst, total } for the line.
     */
    function splitSaleLine(row, channel, txnMeta) {
        const base = parseFloat(row.amount) || 0;
        if (channel !== 'bank') {
            return { taxable: base, gst: 0, total: base };
        }

        const sid = parseInt(row.sale_item_id, 10) || 0;
        const gstTotal = parseFloat(row.total_gst) || 0;

        if (sid === 0) {
            const tx = parseFloat(row.taxable_amount);
            const taxable = !isNaN(tx) ? tx : base;
            return { taxable, gst: gstTotal, total: base };
        }

        const tid = String(row.transaction_id);
        const meta = txnMeta[tid];
        const sum = meta && meta.sumLines > 0 ? meta.sumLines : 0;
        if (gstTotal <= 0 || sum <= 0) {
            return { taxable: base, gst: 0, total: base };
        }
        const gst = gstTotal * (base / sum);
        return { taxable: base, gst, total: base + gst };
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    /** Dashboard: hero outstanding-balance card + secondary metric grid. */
    function renderSummaryCards(summary) {
        const cur = parseFloat(summary.current_balance) || 0;
        const state = cur > 0 ? 'is-due' : cur < 0 ? 'is-credit' : 'is-clear';
        const stateLabel = cur > 0 ? 'Due' : cur < 0 ? 'Credit' : 'Clear';
        const partyGold = parseFloat(summary.gold_balance ?? summary.current_gold_balance) || 0;

        const heroHtml = `
            <div class="pl-balance-card ${state} mb-2">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-600">Outstanding Balance</p>
                        <p class="pl-balance-amount font-bold tabular-nums">${formatRupee(cur)}</p>
                        <div class="flex items-center gap-1.5 mt-1">
                            <span class="pl-balance-chip text-[10px] font-semibold text-gray-700">${stateLabel}</span>
                            <span class="pl-balance-chip text-[10px] text-gray-600"><i class="fas fa-wallet text-emerald-600 mr-0.5"></i>Cash ${formatRupee(summary.cash_balance || 0)}</span>
                            <span class="pl-balance-chip text-[10px] text-gray-600"><i class="fas fa-university text-indigo-600 mr-0.5"></i>Bank ${formatRupee(summary.bank_balance || 0)}</span>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Party metal balance</p>
                        <p class="text-base font-bold text-amber-800 tabular-nums">${partyGold < 0 ? '−' : ''}${Math.abs(partyGold).toFixed(3)}g</p>
                    </div>
                </div>
            </div>
        `;

        const metric = (opts) => `
            <div class="pl-metric-card">
                <div class="flex items-start justify-between gap-1">
                    <div class="min-w-0">
                        <p class="text-[9px] font-semibold uppercase tracking-wide text-gray-500">${opts.label}</p>
                        <p class="pl-metric-value text-gray-900">${opts.value}</p>
                        ${opts.sub ? `<p class="pl-metric-sub">${opts.sub}</p>` : ''}
                    </div>
                    <div class="pl-metric-icon" style="background-color:${opts.color}"><i class="${opts.icon}"></i></div>
                </div>
            </div>
        `;

        const cashBankSub = (cashVal, bankVal, unit) => `
            <span class="inline-flex items-center gap-0.5"><i class="fas fa-wallet text-emerald-600"></i>${unit === 'g' ? cashVal.toFixed(2) : formatRupee(cashVal)}</span>
            <span class="text-gray-300">|</span>
            <span class="inline-flex items-center gap-0.5"><i class="fas fa-university text-indigo-600"></i>${unit === 'g' ? bankVal.toFixed(2) : formatRupee(bankVal)}</span>
        `;

        const metricsHtml = `
            <div class="pl-metric-grid mb-2">
                ${metric({
                    label: 'Booked', icon: 'fas fa-book', color: '#3b82f6',
                    value: `${(summary.booked_weight || 0).toFixed(2)}g`,
                    sub: cashBankSub(summary.booked_weight_cash || 0, summary.booked_weight_bank || 0, 'g')
                })}
                ${metric({
                    label: 'Sold', icon: 'fas fa-shopping-cart', color: '#22c55e',
                    value: `${(summary.sold_weight || 0).toFixed(2)}g`,
                    sub: cashBankSub(summary.sold_weight_cash || 0, summary.sold_weight_bank || 0, 'g')
                })}
                ${metric({
                    label: 'Purchased', icon: 'fas fa-truck-loading', color: '#8b5cf6',
                    value: `${(summary.purchased_weight || 0).toFixed(2)}g`,
                    sub: cashBankSub(summary.purchased_weight_cash || 0, summary.purchased_weight_bank || 0, 'g')
                })}
                ${metric({
                    label: 'Exchange', icon: 'fas fa-exchange-alt', color: '#f97316',
                    value: `${(summary.gold_received_weight || 0).toFixed(3)}g`,
                    sub: `<span class="text-emerald-700">In ${(summary.gold_received_weight || 0).toFixed(3)}g</span>
                          <span class="text-gray-300">|</span>
                          <span class="text-red-700">Out ${(summary.gold_issued_weight || 0).toFixed(3)}g</span>`
                })}
                ${metric({
                    label: 'Received', icon: 'fas fa-money-bill-wave', color: '#14b8a6',
                    value: formatRupee(summary.total_received || 0),
                    sub: cashBankSub(summary.cash_received || 0, summary.bank_received || 0, 'rs')
                })}
                ${metric({
                    label: 'Party Au', icon: 'fas fa-weight-hanging', color: '#b45309',
                    value: `${partyGold < 0 ? '−' : ''}${Math.abs(partyGold).toFixed(3)}g`,
                    sub: '<span class="text-gray-500">Metal balance</span>'
                })}
            </div>
        `;

        $('#summaryCards').html(heroHtml + metricsHtml);
    }

    function formatWeight(weight) {
        if (weight === null || weight === undefined || weight === '') return '-';
        const num = parseFloat(weight);
        return isNaN(num) ? '-' : num.toFixed(3);
    }

    function formatRate(rate) {
        if (rate === null || rate === undefined || rate === '') return '-';
        const num = parseFloat(rate);
        return isNaN(num) ? '-' : '₹' + num.toFixed(2);
    }

    function formatAmount(amount) {
        if (amount === null || amount === undefined || amount === '') return '-';
        const num = parseFloat(amount);
        return isNaN(num) ? '-' : '₹' + formatIndianCurrency(num);
    }

    function formatPurity(purity) {
        if (purity === null || purity === undefined || purity === '') return '-';
        const num = parseFloat(purity);
        return isNaN(num) ? '-' : num + '%';
    }

    function formatBookingTypeBadge(bookingType) {
        if (!bookingType) return '-';
        const isCash = String(bookingType).toLowerCase() === 'cash';
        return `<span class="pl-method-badge ${isCash ? 'cash' : 'bank'}"><i class="fas ${isCash ? 'fa-wallet' : 'fa-university'}"></i>${bookingType}</span>`;
    }

    function methodBadge(method) {
        if (!method) return '<span class="text-gray-400">–</span>';
        const isCash = String(method).toLowerCase() === 'cash';
        return `<span class="pl-method-badge ${isCash ? 'cash' : 'bank'}"><i class="fas ${isCash ? 'fa-wallet' : 'fa-university'}"></i>${method}</span>`;
    }

    /** "All" tab row. */
    function createTransactionRow(trans, serialNo) {
        const typeClass = getTransactionTypeClass(trans.transaction_type);
        const typeIcon = getTransactionTypeIcon(trans.transaction_type);

        const formatBookingType = (bookingType, transType, t) => {
            if (transType !== 'Booking' && transType !== 'Sale') return '-';
            let ch = bookingType || '';
            if (!ch && t) {
                ch = t.receipt_method || t.mode || 'Cash';
            }
            if (!ch) return '-';
            const badgeClass = String(ch).toLowerCase() === 'bank' ? 'bg-indigo-100 text-indigo-800' : 'bg-purple-100 text-purple-800';
            return `<span class="inline-flex px-1.5 py-0.5 text-[10px] font-medium rounded ${badgeClass}">${ch}</span>`;
        };

        const needsCutVow = trans.transaction_type === 'Sale' && (!trans.rate || parseFloat(trans.rate) <= 0);
        const actionButton = needsCutVow
            ? `<button class="cut-vow-btn px-2 py-1 text-[10px] bg-orange-500 hover:bg-orange-600 text-white rounded transition-colors"
                       data-trans-id="${trans.id}"
                       data-weight="${trans.gold_weight || 0}"
                       title="Cut Vow - Enter Rate">
                    <i class="fas fa-cut mr-1"></i>Cut Vow
                </button>`
            : '-';

        let weightDisplay = formatWeight(trans.gold_weight);
        let amountField = trans.gold_amount;
        if (trans.transaction_type === 'Exchange') {
            const r = parseFloat(trans.received_weight || 0);
            const d = parseFloat(trans.delivered_weight || 0);
            const bits = [];
            if (r) bits.push('<span class="text-emerald-700">R ' + r.toFixed(3) + '</span>');
            if (d) bits.push('<span class="text-red-700">I ' + d.toFixed(3) + '</span>');
            if (bits.length) weightDisplay = bits.join(' ');
            const exAmt = parseFloat(trans.amount || 0) || parseFloat(trans.gold_amount || 0);
            amountField = exAmt > 0 ? exAmt : trans.gold_amount;
        }

        let paymentMethodCell = '-';
        if (trans.payment_amount && trans.payment_amount > 0) {
            paymentMethodCell = methodBadge(trans.payment_method || 'Cash');
        }

        const narrationTitle = escapeHtml(trans.narration);
        const typeBadgeCls = 'pl-type-badge type-' + String(trans.transaction_type || '').toLowerCase().replace(/_/g, '-');

        return `
            <tr class="table-row-hover">
                <td class="text-center text-gray-500 tabular-nums">${serialNo}</td>
                <td class="whitespace-nowrap text-gray-900">${formatDate(trans.date_of_transaction)}</td>
                <td class="whitespace-nowrap">
                    <span class="${typeBadgeCls} ${typeClass}">
                        <i class="${typeIcon}"></i>${trans.transaction_type}
                    </span>
                </td>
                <td class="whitespace-nowrap">${formatBookingType(trans.booking_type, trans.transaction_type, trans)}</td>
                <td class="whitespace-nowrap font-mono text-[10px] text-gray-900">${trans.receipt_id || '-'}</td>
                <td class="whitespace-nowrap text-gray-900 text-right">${weightDisplay}</td>
                <td class="whitespace-nowrap text-gray-900 text-right">${formatRate(trans.rate)}</td>
                <td class="whitespace-nowrap text-gray-900 text-right">${formatAmount(amountField)}</td>
                <td class="whitespace-nowrap text-gray-900 text-right">${trans.payment_amount && trans.payment_amount > 0 ? formatAmount(trans.payment_amount) : '-'}</td>
                <td class="whitespace-nowrap text-gray-500 text-[10px]">${paymentMethodCell}</td>
                <td class="text-gray-500 max-w-[10rem] truncate" title="${narrationTitle}">${trans.narration || '-'}</td>
                <td class="whitespace-nowrap text-center">${actionButton}</td>
            </tr>
        `;
    }

    /** Bookings / Payments / Gold-Received per-type tab tables. */
    function updateTable(selector, transactions, type) {
        const tbody = $(selector);
        tbody.empty();

        if (!transactions.length) {
            const colspan = type === 'sale' ? '9' : type === 'payment' ? '7' : type === 'gold-received' ? '8' : '9';
            tbody.append(`
                <tr>
                    <td colspan="${colspan}" class="text-center py-4">
                        <i class="fas fa-inbox fa-lg text-gray-400 mb-2"></i>
                        <p class="text-gray-500 text-xs">No ${type} transactions found</p>
                    </td>
                </tr>
            `);
            return;
        }

        let serialNo = 0;
        transactions.forEach((trans) => {
            let rowHtml = '';

            switch (type) {
                case 'booking':
                case 'sale': {
                    const needsCutVow = type === 'sale' && (!trans.rate || parseFloat(trans.rate) <= 0);
                    const actionButton = needsCutVow
                        ? `<button class="cut-vow-btn px-2 py-1 text-xs bg-orange-500 hover:bg-orange-600 text-white rounded transition-colors"
                                   data-trans-id="${trans.id}"
                                   data-weight="${trans.gold_weight || 0}"
                                   title="Cut Vow - Enter Rate">
                                <i class="fas fa-cut mr-1"></i>Cut Vow
                            </button>`
                        : '-';

                    rowHtml = `
                        <tr class="table-row-hover">
                            <td class="px-2 py-2 text-center text-xs text-gray-500 tabular-nums">${++serialNo}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">${formatDate(trans.date_of_transaction)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs">${formatBookingTypeBadge(trans.booking_type)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">${trans.receipt_id || '-'}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatWeight(trans.gold_weight)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatPurity(trans.purity)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatRate(trans.rate)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatAmount(trans.gold_amount)}</td>
                            <td class="px-2 py-2 text-xs text-gray-500">
                                ${trans.payment_amount && trans.payment_amount > 0
                                    ? '<span class="pl-type-badge type-received">Received</span>'
                                    : '-'}
                            </td>
                            <td class="px-2 py-2 text-xs text-gray-500 max-w-[12rem] truncate" title="${escapeHtml(trans.narration)}">${trans.narration || '-'}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-center">${actionButton}</td>
                        </tr>
                    `;
                    break;
                }
                case 'payment':
                    if (trans.payment_amount && trans.payment_amount > 0) {
                        rowHtml = `
                            <tr class="table-row-hover">
                                <td class="px-2 py-2 text-center text-xs text-gray-500 tabular-nums">${++serialNo}</td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">${formatDate(trans.date_of_transaction)}</td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 font-mono">${trans.receipt_id || '-'}</td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right font-medium">${formatAmount(trans.payment_amount)}</td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs">${formatBookingTypeBadge(trans.payment_method)}</td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs">
                                    <span class="pl-type-badge ${trans.payment_type === 'Payment_In' ? 'type-received' : 'type-purchase'}">
                                        ${trans.payment_type === 'Payment_In' ? 'Received' : 'Paid Out'}
                                    </span>
                                </td>
                                <td class="px-2 py-2 text-xs text-gray-500 max-w-[14rem] truncate" title="${escapeHtml(trans.narration)}">${trans.narration || '-'}</td>
                            </tr>
                        `;
                    }
                    break;
                case 'gold-received':
                    rowHtml = `
                        <tr class="table-row-hover">
                            <td class="px-2 py-2 text-center text-xs text-gray-500 tabular-nums">${++serialNo}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">${formatDate(trans.date_of_transaction)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 font-mono">${trans.receipt_id || '-'}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatWeight(trans.gold_weight)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatRate(trans.rate)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right font-medium">${formatAmount(trans.gold_amount)}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs">${formatBookingTypeBadge(trans.payment_method)}</td>
                            <td class="px-2 py-2 text-xs text-gray-500 max-w-[14rem] truncate" title="${escapeHtml(trans.narration)}">${trans.narration || '-'}</td>
                        </tr>
                    `;
                    break;
            }

            tbody.append(rowHtml);
        });
    }

    /** Purchases tab: Cash/Bank sub-tabs sharing the Sales-style line-item layout. */
    function renderPurchaseRows($tb, rows) {
        $tb.empty();
        if (!rows.length) {
            $tb.append('<tr><td colspan="7" class="text-center py-3 text-[11px] text-gray-500">No line items</td></tr>');
            return;
        }
        rows.forEach((r, index) => {
            const w = parseFloat(r.gold_weight);
            const rt = parseFloat(r.rate);
            const amt = parseFloat(r.amount);
            $tb.append(`
                <tr class="table-row-hover">
                    <td class="text-center text-gray-500 tabular-nums">${index + 1}</td>
                    <td class="whitespace-nowrap">${formatDate(r.date_of_transaction)}</td>
                    <td class="font-mono text-[10px]">${r.receipt_id || '–'}</td>
                    <td class="max-w-[8rem] truncate" title="${escapeHtml(r.stock_name)}">${r.stock_name || '–'}</td>
                    <td class="text-right tabular-nums">${isNaN(w) ? '–' : w.toFixed(3)}</td>
                    <td class="text-right">${isNaN(rt) ? '–' : ('₹' + rt.toFixed(2))}</td>
                    <td class="text-right font-medium">${isNaN(amt) ? '–' : ('₹' + formatIndianCurrency(amt))}</td>
                </tr>
            `);
        });
    }

    function purchaseLineChannel(row) {
        const ch = row.payment_method || row.mode || 'Cash';
        return String(ch).toLowerCase() === 'bank' ? 'bank' : 'cash';
    }

    /** Gold Exchange tab: merge received/issued rows for the same transaction into one row. */
    function mergeExchangeByTxn(items) {
        const map = {};
        (items || []).forEach((row) => {
            const tid = String(row.transaction_id);
            if (!map[tid]) {
                map[tid] = {
                    date_of_transaction: row.date_of_transaction,
                    receipt_id: row.receipt_id,
                    received: null,
                    issued: null,
                    payment_amount: 0,
                    payment_method: null
                };
            }
            const m = map[tid];
            if (row.item_type === 'received') m.received = row;
            if (row.item_type === 'issued') m.issued = row;
            if (row.date_of_transaction) m.date_of_transaction = row.date_of_transaction;
            if (row.receipt_id) m.receipt_id = row.receipt_id;
            if (row.rate != null && row.rate !== '') m.rate = row.rate;
            if (row.difference_weight != null && row.difference_weight !== '') m.difference_weight = row.difference_weight;
            const pay = parseFloat(row.payment_amount) || 0;
            if (pay > 0) {
                m.payment_amount = pay;
                m.payment_method = row.payment_method || m.payment_method;
            }
        });
        return Object.values(map).sort((a, b) => String(b.date_of_transaction || '').localeCompare(String(a.date_of_transaction || '')));
    }

    function renderExchangeTable(exchangeLineItems) {
        const exTbody = $('#goldExchangeTbody');
        exTbody.empty();
        const merged = mergeExchangeByTxn(exchangeLineItems);

        if (!merged.length) {
            exTbody.append('<tr><td colspan="12" class="text-center py-1.5 text-[10px] text-gray-500">No gold exchange items for this party</td></tr>');
            return;
        }

        const fmtW = (v) => (v === null || v === undefined || v === '') ? '–' : parseFloat(v).toFixed(3);
        const fmtP = (v) => (v === null || v === undefined || v === '') ? '–' : (parseFloat(v).toFixed(2) + '%');

        merged.forEach((m, index) => {
            const recv = m.received;
            const iss = m.issued;
            const hasR = !!recv;
            const hasI = !!iss;
            const trCls = hasR && hasI ? 'bg-slate-50/80' : hasR ? 'bg-emerald-50/40' : 'bg-red-50/40';
            const pickAmt = (row) => {
                if (!row) return 0;
                let a = parseFloat(row.amount || 0);
                if (!a) a = parseFloat(row.gold_amount || 0);
                return a;
            };
            const amt = pickAmt(recv) || pickAmt(iss);
            const amtStr = isNaN(amt) || amt === 0 ? '–' : ('₹' + formatIndianCurrency(Math.abs(amt)));
            let diffVal = m.difference_weight != null && m.difference_weight !== '' ? parseFloat(m.difference_weight) : NaN;
            if (isNaN(diffVal) && hasI && hasR) {
                const iw = parseFloat(iss.weight);
                const rf = parseFloat(recv.fine_weight);
                if (!isNaN(iw) && !isNaN(rf)) diffVal = iw - rf;
            }
            const diffStr = isNaN(diffVal) ? '–' : diffVal.toFixed(3);
            const diffCls = isNaN(diffVal) ? 'text-gray-400' : diffVal > 0 ? 'text-emerald-700 font-medium' : diffVal < 0 ? 'text-red-700 font-medium' : 'text-gray-700';
            const rateVal = m.rate != null && m.rate !== '' ? parseFloat(m.rate) : NaN;
            const rateStr = isNaN(rateVal) || rateVal === 0 ? '–' : ('₹' + rateVal.toFixed(2));

            const paidAmt = parseFloat(m.payment_amount) || 0;
            const paidStr = paidAmt > 0 ? ('₹' + formatIndianCurrency(paidAmt)) : '–';
            const paidCls = paidAmt > 0 ? 'text-blue-700 font-medium' : 'text-gray-400';
            const paidMethodBadge = paidAmt > 0 ? methodBadge(m.payment_method || 'Cash') : '<span class="text-gray-400">–</span>';
            const flowBadge = hasR && hasI
                ? '<span class="pl-type-badge type-exchange">In+Out</span>'
                : hasR
                    ? '<span class="pl-type-badge type-sale">In</span>'
                    : '<span class="pl-type-badge type-purchase">Out</span>';

            exTbody.append(`
                <tr class="${trCls}">
                    <td class="text-center text-gray-500 tabular-nums">${index + 1}</td>
                    <td class="whitespace-nowrap text-gray-900">${formatDate(m.date_of_transaction)}</td>
                    <td class="font-mono text-[10px]">${m.receipt_id || '–'}</td>
                    <td class="whitespace-nowrap">${flowBadge}</td>
                    <td class="text-right tabular-nums ${hasR ? 'text-emerald-800 font-medium' : 'text-gray-400'}">${hasR ? fmtW(recv.weight) : '–'}</td>
                    <td class="text-right text-[10px]">${hasR ? fmtP(recv.purity) : (hasI ? fmtP(iss.purity) : '–')}</td>
                    <td class="text-right tabular-nums ${hasR ? 'text-emerald-800' : 'text-gray-400'}">${hasR ? fmtW(recv.fine_weight) : '–'}</td>
                    <td class="text-right tabular-nums ${hasI ? 'text-red-800 font-medium' : 'text-gray-400'}">${hasI ? fmtW(iss.weight) : '–'}</td>
                    <td class="text-right tabular-nums text-gray-800">${rateStr}</td>
                    <td class="text-right tabular-nums ${diffCls}">${diffStr}</td>
                    <td class="text-right tabular-nums text-gray-900 font-medium">${amtStr}</td>
                    <td class="text-right tabular-nums ${paidCls}">${paidStr}</td>
                    <td class="whitespace-nowrap">${paidMethodBadge}</td>
                </tr>
            `);
        });
    }

    function renderSalesTables(saleLineItems) {
        const cashTbody = $('#salesCashTbody');
        const bankTbody = $('#salesBankTbody');
        cashTbody.empty();
        bankTbody.empty();

        const cashRows = saleLineItems.filter((r) => saleLineChannel(r) === 'cash');
        const bankRows = saleLineItems.filter((r) => saleLineChannel(r) === 'bank');
        const txnMeta = saleTxnLineSumMap(saleLineItems);

        const fillRows = ($tb, rows, channel) => {
            const emptyColspan = channel === 'bank' ? 9 : 7;
            if (!rows.length) {
                $tb.append(`<tr><td colspan="${emptyColspan}" class="text-center py-3 text-[11px] text-gray-500">No line items</td></tr>`);
                return;
            }
            rows.forEach((r, index) => {
                const w = parseFloat(r.gold_weight);
                const rt = parseFloat(r.rate);
                const split = splitSaleLine(r, channel, txnMeta);
                const snCell = `<td class="text-center text-gray-500 tabular-nums">${index + 1}</td>`;
                const dateCell = `<td class="whitespace-nowrap">${formatDate(r.date_of_transaction)}</td>`;
                const idCell = `<td class="font-mono text-[10px]">${r.receipt_id || '–'}</td>`;
                const itemCell = `<td class="max-w-[8rem] truncate" title="${escapeHtml(r.stock_name)}">${r.stock_name || '–'}</td>`;
                const wtCell = `<td class="text-right tabular-nums">${isNaN(w) ? '–' : w.toFixed(3)}</td>`;
                const rateCell = `<td class="text-right">${isNaN(rt) ? '–' : ('₹' + rt.toFixed(2))}</td>`;

                if (channel === 'bank') {
                    $tb.append(`
                        <tr class="table-row-hover">
                            ${snCell}${dateCell}${idCell}${itemCell}${wtCell}${rateCell}
                            <td class="text-right tabular-nums">${isNaN(split.taxable) ? '–' : ('₹' + formatIndianCurrency(split.taxable))}</td>
                            <td class="text-right tabular-nums">${isNaN(split.gst) ? '–' : ('₹' + formatIndianCurrency(split.gst))}</td>
                            <td class="text-right font-medium">${isNaN(split.total) ? '–' : ('₹' + formatIndianCurrency(split.total))}</td>
                        </tr>
                    `);
                } else {
                    $tb.append(`
                        <tr class="table-row-hover">
                            ${snCell}${dateCell}${idCell}${itemCell}${wtCell}${rateCell}
                            <td class="text-right font-medium">${isNaN(split.total) ? '–' : ('₹' + formatIndianCurrency(split.total))}</td>
                        </tr>
                    `);
                }
            });
        };

        fillRows(cashTbody, cashRows, 'cash');
        fillRows(bankTbody, bankRows, 'bank');
    }

    function renderPurchasesTables(purchaseLineItems) {
        const cashTbody = $('#purchasesCashTbody');
        const bankTbody = $('#purchasesBankTbody');
        if (!cashTbody.length) return;
        const cashRows = purchaseLineItems.filter((r) => purchaseLineChannel(r) === 'cash');
        const bankRows = purchaseLineItems.filter((r) => purchaseLineChannel(r) === 'bank');
        renderPurchaseRows(cashTbody, cashRows);
        renderPurchaseRows(bankTbody, bankRows);
    }

    /** Fills the "All" tab + per-type tabs + Gold Exchange + Sales + Purchases. */
    function renderTransactionTables(data) {
        const transactions = data.transactions || [];
        const paymentTransactions = data.payment_transactions || [];
        const summary = data.summary;
        const exchangeLineItems = data.exchange_line_items || [];
        const saleLineItems = data.sale_line_items || [];
        const purchaseLineItems = data.purchase_line_items || [];

        const allTbody = $('#allTransactionsTable tbody');
        allTbody.empty();

        const bookings = [];
        const goldReceived = [];

        transactions.forEach((trans, index) => {
            allTbody.append(createTransactionRow(trans, index + 1));
            switch (trans.transaction_type) {
                case 'Booking':
                    bookings.push(trans);
                    break;
                case 'Gold_Received':
                    goldReceived.push(trans);
                    break;
            }
        });

        if (summary) {
            const cashBalance = parseFloat(summary.cash_balance || 0);
            const bankBalance = parseFloat(summary.bank_balance || 0);
            const totalBalance = parseFloat(summary.current_balance || 0);
            const balanceClass = totalBalance > 0 ? 'text-red-600 font-bold' : totalBalance < 0 ? 'text-green-600 font-bold' : 'text-gray-600 font-bold';

            allTbody.append(`
                <tr class="bg-yellow-50 border-t-2 border-yellow-200 font-semibold">
                    <td class="px-1 py-2"></td>
                    <td colspan="4" class="px-2 py-2 text-[11px] font-bold text-gray-700 uppercase">
                        <i class="fas fa-calculator mr-1"></i>Total Outstanding Balance
                    </td>
                    <td class="px-2 py-2 text-[11px] text-gray-600 text-right" colspan="3">
                        <div class="flex flex-col items-end gap-0.5">
                            <div class="text-[10px] text-gray-500"><i class="fas fa-wallet text-emerald-600 mr-0.5"></i>Cash: <span class="font-semibold ${cashBalance > 0 ? 'text-red-600' : cashBalance < 0 ? 'text-green-600' : 'text-gray-600'}">${formatRupee(cashBalance)}</span></div>
                            <div class="text-[10px] text-gray-500"><i class="fas fa-university text-indigo-600 mr-0.5"></i>Bank: <span class="font-semibold ${bankBalance > 0 ? 'text-red-600' : bankBalance < 0 ? 'text-green-600' : 'text-gray-600'}">${formatRupee(bankBalance)}</span></div>
                        </div>
                    </td>
                    <td class="px-2 py-2 text-[11px] ${balanceClass} text-right" colspan="4">
                        <div class="flex flex-col items-end">
                            <div class="text-xs font-bold">Total: ${formatRupee(totalBalance)}</div>
                            <div class="text-[10px] ${totalBalance > 0 ? 'text-red-500' : totalBalance < 0 ? 'text-green-500' : 'text-gray-500'}">
                                ${totalBalance > 0 ? '(Due)' : totalBalance < 0 ? '(Credit)' : '(Clear)'}
                            </div>
                        </div>
                    </td>
                </tr>
            `);
        }

        updateTable('#bookingsTable tbody', bookings, 'booking');
        updateTable('#paymentsTable tbody', paymentTransactions, 'payment');
        updateTable('#goldReceivedTable tbody', goldReceived, 'gold-received');
        renderExchangeTable(exchangeLineItems);
        renderSalesTables(saleLineItems);
        renderPurchasesTables(purchaseLineItems);
    }

    window.PL.render = {
        formatIndianCurrency,
        formatDate,
        formatTime,
        formatRupee,
        getTransactionTypeClass,
        getTransactionTypeIcon,
        renderSummaryCards,
        renderTransactionTables,
        createTransactionRow,
        updateTable
    };
})();

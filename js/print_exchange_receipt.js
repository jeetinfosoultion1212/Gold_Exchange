// Gold Exchange - Print Receipt Function
// Add this to the end of gold_exchange.js

/**
 * Print exchange receipt (thermal printer compatible)
 */
function printExchangeReceipt(exchangeData, companyName) {
    const transactionDate = exchangeData.date_of_transaction
        ? new Date(exchangeData.date_of_transaction).toLocaleString('en-IN')
        : new Date().toLocaleString('en-IN');

    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Receipt - ${exchangeData.receipt_id}</title>
            <style>
                @page {
                    size: 80mm auto;
                    margin: 5mm;
                }
                
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Courier New', monospace;
                    font-size: 11pt;
                    width: 70mm;
                    margin: 0 auto;
                    padding: 5mm;
                }
                
                .receipt-header {
                    text-align: center;
                    border-bottom: 1px dashed #000;
                    padding-bottom: 8px;
                    margin-bottom: 8px;
                }
                
                .company-name {
                    font-size: 14pt;
                    font-weight: bold;
                    margin-bottom: 3px;
                }
                
                .receipt-title {
                    font-size: 10pt;
                    color: #666;
                }
                
                .receipt-body {
                    font-size: 10pt;
                    line-height: 1.4;
                }
                
                .receipt-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 4px;
                }
                
                .receipt-label {
                    color: #333;
                }
                
                .receipt-value {
                    font-weight: bold;
                }
                
                .receipt-divider {
                    border-top: 1px dashed #000;
                    margin: 8px 0;
                }
                
                .receipt-section {
                    background: #f5f5f5;
                    padding: 8px;
                    margin: 8px 0;
                    border-radius: 3px;
                }
                
                .receipt-footer {
                    text-align: center;
                    border-top: 1px dashed #000;
                    padding-top: 8px;
                    margin-top: 8px;
                    font-size: 9pt;
                    color: #666;
                }
                
                @media print {
                    body {
                        width: 70mm;
                    }
                    @page {
                        margin: 0;
                    }
                }
            </style>
        </head>
        <body>
            <div class="receipt-header">
                <div class="company-name">${companyName}</div>
                <div class="receipt-title">GOLD EXCHANGE RECEIPT</div>
            </div>
            
            <div class="receipt-body">
                <div class="receipt-row">
                    <span class="receipt-label">Receipt ID:</span>
                    <span class="receipt-value">${exchangeData.receipt_id}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Date:</span>
                    <span>${transactionDate}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Party:</span>
                    <span class="receipt-value">${exchangeData.party_name}</span>
                </div>
                
                <div class="receipt-divider"></div>
                
                <div class="receipt-row">
                    <span class="receipt-label">Received Weight:</span>
                    <span class="receipt-value">${parseFloat(exchangeData.received_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Purity:</span>
                    <span>${parseFloat(exchangeData.purity).toFixed(2)}%</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Fine Weight:</span>
                    <span>${parseFloat(exchangeData.fine_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Issue Weight:</span>
                    <span>${parseFloat(exchangeData.issue_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Difference:</span>
                    <span class="receipt-value">${parseFloat(exchangeData.difference_weight).toFixed(3)} g</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Rate:</span>
                    <span>₹${parseFloat(exchangeData.rate).toLocaleString('en-IN', { minimumFractionDigits: 2 })}/g</span>
                </div>
                
                <div class="receipt-divider"></div>
                
                <div class="receipt-section">
                    <div class="receipt-row">
                        <span class="receipt-label">Amount:</span>
                        <span class="receipt-value" style="font-size: 12pt;">₹${parseFloat(exchangeData.amount).toLocaleString('en-IN')}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">${exchangeData.payment_type === 'Payment_In' ? 'Received' : 'Paid'}:</span>
                        <span style="color: ${exchangeData.payment_type === 'Payment_In' ? '#28a745' : '#dc3545'};">₹${parseFloat(exchangeData.payment_amount).toLocaleString('en-IN')}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Payment Method:</span>
                        <span>${exchangeData.payment_method}</span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Status:</span>
                        <span>${exchangeData.payment_status}</span>
                    </div>
                </div>
                
                ${exchangeData.narration ? `
                <div class="receipt-divider"></div>
                <div>
                    <div style="font-size: 9pt; color: #666; margin-bottom: 2px;">Note:</div>
                    <div style="font-size: 9pt;">${exchangeData.narration}</div>
                </div>
                ` : ''}
            </div>
            
            <div class="receipt-footer">
                Thank you for your business!
            </div>
        </body>
        </html>
    `;

    const printWindow = window.open('', '_blank', 'width=300,height=600');
    printWindow.document.write(printContent);
    printWindow.document.close();

    setTimeout(() => {
        printWindow.focus();
        printWindow.print();
    }, 250);
}

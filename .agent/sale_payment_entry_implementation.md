# Sale Payment Entry Implementation

## Summary
Implemented separate payment entry creation for sales transactions in `sell_gold.php`, following the same pattern as `gold_exchange.php`.

## Changes Made

### 1. **save_sell Case** (Lines 237-408)
**Before:** 
- Single transaction entry containing both sale and payment information
- Payment details stored in the same row as the sale

**After:**
- **Sale Transaction**: Records the sale details (gold weight, purity, rate, amount)
- **Received Transaction** (if payment > 0): Separate entry for payment received
  - Receipt ID: `RCV-{sale_receipt_id}-{random}`
  - Transaction Type: `'Received'`
  - Payment Type: `'Payment_In'`
  - Narration: `"Payment received for Sale {receipt_id}"`
  - Updates account balance (Cash/Bank)

### 2. **delete_sale Case** (Lines 619-726)
**Added:**
- Reverts account balances for linked "Received" transactions before deletion
- Includes "Received" in the transaction types to delete
- Follows the same reversal pattern as `gold_exchange.php`

### 3. **update_sale Case** (Lines 529-641)
**Added:**
- Reverts account balances for linked "Received" transactions before deletion
- Properly cleans up old payment entries when updating a sale

## Benefits

1. **Better Accounting**: Clear separation between sales and payments
2. **Consistency**: Matches the pattern used in `gold_exchange.php`
3. **Flexibility**: Allows for partial payments and multiple payment methods
4. **Audit Trail**: Separate entries make it easier to track payment history
5. **Reporting**: Easier to generate payment reports and outstanding balances

## Database Impact

### Transactions Table
Each sale with payment now creates **2 entries**:

#### Entry 1: Sale Transaction
```
transaction_type: 'Sale'
gold_weight: {weight}
gold_amount: {amount}
(no payment_amount, payment_method in this row)
```

#### Entry 2: Received Transaction (if payment > 0)
```
transaction_type: 'Received'
payment_type: 'Payment_In'
payment_method: 'Cash' or 'Bank' etc.
payment_amount: {amount_paid}
narration: "Payment received for Sale {receipt_id}"
```

## Testing Checklist

- [ ] Create a new sale with payment - verify 2 transactions created
- [ ] Create a new sale without payment - verify only 1 transaction created
- [ ] Update an existing sale - verify old payment entries are properly removed
- [ ] Delete a sale - verify both sale and payment entries are removed
- [ ] Verify account balances are correctly updated
- [ ] Verify party balances are correctly updated
- [ ] Check that payment reports show the received entries

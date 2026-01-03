# Inline Outstanding Balance - sell_gold.php

## Changes Made

Applied the same inline outstanding balance display to `sell_gold.php` that was previously implemented in `gold_exchange.php`.

### **HTML Changes** (Lines 1660-1682)

#### Before:
```html
<label class="block text-xs font-bold text-gray-700 mb-1">Party Name</label>
<!-- Outstanding balance shown in separate box below -->
<div id="outstandingBalanceAlert" class="hidden mt-2 bg-yellow-50...">
```

#### After:
```html
<label class="block text-xs font-bold text-gray-700 mb-1 flex items-center justify-between">
    <span>Party Name</span>
    <!-- Outstanding Balance Inline -->
    <span id="outstandingBalanceInline" class="hidden text-xs font-semibold">
        <span class="text-orange-600">Outstanding:</span>
        <span class="text-red-600 ml-1" id="outstandingAmountInline">₹0.00</span>
        <span class="text-yellow-700 ml-1" id="outstandingGoldInline">0.000g</span>
    </span>
</label>
```

### **JavaScript Changes**

#### 1. Display Outstanding Balance (Lines 2677-2690)
Added code in the `selectParty` function to display outstanding balance inline:

```javascript
// Display outstanding balance inline
const dueAmount = parseFloat(response.current_balance) || 0;
const dueGold = parseFloat(response.current_gold_balance) || 0;

if (dueAmount > 0 || dueGold > 0) {
    $('#outstandingAmountInline').text('₹' + dueAmount.toFixed(2));
    $('#outstandingGoldInline').text(dueGold.toFixed(3) + 'g');
    $('#outstandingBalanceInline').removeClass('hidden');
} else {
    $('#outstandingBalanceInline').addClass('hidden');
}
```

#### 2. Hide When Input Cleared (Lines 2381-2389)
Added code to hide outstanding balance when party input is cleared:

```javascript
} else {
    $('#partyList').addClass('hidden');
    partyListVisible = false;
    selectedPartyName = '';
    $('#partyId').val('');
    updatePartySelectionStatus(false);
    // Hide outstanding balance
    $('#outstandingBalanceInline').addClass('hidden');
}
```

## Visual Result

**Party Name Label:**
```
Party Name                Outstanding: ₹14.00 0.133g
[giriraj jewellers_________________________]
```

## Benefits

✅ **Consistent UI**: Matches the gold_exchange.php design
✅ **Space Efficient**: No separate box taking up vertical space
✅ **Better Visibility**: Outstanding balance is immediately visible with the party name
✅ **Clean Design**: Professional and organized layout

## Files Modified

1. **c:\xampp\htdocs\Gold_Exchange\sell_gold.php**
   - HTML: Lines 1660-1682 (Party Name field structure)
   - JavaScript: Lines 2677-2690 (Display logic)
   - JavaScript: Lines 2381-2389 (Hide logic)

## Date Completed
January 1, 2026

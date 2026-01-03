# Fix Summary: Outstanding Balance Display

## Objective
The goal was to display the party's outstanding balance (Amount and Gold) inline with the Party Name label on both `gold_exchange.php` and `sell_gold.php` pages.

## Changes Implemented

### 1. Gold Exchange Page (`gold_exchange.php`)
- **UI Update**: 
  - Adjusted grid layout: Receipt ID (3 cols), Date (3 cols), Party Name (6 cols).
  - Moved outstanding balance display from a separate alert box to an inline span next to the "Party Name" label.
  - Structure: `Party Name | Outstanding: ₹XX.XX X.XXXg`
- **JavaScript Update**:
  - Updated `loadPartyDues` function to target the new inline elements (`#dueAmountValueInline`, `#dueGoldValueInline`).
  - Hides the outstanding balance if the party name input is cleared.
  - Changed "Print Receipt" behavior to open in the same tab (`_self`) for easier printing.

### 2. Sell Gold Page (`sell_gold.php`)
- **UI Update**:
  - Updated HTML to match the `gold_exchange.php` inline layout for the Party Name label.
  - Removed the old separate alert box.
- **Backend API Update**:
  - Modified `get_party_gold_balance` action in `sell_gold.php` to fetch `current_balance` and `current_gold_balance` from the `parties` table.
  - Included these fields in the JSON response.
- **JavaScript Update**:
  - Updated `selectParty` function to read `current_balance` and `current_gold_balance` from the API response.
  - Added logic to populate the inline display elements (`#outstandingAmountInline`, `#outstandingGoldInline`).
  - Added logic to hide the display when the party input is cleared.

## Result
Both pages now consistently display the party's outstanding balance inline, providing a cleaner and more space-efficient interface.

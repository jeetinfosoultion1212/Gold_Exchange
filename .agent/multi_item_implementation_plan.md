# Multi-Item Gold Exchange Backend Implementation Plan

## Database Schema Required

### 1. Create `exchange_items` table
```sql
CREATE TABLE IF NOT EXISTS `exchange_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_type` enum('received','issued') NOT NULL,
  `weight` decimal(10,3) DEFAULT NULL,
  `purity` decimal(5,2) DEFAULT NULL,
  `fine_weight` decimal(10,3) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `exchange_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Backend Functions Needed

### 1. save_transaction (UPDATE)
- Accept received_items array from frontend
- Calculate totals from items
- Save items to exchange_items table
- Keep backward compatibility with transactions table

### 2. get_exchange_by_receipt_id (UPDATE)
- Fetch main transaction
- Fetch all exchange_items for that transaction
- Return both in response

### 3. delete_transaction (NEW)
- Delete transaction (cascade will delete exchange_items)
- Revert stock changes
- Revert party balance changes

## Frontend JavaScript Needed

### 1. Form Submission
- Collect all rows from received items table
- Package as array
- Send to backend

### 2. Load for Edit
- Populate main fields
- Dynamically create rows for each received item
- Attach calculation listeners

### 3. Delete Handler
- Confirm and send delete request
- Refresh page or clear form

## Implementation Order
1. Database table creation
2. Update save_transaction to handle items array
3. Update get_exchange_by_receipt_id to return items
4. Add delete_transaction handler
5. Update frontend submission
6. Update frontend load for edit
7. Add delete button handler

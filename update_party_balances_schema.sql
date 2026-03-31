-- Add cash_balance and bank_balance columns to parties table
-- This will track separate cash and bank balances for each party

-- Add the new columns
ALTER TABLE `parties`
ADD COLUMN `cash_balance` DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Cash balance for this party',
ADD COLUMN `bank_balance` DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Bank balance for this party (includes UPI, Cheque, etc.)';

-- Update existing records to split current_balance based on transaction history
-- This will migrate existing data by categorizing payments as cash or bank based on payment_method
UPDATE parties p
SET 
    cash_balance = (
        SELECT COALESCE(SUM(
            CASE 
                WHEN t.transaction_type = 'Booking' THEN -t.gold_amount
                WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' AND t.payment_method = 'Cash' THEN t.payment_amount
                ELSE 0 
            END
        ), 0)
        FROM transactions t
        WHERE t.party_id = p.id AND (t.booking_type = 'Cash' OR t.payment_method = 'Cash')
    ),
    bank_balance = (
        SELECT COALESCE(SUM(
            CASE 
                WHEN t.transaction_type = 'Booking' THEN -t.gold_amount
                WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' AND t.payment_method IN ('Bank', 'UPI', 'Cheque', 'Card') THEN t.payment_amount
                ELSE 0 
            END
        ), 0)
        FROM transactions t
        WHERE t.party_id = p.id AND (t.booking_type = 'Bank' OR t.payment_method IN ('Bank', 'UPI', 'Cheque', 'Card'))
    );

-- Add index for better performance
CREATE INDEX `idx_parties_cash_balance` ON `parties` (`cash_balance`);
CREATE INDEX `idx_parties_bank_balance` ON `parties` (`bank_balance`);

-- Update the transactions table to simplify payment_method enum
-- First, consolidate all bank-related methods to 'Bank'
UPDATE transactions 
SET payment_method = 'Bank' 
WHERE payment_method IN ('UPI', 'Cheque', 'Card');

-- Now alter the enum to only have Cash and Bank
ALTER TABLE `transactions` 
MODIFY COLUMN `payment_method` ENUM('Cash','Bank') DEFAULT 'Cash' 
COMMENT 'Simplified payment method: Cash or Bank';







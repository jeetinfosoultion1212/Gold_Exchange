-- One-time fix for an EXISTING desktop database (gold_exchange).
-- Run after upgrading the app if you see enum / created_by errors.
-- Fresh installs use database_schamas.sql and do not need this file.

USE `gold_exchange`;

ALTER TABLE `transactions`
  MODIFY COLUMN `payment_status` ENUM('Paid','Partial','Due','Pending') NOT NULL DEFAULT 'Pending',
  MODIFY COLUMN `transaction_type` ENUM(
    'Booking','Sale','Purchase','Received','Payment','Gold_Received','Exchange',
    'Personal_Expense','Stock_Addition','Stock_Reset','Logo_Marking'
  ) NOT NULL,
  MODIFY COLUMN `created_by` INT(11) NOT NULL DEFAULT 0;

ALTER TABLE `gold_purchase`
  MODIFY COLUMN `payment_status` ENUM('Paid','Partial','Due','Pending') NOT NULL DEFAULT 'Paid';

-- Only if this legacy table exists on your install:
-- ALTER TABLE `gold_sales`
--   MODIFY COLUMN `payment_status` ENUM('Paid','Partial','Due','Pending') NOT NULL DEFAULT 'Pending';

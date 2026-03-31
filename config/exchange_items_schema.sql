-- Multi-Item Gold Exchange Database Schema
-- Run this to add support for storing multiple received items per transaction

CREATE TABLE IF NOT EXISTS `exchange_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_type` enum('received','issued') NOT NULL COMMENT 'received = old gold items, issued = fine gold given',
  `weight` decimal(10,3) DEFAULT NULL COMMENT 'Weight in grams',
  `purity` decimal(5,2) DEFAULT NULL COMMENT 'Purity percentage',
  `fine_weight` decimal(10,3) DEFAULT NULL COMMENT 'Calculated fine gold weight',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `exchange_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores individual items for multi-item gold exchange transactions';

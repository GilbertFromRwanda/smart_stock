

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `sale_type` ENUM('bulk','retail') DEFAULT NULL AFTER `loan_date`;
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `unit_price` DECIMAL(10,2) DEFAULT NULL AFTER `sale_type`;



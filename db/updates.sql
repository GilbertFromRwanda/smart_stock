-- 2026-05-21: idempotent migrations (safe to re-run)

-- ── loans: new columns ────────────────────────────────────────────────────────
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `retail_id`    int(11)      DEFAULT NULL;
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `bulk_id`      int(11)      DEFAULT NULL;
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `given_by`     int(11)      DEFAULT NULL;
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `product_name` varchar(200) DEFAULT NULL;
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `external_id`  int(11)      DEFAULT NULL;

-- ── loans: indexes ────────────────────────────────────────────────────────────
ALTER TABLE `loans` ADD INDEX IF NOT EXISTS `idx_retail_id` (`retail_id`);
ALTER TABLE `loans` ADD INDEX IF NOT EXISTS `idx_bulk_id`   (`bulk_id`);
ALTER TABLE `loans` ADD INDEX IF NOT EXISTS `idx_given_by`  (`given_by`);

-- ── loans: foreign keys (drop-then-add = idempotent) ─────────────────────────
ALTER TABLE `loans` DROP FOREIGN KEY IF EXISTS `loans_ibfk_retail`;
ALTER TABLE `loans` ADD CONSTRAINT `loans_ibfk_retail`   FOREIGN KEY (`retail_id`) REFERENCES `sales_retail` (`id`);

ALTER TABLE `loans` DROP FOREIGN KEY IF EXISTS `loans_ibfk_bulk`;
ALTER TABLE `loans` ADD CONSTRAINT `loans_ibfk_bulk`     FOREIGN KEY (`bulk_id`)   REFERENCES `sales_bulk`   (`id`);

ALTER TABLE `loans` DROP FOREIGN KEY IF EXISTS `loans_ibfk_given_by`;
ALTER TABLE `loans` ADD CONSTRAINT `loans_ibfk_given_by` FOREIGN KEY (`given_by`)  REFERENCES `users`        (`id`);

-- ── loan_payments: received_by ────────────────────────────────────────────────
ALTER TABLE `loan_payments` ADD COLUMN IF NOT EXISTS `received_by` int(11) DEFAULT NULL;
ALTER TABLE `loan_payments` ADD INDEX IF NOT EXISTS `idx_received_by` (`received_by`);
ALTER TABLE `loan_payments` DROP FOREIGN KEY IF EXISTS `lp_ibfk_received_by`;
ALTER TABLE `loan_payments` ADD CONSTRAINT `lp_ibfk_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`);

-- ── sales_bulk: has_loan + amount ─────────────────────────────────────────────
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `has_loan` tinyint(1)    NOT NULL DEFAULT 0;
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `amount`   decimal(10,2) DEFAULT NULL;

-- ── sales_retail: has_loan + amount ──────────────────────────────────────────
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `has_loan` tinyint(1)    NOT NULL DEFAULT 0;
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `amount`   decimal(10,2) DEFAULT NULL;

-- ── loan_clients: aggregate registry ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `loan_clients` (
  `id`            int(11)       NOT NULL AUTO_INCREMENT,
  `name`          varchar(100)  NOT NULL,
  `phone`         varchar(20)   NOT NULL DEFAULT '',
  `total_loans`   int(11)       NOT NULL DEFAULT 0,
  `paid_amount`   decimal(10,2) NOT NULL DEFAULT 0.00,
  `unpaid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at`    timestamp     NOT NULL DEFAULT current_timestamp(),
  `updated_at`    timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_phone` (`name`, `phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Seed loan_clients from existing loans ────────────────────────────────────
INSERT IGNORE INTO `loan_clients` (name, phone, total_loans, paid_amount, unpaid_amount)
SELECT
    l.client                                                  AS name,
    COALESCE(l.phone, '')                                     AS phone,
    COUNT(DISTINCT l.id)                                      AS total_loans,
    COALESCE(SUM(lp_s.paid), 0)                               AS paid_amount,
    COALESCE(SUM(l.amount), 0) - COALESCE(SUM(lp_s.paid), 0) AS unpaid_amount
FROM loans l
LEFT JOIN (
    SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id
) lp_s ON lp_s.loan_id = l.id
GROUP BY l.client, COALESCE(l.phone, '');

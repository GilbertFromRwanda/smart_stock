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

-- ── purchase_levels: multi-level packaging chain ─────────────────────────────
CREATE TABLE IF NOT EXISTS `purchase_levels` (
  `id`             int(11)       NOT NULL AUTO_INCREMENT,
  `purchase_id`    int(11)       NOT NULL,
  `level_order`    tinyint(4)    NOT NULL,
  `level_name`     varchar(100)  NOT NULL,
  `qty_per_parent` int(11)       NOT NULL DEFAULT 1,
  `selling_price`  decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_id` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── sales_bulk: split payments + level + seller + refund ─────────────────────
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `cash_amount`    decimal(10,2) NOT NULL DEFAULT 0;
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `momo_amount`    decimal(10,2) NOT NULL DEFAULT 0;
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `loan_amount`    decimal(10,2) NOT NULL DEFAULT 0;
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `level_divisor`  int(11)       NOT NULL DEFAULT 1;
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `sold_by`        int(11)       DEFAULT NULL;
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `refunded`       tinyint(1)    NOT NULL DEFAULT 0;

-- ── sales_retail: split payments + seller + refund ───────────────────────────
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `cash_amount`  decimal(10,2) NOT NULL DEFAULT 0;
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `momo_amount`  decimal(10,2) NOT NULL DEFAULT 0;
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `loan_amount`  decimal(10,2) NOT NULL DEFAULT 0;
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `sold_by`      int(11)       DEFAULT NULL;
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `refunded`     tinyint(1)    NOT NULL DEFAULT 0;

-- ── loans: client_id link ─────────────────────────────────────────────────────
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `client_id` int(11) DEFAULT NULL;
ALTER TABLE `loans` ADD INDEX  IF NOT EXISTS `idx_client_id` (`client_id`);

-- ── loans: backfill client_id from loan_clients (for rows inserted before client_id was tracked)
UPDATE `loans` l
JOIN `loan_clients` lc
    ON  lc.`name`  = l.`client`
    AND (lc.`phone` = l.`phone` OR (lc.`phone` = '' AND (l.`phone` IS NULL OR l.`phone` = '')))
SET l.`client_id` = lc.`id`
WHERE l.`client_id` IS NULL;

-- ── product_owners: external sale owners ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `product_owners` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `phone`      varchar(20)  DEFAULT NULL,
  `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── refunds: return / loss records ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `refunds` (
  `id`            int(11)                              NOT NULL AUTO_INCREMENT,
  `sale_type`     enum('bulk','retail','external')     NOT NULL,
  `sale_id`       int(11)                              NOT NULL,
  `product_id`    int(11)                              DEFAULT NULL,
  `product_name`  varchar(200)                         DEFAULT NULL,
  `quantity`      int(11)                              NOT NULL DEFAULT 0,
  `refund_amount` decimal(10,2)                        NOT NULL DEFAULT 0.00,
  `loss_amount`   decimal(10,2)                        DEFAULT NULL,
  `reason`        text                                 DEFAULT NULL,
  `back_to_stock` tinyint(1)                           NOT NULL DEFAULT 0,
  `refund_date`   date                                 NOT NULL,
  `processed_by`  int(11)                              DEFAULT NULL,
  `created_at`    timestamp                            NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
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

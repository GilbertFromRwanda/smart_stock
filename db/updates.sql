-- Notes: personal notes per user with pin support
CREATE TABLE IF NOT EXISTS `notes` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT DEFAULT NULL,
    `user_id`    INT NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `note`       TEXT NOT NULL,
    `is_pinned`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_notes_company` (`company_id`),
    INDEX `idx_notes_user` (`user_id`)
) ;
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL DEFAULT 0,
    username    VARCHAR(100) NOT NULL DEFAULT '',
    action      VARCHAR(30)  NOT NULL,
    module      VARCHAR(30)  NOT NULL,
    record_id   INT          NOT NULL DEFAULT 0,
    description TEXT,
    old_value   TEXT,
    new_value   TEXT,
    ip_address  VARCHAR(45),
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_action  (action),
    INDEX idx_module  (module)
);

-- Add old_value / new_value to existing audit_log tables
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS old_value TEXT NULL AFTER description;
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS new_value TEXT NULL AFTER old_value;

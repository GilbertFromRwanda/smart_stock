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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

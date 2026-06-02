CREATE TABLE IF NOT EXISTS subscription (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    license_key  VARCHAR(35)  NOT NULL,
    expires_at   DATE         NOT NULL,
    signature    VARCHAR(64)  NULL,
    activated_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    activated_by INT,
    notes        VARCHAR(255)
);

-- Add signature column to existing subscription table (run once if table already exists)
ALTER TABLE subscription ADD COLUMN IF NOT EXISTS signature VARCHAR(64) NULL AFTER expires_at;
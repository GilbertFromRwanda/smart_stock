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

-- FIFO stock value cache
CREATE TABLE IF NOT EXISTS stock_value_cache (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT          NOT NULL,
    cost_wh    DECIMAL(12,2) NOT NULL DEFAULT 0,
    cost_rt    DECIMAL(12,2) NOT NULL DEFAULT 0,
    sell_wh    DECIMAL(12,2) NOT NULL DEFAULT 0,
    sell_rt    DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product (product_id)
);

-- Wishlist: products clients want that are not yet in stock
CREATE TABLE IF NOT EXISTS wishlist (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(255) NOT NULL,
    client_count INT          NOT NULL DEFAULT 1,
    status       ENUM('pending','purchased') NOT NULL DEFAULT 'pending',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    purchased_at DATETIME NULL
);
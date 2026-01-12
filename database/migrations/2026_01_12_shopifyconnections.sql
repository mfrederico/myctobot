-- Shopify Multi-Store Connections
-- Allows multiple Shopify stores per workspace with repo linking

CREATE TABLE IF NOT EXISTS `shopifyconnections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `created_by_member_id` INT NOT NULL,
    `created_by_name` VARCHAR(255),
    `connection_name` VARCHAR(255),              -- Optional friendly name
    `shop_domain` VARCHAR(255) NOT NULL,         -- e.g., mystore.myshopify.com
    `access_token` TEXT NOT NULL,                -- Encrypted shpat_* token
    `shop_name` VARCHAR(255),                    -- Store display name (from API)
    `shop_email` VARCHAR(255),                   -- Store owner email
    `storefront_password` TEXT,                  -- Encrypted, for password-protected stores
    `verify_with_playwright` TINYINT(1) DEFAULT 0,
    `enabled` TINYINT(1) DEFAULT 1,
    `repo_connection_id` INT DEFAULT NULL,       -- FK to repoconnections (optional)
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_shop_domain` (`shop_domain`),
    INDEX `idx_enabled` (`enabled`),
    INDEX `idx_repo` (`repo_connection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: FK to repoconnections not enforced due to tenant schema flexibility
-- The application code handles the relationship

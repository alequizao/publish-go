-- Cupons de desconto e promoções da loja.
CREATE TABLE IF NOT EXISTS coupons (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,
    code        VARCHAR(40) NOT NULL,
    type        ENUM('percent','fixed','free_shipping') NOT NULL DEFAULT 'percent',
    value       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    min_order   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_uses    INT UNSIGNED DEFAULT NULL,
    uses        INT UNSIGNED NOT NULL DEFAULT 0,
    starts_at   DATETIME DEFAULT NULL,
    expires_at  DATETIME DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_coupon_code (company_id, code),
    CONSTRAINT fk_coupon_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,
    title       VARCHAR(150) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    type        ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    value       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    scope       ENUM('all','category','product') NOT NULL DEFAULT 'all',
    scope_id    INT UNSIGNED DEFAULT NULL,
    banner_url  VARCHAR(255) DEFAULT NULL,
    starts_at   DATETIME DEFAULT NULL,
    expires_at  DATETIME DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_promo_company (company_id, is_active),
    CONSTRAINT fk_promo_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

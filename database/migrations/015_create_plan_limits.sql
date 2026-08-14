-- Limites por plano, configuráveis pelo super-admin do SaaS. (0 = ilimitado)
CREATE TABLE IF NOT EXISTS plan_limits (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan            ENUM('free','pro','enterprise') NOT NULL,
    label           VARCHAR(60) NOT NULL DEFAULT '',
    max_products    INT NOT NULL DEFAULT 0,
    max_categories  INT NOT NULL DEFAULT 0,
    max_couriers    INT NOT NULL DEFAULT 0,
    monthly_orders  INT NOT NULL DEFAULT 0,
    allow_storefront  TINYINT(1) NOT NULL DEFAULT 1,
    allow_coupons     TINYINT(1) NOT NULL DEFAULT 0,
    allow_promotions  TINYINT(1) NOT NULL DEFAULT 0,
    allow_stock       TINYINT(1) NOT NULL DEFAULT 0,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_plan (plan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO plan_limits (plan, label, max_products, max_categories, max_couriers, monthly_orders, allow_storefront, allow_coupons, allow_promotions, allow_stock) VALUES
('free', 'Free', 20, 3, 2, 100, 1, 0, 0, 0),
('pro', 'Pro', 200, 20, 15, 3000, 1, 1, 1, 1),
('enterprise', 'Enterprise', 0, 0, 0, 0, 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE plan = VALUES(plan);

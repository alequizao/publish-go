-- Empresas (tenants) — base do sistema whitelabel.
CREATE TABLE IF NOT EXISTS companies (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(150) NOT NULL,
    slug            VARCHAR(80)  NOT NULL,
    subdomain       VARCHAR(80)  DEFAULT NULL,
    document        VARCHAR(20)  DEFAULT NULL,
    phone           VARCHAR(20)  DEFAULT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    logo_url        VARCHAR(255) DEFAULT NULL,
    primary_color   VARCHAR(9)   NOT NULL DEFAULT '#2563eb',
    accent_color    VARCHAR(9)   NOT NULL DEFAULT '#38bdf8',
    theme           ENUM('light','dark','system') NOT NULL DEFAULT 'system',
    plan            ENUM('free','pro','enterprise') NOT NULL DEFAULT 'pro',
    -- Configuração operacional/financeira (whitelabel)
    address         VARCHAR(255) DEFAULT NULL,
    lat             DECIMAL(10,7) DEFAULT NULL,
    lng             DECIMAL(10,7) DEFAULT NULL,
    delivery_fee    DECIMAL(10,2) NOT NULL DEFAULT 6.00,
    courier_commission DECIMAL(5,2) NOT NULL DEFAULT 80.00,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_company_slug (slug),
    UNIQUE KEY uniq_company_subdomain (subdomain),
    KEY idx_company_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

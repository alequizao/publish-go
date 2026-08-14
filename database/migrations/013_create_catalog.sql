-- Catálogo: categorias, produtos, complementos.
CREATE TABLE IF NOT EXISTS product_categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cat_company (company_id, is_active, position),
    CONSTRAINT fk_cat_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    name        VARCHAR(150) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    image_url   VARCHAR(255) DEFAULT NULL,
    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    promo_price DECIMAL(10,2) DEFAULT NULL,
    sku         VARCHAR(60) DEFAULT NULL,
    unit        VARCHAR(20) DEFAULT NULL,
    track_stock TINYINT(1) NOT NULL DEFAULT 0,
    stock_qty   INT NOT NULL DEFAULT 0,
    stock_alert INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_prod_company (company_id, is_active),
    KEY idx_prod_cat (category_id),
    CONSTRAINT fk_prod_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES product_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_option_groups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    min_select  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_select  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_optg_product (product_id),
    CONSTRAINT fk_optg_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_optg_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_options (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id    INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_opt_group (group_id),
    CONSTRAINT fk_opt_group FOREIGN KEY (group_id) REFERENCES product_option_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

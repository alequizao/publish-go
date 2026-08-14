-- Chat entre estabelecimento e motoboy (por entrega).
CREATE TABLE IF NOT EXISTS messages (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,
    delivery_id INT UNSIGNED NOT NULL,
    sender      ENUM('establishment','courier') NOT NULL,
    body        VARCHAR(1000) NOT NULL,
    read_at     DATETIME DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_msg_delivery (delivery_id, id),
    KEY idx_msg_company (company_id),
    CONSTRAINT fk_msg_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

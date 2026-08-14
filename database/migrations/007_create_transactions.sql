-- Transações financeiras (taxas, repasses, comissões, saldos).
CREATE TABLE IF NOT EXISTS transactions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,
    courier_id  INT UNSIGNED DEFAULT NULL,
    order_id    INT UNSIGNED DEFAULT NULL,
    delivery_id INT UNSIGNED DEFAULT NULL,
    type        ENUM('delivery_fee','courier_payout','commission','adjustment','withdrawal')
                NOT NULL,
    direction   ENUM('credit','debit') NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tx_company (company_id, created_at),
    KEY idx_tx_courier (courier_id, created_at),
    CONSTRAINT fk_tx_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_courier FOREIGN KEY (courier_id) REFERENCES couriers (id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entregas (vínculo pedido <-> motoboy + ciclo de vida da corrida).
CREATE TABLE IF NOT EXISTS deliveries (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id    INT UNSIGNED NOT NULL,
    order_id      INT UNSIGNED NOT NULL,
    courier_id    INT UNSIGNED DEFAULT NULL,
    dispatch_type ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    status        ENUM('pending','assigned','accepted','rejected','picked','delivered','canceled')
                  NOT NULL DEFAULT 'pending',
    distance_km   DECIMAL(8,3) DEFAULT NULL,
    eta_minutes   SMALLINT UNSIGNED DEFAULT NULL,
    courier_fee   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    proof_url     VARCHAR(255) DEFAULT NULL,
    signature_url VARCHAR(255) DEFAULT NULL,
    assigned_at   DATETIME DEFAULT NULL,
    accepted_at   DATETIME DEFAULT NULL,
    picked_at     DATETIME DEFAULT NULL,
    delivered_at  DATETIME DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_delivery_company (company_id),
    KEY idx_delivery_order (order_id),
    KEY idx_delivery_courier (courier_id, status),
    CONSTRAINT fk_delivery_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_courier FOREIGN KEY (courier_id) REFERENCES couriers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

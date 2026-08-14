-- Auditoria e logs de acesso/ações.
CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED DEFAULT NULL,
    user_id     INT UNSIGNED DEFAULT NULL,
    action      VARCHAR(80) NOT NULL,
    entity      VARCHAR(60) DEFAULT NULL,
    entity_id   INT UNSIGNED DEFAULT NULL,
    ip          VARCHAR(45) DEFAULT NULL,
    meta        JSON DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_company (company_id, created_at),
    KEY idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

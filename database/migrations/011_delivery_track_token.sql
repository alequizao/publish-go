-- Token público para o cliente acompanhar a entrega (link compartilhável).
ALTER TABLE deliveries
    ADD COLUMN track_token VARCHAR(40) DEFAULT NULL AFTER status,
    ADD COLUMN track_expires_at DATETIME DEFAULT NULL AFTER track_token,
    ADD UNIQUE KEY uniq_track_token (track_token);

-- Comprovante de entrega (Proof of Delivery): quem recebeu, documento, foto e geolocalização.
ALTER TABLE deliveries
    ADD COLUMN receiver_name     VARCHAR(150) DEFAULT NULL AFTER signature_url,
    ADD COLUMN receiver_document VARCHAR(30)  DEFAULT NULL AFTER receiver_name,
    ADD COLUMN proof_lat         DECIMAL(10,7) DEFAULT NULL AFTER receiver_document,
    ADD COLUMN proof_lng         DECIMAL(10,7) DEFAULT NULL AFTER proof_lat;

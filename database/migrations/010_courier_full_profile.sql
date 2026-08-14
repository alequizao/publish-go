-- Cadastro completo do motoboy: dados pessoais, CNH e veículo.
ALTER TABLE couriers
    ADD COLUMN cpf             VARCHAR(14)  DEFAULT NULL AFTER document,
    ADD COLUMN birth_date      DATE         DEFAULT NULL AFTER cpf,
    ADD COLUMN address         VARCHAR(255) DEFAULT NULL AFTER birth_date,
    -- CNH
    ADD COLUMN cnh_number      VARCHAR(20)  DEFAULT NULL AFTER address,
    ADD COLUMN cnh_category    VARCHAR(5)   DEFAULT NULL AFTER cnh_number,
    ADD COLUMN cnh_ear         TINYINT(1)   NOT NULL DEFAULT 0 AFTER cnh_category,
    ADD COLUMN cnh_expiry      DATE         DEFAULT NULL AFTER cnh_ear,
    ADD COLUMN cnh_file_url    VARCHAR(255) DEFAULT NULL AFTER cnh_expiry,
    -- Veículo
    ADD COLUMN vehicle_brand   VARCHAR(60)  DEFAULT NULL AFTER cnh_file_url,
    ADD COLUMN vehicle_model   VARCHAR(60)  DEFAULT NULL AFTER vehicle_brand,
    ADD COLUMN vehicle_year    SMALLINT UNSIGNED DEFAULT NULL AFTER vehicle_model,
    ADD COLUMN vehicle_color   VARCHAR(30)  DEFAULT NULL AFTER vehicle_year,
    ADD COLUMN vehicle_renavam VARCHAR(20)  DEFAULT NULL AFTER vehicle_color,
    ADD COLUMN vehicle_doc_url VARCHAR(255) DEFAULT NULL AFTER vehicle_renavam;

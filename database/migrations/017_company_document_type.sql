-- Tipo de documento da empresa: CPF (autônomo/MEI) ou CNPJ.
ALTER TABLE companies ADD COLUMN document_type ENUM('cpf','cnpj') DEFAULT NULL AFTER document;

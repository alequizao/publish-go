-- Publish Go · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
-- https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
-- Tipo de documento da empresa: CPF (autônomo/MEI) ou CNPJ.
ALTER TABLE companies ADD COLUMN document_type ENUM('cpf','cnpj') DEFAULT NULL AFTER document;

-- Pedidos vindos da loja pública (storefront) + rastreio do cupom aplicado.
ALTER TABLE orders MODIFY COLUMN source ENUM('manual','ifood','api','whatsapp','storefront') NOT NULL DEFAULT 'manual';
ALTER TABLE orders ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subtotal;
ALTER TABLE orders ADD COLUMN coupon_code VARCHAR(40) DEFAULT NULL AFTER discount;

<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\Company;
use PublishGo\Models\Order;
use PublishGo\Models\OrderItem;
use PublishGo\Services\PlanGate;
use PublishGo\Services\RealtimeService;

/**
 * Loja pública (estilo iFood) por empresa — sem autenticação.
 * Resolve a empresa pelo slug, monta o catálogo e recebe pedidos do cliente.
 */
final class StorefrontController extends Controller
{
    private function resolveCompany(Request $request): array
    {
        $slug = (string) $request->param('slug');
        $company = Company::findBySlug($slug);
        if ($company === null || (int) $company['is_active'] !== 1 || $slug === 'publish-go') {
            throw HttpException::notFound('Loja não encontrada.');
        }
        if (!PlanGate::feature($company, 'allow_storefront')) {
            throw HttpException::forbidden('Esta loja não está disponível no momento.');
        }
        return $company;
    }

    /** Vitrine: tema + categorias + produtos + promoções. */
    public function show(Request $request): mixed
    {
        $company = $this->resolveCompany($request);
        $companyId = (int) $company['id'];
        $allowPromos = PlanGate::feature($company, 'allow_promotions');

        $categories = Database::select(
            'SELECT id, name, description FROM product_categories
             WHERE company_id = :c AND is_active = 1 ORDER BY position ASC, id ASC',
            ['c' => $companyId]
        );
        $products = Database::select(
            'SELECT id, category_id, name, description, image_url, price, promo_price, unit,
                    track_stock, stock_qty, is_featured
             FROM products WHERE company_id = :c AND is_active = 1
             ORDER BY position ASC, id DESC',
            ['c' => $companyId]
        );

        // Agrupa complementos por produto (uma query só).
        $optByProduct = [];
        $groups = Database::select(
            'SELECT g.id, g.product_id, g.name, g.min_select, g.max_select, g.is_required
             FROM product_option_groups g WHERE g.company_id = :c ORDER BY g.position ASC, g.id ASC',
            ['c' => $companyId]
        );
        $groupIds = array_map(static fn ($g) => (int) $g['id'], $groups);
        $optionsByGroup = [];
        if ($groupIds !== []) {
            $in = implode(',', array_fill(0, count($groupIds), '?'));
            $opts = Database::select(
                "SELECT id, group_id, name, price FROM product_options
                 WHERE group_id IN ({$in}) AND is_active = 1 ORDER BY position ASC, id ASC",
                $groupIds
            );
            foreach ($opts as $o) {
                $optionsByGroup[(int) $o['group_id']][] = [
                    'id' => (int) $o['id'], 'name' => $o['name'], 'price' => (float) $o['price'],
                ];
            }
        }
        foreach ($groups as $g) {
            $optByProduct[(int) $g['product_id']][] = [
                'id' => (int) $g['id'], 'name' => $g['name'],
                'min_select' => (int) $g['min_select'], 'max_select' => (int) $g['max_select'],
                'is_required' => (int) $g['is_required'] === 1,
                'options' => $optionsByGroup[(int) $g['id']] ?? [],
            ];
        }

        $products = array_map(function ($p) use ($optByProduct) {
            $pid = (int) $p['id'];
            return [
                'id' => $pid,
                'category_id' => $p['category_id'] !== null ? (int) $p['category_id'] : null,
                'name' => $p['name'],
                'description' => $p['description'],
                'image_url' => $p['image_url'],
                'price' => (float) $p['price'],
                'promo_price' => $p['promo_price'] !== null ? (float) $p['promo_price'] : null,
                'unit' => $p['unit'],
                'is_featured' => (int) $p['is_featured'] === 1,
                'sold_out' => (int) $p['track_stock'] === 1 && (int) $p['stock_qty'] <= 0,
                'option_groups' => $optByProduct[$pid] ?? [],
            ];
        }, $products);

        $promotions = [];
        if ($allowPromos) {
            $promotions = Database::select(
                "SELECT id, title, description, type, value, scope, scope_id, banner_url
                 FROM promotions WHERE company_id = :c AND is_active = 1
                 AND (starts_at IS NULL OR starts_at <= NOW())
                 AND (expires_at IS NULL OR expires_at >= NOW())
                 ORDER BY id DESC",
                ['c' => $companyId]
            );
        }

        return [
            'company' => [
                'id' => $companyId,
                'name' => $company['name'],
                'slug' => $company['slug'],
                'segment' => $company['segment'] ?? null,
                'logo_url' => $company['logo_url'],
                'primary_color' => $company['primary_color'],
                'accent_color' => $company['accent_color'],
                'phone' => $company['phone'],
                'address' => $company['address'],
                'delivery_fee' => (float) $company['delivery_fee'],
            ],
            'categories' => array_map(static fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'description' => $c['description']], $categories),
            'products' => $products,
            'promotions' => $promotions,
            'features' => ['coupons' => PlanGate::feature($company, 'allow_coupons')],
        ];
    }

    /** Valida um cupom e devolve o desconto calculado. */
    public function coupon(Request $request): mixed
    {
        $company = $this->resolveCompany($request);
        if (!PlanGate::feature($company, 'allow_coupons')) {
            throw HttpException::forbidden('Cupons não disponíveis nesta loja.');
        }
        $data = Validator::validate($request->all(), [
            'code' => 'required|string|max:40',
            'subtotal' => 'required|numeric',
        ]);
        $coupon = $this->findValidCoupon((int) $company['id'], (string) $data['code'], (float) $data['subtotal']);
        $deliveryFee = (float) $company['delivery_fee'];
        $calc = $this->applyCoupon($coupon, (float) $data['subtotal'], $deliveryFee);
        return [
            'code' => $coupon['code'],
            'type' => $coupon['type'],
            'discount' => $calc['discount'],
            'free_shipping' => $calc['free_shipping'],
        ];
    }

    /** Finaliza o pedido do cliente (cria o pedido no painel da empresa). */
    public function checkout(Request $request): mixed
    {
        $company = $this->resolveCompany($request);
        $companyId = (int) $company['id'];

        // Limite de pedidos/mês do plano.
        $limits = PlanGate::limits($company);
        if ((int) $limits['monthly_orders'] > 0) {
            $used = PlanGate::usage($companyId)['orders_month'];
            if ($used >= (int) $limits['monthly_orders']) {
                throw HttpException::forbidden('A loja atingiu o limite de pedidos do mês.');
            }
        }

        $data = Validator::validate($request->all(), [
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'district' => 'string|max:120',
            'payment_method' => 'in:cash,card,pix,online',
            'notes' => 'string|max:500',
            'coupon_code' => 'string|max:40',
            'items' => 'required|array',
        ]);

        $items = is_array($request->input('items')) ? $request->input('items') : [];
        if ($items === []) {
            throw HttpException::unprocessable('O carrinho está vazio.');
        }

        // Recalcula tudo no servidor a partir dos produtos reais (nunca confia no preço do cliente).
        $resolved = [];
        $subtotal = 0.0;
        foreach ($items as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $product = Database::first(
                'SELECT * FROM products WHERE id = :id AND company_id = :c AND is_active = 1 LIMIT 1',
                ['id' => $pid, 'c' => $companyId]
            );
            if ($product === null) {
                continue;
            }
            if ((int) $product['track_stock'] === 1 && (int) $product['stock_qty'] < $qty) {
                throw HttpException::unprocessable('Produto "' . $product['name'] . '" sem estoque suficiente.');
            }
            $unit = $product['promo_price'] !== null ? (float) $product['promo_price'] : (float) $product['price'];

            // Complementos escolhidos (valida ids e soma preços).
            $chosenIds = array_map('intval', is_array($line['options'] ?? null) ? $line['options'] : []);
            $optLabels = [];
            if ($chosenIds !== []) {
                $in = implode(',', array_fill(0, count($chosenIds), '?'));
                $params = array_merge($chosenIds, [$companyId]);
                $opts = Database::select(
                    "SELECT o.name, o.price FROM product_options o
                     JOIN product_option_groups g ON g.id = o.group_id
                     WHERE o.id IN ({$in}) AND g.product_id = " . $pid . " AND g.company_id = ?",
                    $params
                );
                foreach ($opts as $o) {
                    $unit += (float) $o['price'];
                    $optLabels[] = $o['name'] . ((float) $o['price'] > 0 ? ' (+' . number_format((float) $o['price'], 2, ',', '.') . ')' : '');
                }
            }

            $noteParts = [];
            if ($optLabels !== []) {
                $noteParts[] = implode(', ', $optLabels);
            }
            if (!empty($line['notes'])) {
                $noteParts[] = mb_substr((string) $line['notes'], 0, 180);
            }

            $subtotal += $unit * $qty;
            $resolved[] = [
                'product' => $product, 'qty' => $qty, 'unit' => $unit,
                'name' => $product['name'], 'notes' => $noteParts === [] ? null : mb_substr(implode(' — ', $noteParts), 0, 255),
            ];
        }

        if ($resolved === []) {
            throw HttpException::unprocessable('Nenhum item válido no carrinho.');
        }

        $deliveryFee = (float) $company['delivery_fee'];
        $discount = 0.0;
        $couponRow = null;
        $couponCode = trim((string) ($data['coupon_code'] ?? ''));
        if ($couponCode !== '' && PlanGate::feature($company, 'allow_coupons')) {
            $couponRow = $this->findValidCoupon($companyId, $couponCode, $subtotal);
            $calc = $this->applyCoupon($couponRow, $subtotal, $deliveryFee);
            $discount = $calc['discount'];
            if ($calc['free_shipping']) {
                $deliveryFee = 0.0;
            }
        }

        $total = max(0.0, $subtotal - $discount) + $deliveryFee;

        Database::beginTransaction();
        try {
            $orderId = Order::create([
                'company_id' => $companyId,
                'code' => Order::nextCode($companyId),
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'address' => $data['address'],
                'district' => $data['district'] ?? null,
                'priority' => 'normal',
                'payment_method' => $data['payment_method'] ?? 'pix',
                'source' => 'storefront',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_code' => $couponRow !== null ? $couponRow['code'] : null,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($resolved as $r) {
                OrderItem::create([
                    'order_id' => $orderId,
                    'name' => mb_substr($r['name'], 0, 150),
                    'quantity' => $r['qty'],
                    'unit_price' => $r['unit'],
                    'notes' => $r['notes'],
                ]);
                if ((int) $r['product']['track_stock'] === 1) {
                    Database::execute(
                        'UPDATE products SET stock_qty = GREATEST(0, stock_qty - :q) WHERE id = :id',
                        ['q' => $r['qty'], 'id' => (int) $r['product']['id']]
                    );
                }
            }

            if ($couponRow !== null) {
                Database::execute('UPDATE coupons SET uses = uses + 1 WHERE id = :id', ['id' => (int) $couponRow['id']]);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $order = Order::find($orderId, $companyId);
        RealtimeService::publish($companyId, 'order.created', [
            'id' => $orderId, 'code' => $order['code'] ?? '', 'customer_name' => $data['customer_name'],
            'total' => $total, 'source' => 'storefront', 'status' => 'received',
        ]);

        Response::success([
            'order_id' => $orderId,
            'code' => $order['code'] ?? '',
            'subtotal' => $subtotal,
            'discount' => $discount,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
        ], 201);
        return null;
    }

    /* ───────── helpers de cupom ───────── */
    private function findValidCoupon(int $companyId, string $code, float $subtotal): array
    {
        $coupon = Database::first(
            'SELECT * FROM coupons WHERE company_id = :c AND code = :code AND is_active = 1
             AND (starts_at IS NULL OR starts_at <= NOW())
             AND (expires_at IS NULL OR expires_at >= NOW()) LIMIT 1',
            ['c' => $companyId, 'code' => strtoupper($code)]
        );
        if ($coupon === null) {
            throw HttpException::unprocessable('Cupom inválido ou expirado.', ['coupon_code' => ['Cupom inválido.']]);
        }
        if ($coupon['max_uses'] !== null && (int) $coupon['uses'] >= (int) $coupon['max_uses']) {
            throw HttpException::unprocessable('Cupom esgotado.', ['coupon_code' => ['Cupom esgotado.']]);
        }
        if ($subtotal < (float) $coupon['min_order']) {
            throw HttpException::unprocessable(
                'Pedido mínimo de R$ ' . number_format((float) $coupon['min_order'], 2, ',', '.') . ' para este cupom.',
                ['coupon_code' => ['Pedido mínimo não atingido.']]
            );
        }
        return $coupon;
    }

    private function applyCoupon(array $coupon, float $subtotal, float $deliveryFee): array
    {
        $discount = 0.0;
        $freeShipping = false;
        switch ($coupon['type']) {
            case 'percent':
                $discount = round($subtotal * ((float) $coupon['value'] / 100), 2);
                break;
            case 'fixed':
                $discount = min((float) $coupon['value'], $subtotal);
                break;
            case 'free_shipping':
                $freeShipping = true;
                $discount = 0.0;
                break;
        }
        return ['discount' => $discount, 'free_shipping' => $freeShipping];
    }
}

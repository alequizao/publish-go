<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\Request;

/**
 * Relatórios para o proprietário do estabelecimento (escopado por company_id):
 * vendas por período, produtos mais vendidos, formas de pagamento, estoque,
 * desempenho de cupons.
 */
final class ReportController extends Controller
{
    /** Período em dias a partir do parâmetro ?days= (default 30). */
    private function days(Request $request): int
    {
        $d = (int) ($request->query('days', 30));
        return max(1, min($d, 365));
    }

    /** Visão consolidada de vendas. */
    public function sales(Request $request): mixed
    {
        $c = $this->companyId($request);
        $days = $this->days($request);

        $totals = Database::first(
            "SELECT
                COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END),0) AS delivered,
                COALESCE(SUM(CASE WHEN status='canceled' THEN 1 ELSE 0 END),0) AS canceled,
                COALESCE(SUM(CASE WHEN status='delivered' THEN total ELSE 0 END),0) AS revenue,
                COALESCE(SUM(CASE WHEN status='delivered' THEN discount ELSE 0 END),0) AS discounts,
                COALESCE(SUM(CASE WHEN status='delivered' THEN delivery_fee ELSE 0 END),0) AS delivery_fees,
                COALESCE(AVG(CASE WHEN status='delivered' THEN total END),0) AS avg_ticket
             FROM orders WHERE company_id = :c AND created_at >= (NOW() - INTERVAL {$days} DAY)",
            ['c' => $c]
        ) ?? [];

        $byDay = Database::select(
            "SELECT DATE(created_at) AS day,
                    COUNT(*) AS orders,
                    COALESCE(SUM(CASE WHEN status='delivered' THEN total ELSE 0 END),0) AS revenue
             FROM orders WHERE company_id = :c AND created_at >= (NOW() - INTERVAL {$days} DAY)
             GROUP BY DATE(created_at) ORDER BY day ASC",
            ['c' => $c]
        );

        $bySource = Database::select(
            "SELECT source, COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN status='delivered' THEN total ELSE 0 END),0) AS revenue
             FROM orders WHERE company_id = :c AND created_at >= (NOW() - INTERVAL {$days} DAY)
             GROUP BY source ORDER BY n DESC",
            ['c' => $c]
        );

        return [
            'days' => $days,
            'totals' => [
                'orders' => (int) ($totals['orders'] ?? 0),
                'delivered' => (int) ($totals['delivered'] ?? 0),
                'canceled' => (int) ($totals['canceled'] ?? 0),
                'revenue' => (float) ($totals['revenue'] ?? 0),
                'discounts' => (float) ($totals['discounts'] ?? 0),
                'delivery_fees' => (float) ($totals['delivery_fees'] ?? 0),
                'avg_ticket' => (float) ($totals['avg_ticket'] ?? 0),
            ],
            'by_day' => array_map(static fn ($r) => [
                'day' => $r['day'], 'orders' => (int) $r['orders'], 'revenue' => (float) $r['revenue'],
            ], $byDay),
            'by_source' => array_map(static fn ($r) => [
                'source' => $r['source'], 'n' => (int) $r['n'], 'revenue' => (float) $r['revenue'],
            ], $bySource),
        ];
    }

    /** Produtos mais vendidos (por itens dos pedidos). */
    public function topProducts(Request $request): mixed
    {
        $c = $this->companyId($request);
        $days = $this->days($request);
        $rows = Database::select(
            "SELECT oi.name,
                    SUM(oi.quantity) AS qty,
                    SUM(oi.quantity * oi.unit_price) AS revenue
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.company_id = :c AND o.status <> 'canceled'
               AND o.created_at >= (NOW() - INTERVAL {$days} DAY)
             GROUP BY oi.name ORDER BY qty DESC LIMIT 20",
            ['c' => $c]
        );
        return array_map(static fn ($r) => [
            'name' => $r['name'], 'qty' => (int) $r['qty'], 'revenue' => (float) $r['revenue'],
        ], $rows);
    }

    /** Faturamento por forma de pagamento. */
    public function byPayment(Request $request): mixed
    {
        $c = $this->companyId($request);
        $days = $this->days($request);
        $rows = Database::select(
            "SELECT payment_method,
                    COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN status='delivered' THEN total ELSE 0 END),0) AS revenue
             FROM orders WHERE company_id = :c AND created_at >= (NOW() - INTERVAL {$days} DAY)
             GROUP BY payment_method ORDER BY revenue DESC",
            ['c' => $c]
        );
        return array_map(static fn ($r) => [
            'payment_method' => $r['payment_method'], 'n' => (int) $r['n'], 'revenue' => (float) $r['revenue'],
        ], $rows);
    }

    /** Estoque baixo / esgotado (apenas produtos com controle de estoque). */
    public function stock(Request $request): mixed
    {
        $c = $this->companyId($request);
        $rows = Database::select(
            'SELECT id, name, sku, stock_qty, stock_alert
             FROM products
             WHERE company_id = :c AND track_stock = 1 AND stock_qty <= GREATEST(stock_alert, 0)
             ORDER BY stock_qty ASC LIMIT 100',
            ['c' => $c]
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'sku' => $r['sku'],
            'stock_qty' => (int) $r['stock_qty'], 'stock_alert' => (int) $r['stock_alert'],
            'sold_out' => (int) $r['stock_qty'] <= 0,
        ], $rows);
    }

    /** Desempenho dos cupons. */
    public function coupons(Request $request): mixed
    {
        $c = $this->companyId($request);
        $rows = Database::select(
            "SELECT c.code, c.type, c.value, c.uses,
                    COALESCE(SUM(o.discount),0) AS total_discount,
                    COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.total ELSE 0 END),0) AS revenue
             FROM coupons c
             LEFT JOIN orders o ON o.company_id = c.company_id AND o.coupon_code = c.code
             WHERE c.company_id = :c
             GROUP BY c.id, c.code, c.type, c.value, c.uses
             ORDER BY c.uses DESC",
            ['c' => $c]
        );
        return array_map(static fn ($r) => [
            'code' => $r['code'], 'type' => $r['type'], 'value' => (float) $r['value'],
            'uses' => (int) $r['uses'], 'total_discount' => (float) $r['total_discount'], 'revenue' => (float) $r['revenue'],
        ], $rows);
    }
}

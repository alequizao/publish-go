<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\Request;

final class DashboardController extends Controller
{
    /** Resumo de KPIs do dia. */
    public function summary(Request $request): mixed
    {
        $companyId = $this->companyId($request);

        $today = Database::first(
            "SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
                COALESCE(SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END), 0) AS canceled,
                COALESCE(SUM(CASE WHEN status IN ('received','preparing','ready','dispatched','picked') THEN 1 ELSE 0 END), 0) AS active,
                COALESCE(AVG(CASE WHEN status = 'delivered' THEN total END), 0) AS avg_ticket
             FROM orders
             WHERE company_id = :c AND DATE(created_at) = CURDATE()",
            ['c' => $companyId]
        ) ?? [];

        $inDelivery = Database::first(
            "SELECT COUNT(*) AS n FROM deliveries
             WHERE company_id = :c AND status IN ('assigned','accepted','picked')",
            ['c' => $companyId]
        );

        $couriers = Database::first(
            "SELECT
                SUM(status = 'online') AS online,
                SUM(status = 'busy') AS busy,
                COUNT(*) AS total
             FROM couriers WHERE company_id = :c",
            ['c' => $companyId]
        ) ?? [];

        // Tempo médio de entrega (minutos) nos pedidos entregues hoje.
        $avgTime = Database::first(
            "SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at)), 0) AS minutes
             FROM orders
             WHERE company_id = :c AND status = 'delivered' AND delivered_at IS NOT NULL
               AND DATE(created_at) = CURDATE()",
            ['c' => $companyId]
        );

        // Taxa de aceitação das entregas (aceitas / atribuídas).
        $accept = Database::first(
            "SELECT
                SUM(status IN ('accepted','picked','delivered')) AS accepted,
                SUM(status IN ('assigned','accepted','picked','delivered','rejected')) AS offered
             FROM deliveries WHERE company_id = :c AND DATE(created_at) = CURDATE()",
            ['c' => $companyId]
        ) ?? [];

        $offered = (int) ($accept['offered'] ?? 0);
        $acceptanceRate = $offered > 0 ? round(((int) $accept['accepted'] / $offered) * 100, 1) : 100.0;

        $revenue = (float) ($today['revenue'] ?? 0);
        // Lucro estimado: receita - repasse aos motoboys do dia.
        $payout = Database::first(
            "SELECT COALESCE(SUM(courier_fee), 0) AS payout FROM deliveries
             WHERE company_id = :c AND status = 'delivered' AND DATE(created_at) = CURDATE()",
            ['c' => $companyId]
        );
        $profit = $revenue - (float) ($payout['payout'] ?? 0);

        return [
            'revenue' => $revenue,
            'orders_total' => (int) ($today['total_orders'] ?? 0),
            'orders_active' => (int) ($today['active'] ?? 0),
            'orders_delivered' => (int) ($today['delivered'] ?? 0),
            'orders_canceled' => (int) ($today['canceled'] ?? 0),
            'avg_ticket' => round((float) ($today['avg_ticket'] ?? 0), 2),
            'profit' => round($profit, 2),
            'in_delivery' => (int) ($inDelivery['n'] ?? 0),
            'couriers_online' => (int) ($couriers['online'] ?? 0),
            'couriers_busy' => (int) ($couriers['busy'] ?? 0),
            'couriers_total' => (int) ($couriers['total'] ?? 0),
            'avg_delivery_minutes' => (int) round((float) ($avgTime['minutes'] ?? 0)),
            'acceptance_rate' => $acceptanceRate,
        ];
    }

    /** Série temporal para o gráfico (pedidos e receita por hora, últimas 24h). */
    public function chart(Request $request): mixed
    {
        $companyId = $this->companyId($request);

        $rows = Database::select(
            "SELECT HOUR(created_at) AS h,
                    COUNT(*) AS orders,
                    COALESCE(SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END), 0) AS revenue
             FROM orders
             WHERE company_id = :c AND created_at >= (NOW() - INTERVAL 24 HOUR)
             GROUP BY HOUR(created_at)",
            ['c' => $companyId]
        );

        $byHour = [];
        foreach ($rows as $r) {
            $byHour[(int) $r['h']] = $r;
        }

        $labels = [];
        $orders = [];
        $revenue = [];
        for ($i = 23; $i >= 0; $i--) {
            $hour = (int) date('G', time() - $i * 3600);
            $labels[] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . 'h';
            $orders[] = (int) ($byHour[$hour]['orders'] ?? 0);
            $revenue[] = round((float) ($byHour[$hour]['revenue'] ?? 0), 2);
        }

        // Top bairros por volume de pedidos (para "bairros com mais pedidos").
        $districts = Database::select(
            "SELECT COALESCE(district,'—') AS district, COUNT(*) AS n
             FROM orders WHERE company_id = :c AND created_at >= (NOW() - INTERVAL 7 DAY)
             GROUP BY district ORDER BY n DESC LIMIT 6",
            ['c' => $companyId]
        );

        return [
            'labels' => $labels,
            'orders' => $orders,
            'revenue' => $revenue,
            'top_districts' => $districts,
        ];
    }
}

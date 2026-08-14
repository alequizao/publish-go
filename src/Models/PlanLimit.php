<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

final class PlanLimit extends Model
{
    protected static string $table = 'plan_limits';

    /** Limites de um plano (com fallback seguro caso não exista linha). */
    public static function forPlan(string $plan): array
    {
        $row = Database::first('SELECT * FROM plan_limits WHERE plan = :p LIMIT 1', ['p' => $plan]);
        if ($row === null) {
            $row = [
                'plan' => $plan, 'label' => ucfirst($plan),
                'max_products' => 0, 'max_categories' => 0, 'max_couriers' => 0, 'monthly_orders' => 0,
                'allow_storefront' => 1, 'allow_coupons' => 1, 'allow_promotions' => 1, 'allow_stock' => 1,
            ];
        }
        return self::cast($row);
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        $rows = Database::select("SELECT * FROM plan_limits ORDER BY FIELD(plan,'free','pro','enterprise')");
        return array_map([self::class, 'cast'], $rows);
    }

    public static function cast(array $r): array
    {
        foreach (['id', 'max_products', 'max_categories', 'max_couriers', 'monthly_orders'] as $k) {
            if (isset($r[$k])) {
                $r[$k] = (int) $r[$k];
            }
        }
        foreach (['allow_storefront', 'allow_coupons', 'allow_promotions', 'allow_stock'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0) === 1;
        }
        return $r;
    }
}

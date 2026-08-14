<?php

declare(strict_types=1);

namespace PublishGo\Services;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Models\PlanLimit;

/**
 * Aplica os limites por plano (configuráveis pelo super-admin) sobre as
 * operações das empresas: nº de produtos/categorias/motoboys e features
 * (loja, cupons, promoções, estoque).
 */
final class PlanGate
{
    public static function limits(array $company): array
    {
        return PlanLimit::forPlan($company['plan'] ?? 'free');
    }

    /** Contagem atual de uso da empresa. */
    public static function usage(int $companyId): array
    {
        $count = static function (string $table, string $where = '') use ($companyId): int {
            $sql = "SELECT COUNT(*) AS n FROM {$table} WHERE company_id = :c" . ($where !== '' ? " AND {$where}" : '');
            return (int) (Database::first($sql, ['c' => $companyId])['n'] ?? 0);
        };
        return [
            'products' => $count('products'),
            'categories' => $count('product_categories'),
            'couriers' => $count('couriers'),
            'orders_month' => (int) (Database::first(
                "SELECT COUNT(*) AS n FROM orders WHERE company_id = :c
                 AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')",
                ['c' => $companyId]
            )['n'] ?? 0),
        ];
    }

    /** Resumo combinado (limites + uso) para o painel. */
    public static function snapshot(array $company): array
    {
        $limits = self::limits($company);
        $usage = self::usage((int) $company['id']);
        return ['plan' => $company['plan'] ?? 'free', 'limits' => $limits, 'usage' => $usage];
    }

    /**
     * Garante que a empresa pode criar mais um item do tipo informado.
     * $what: products | categories | couriers
     */
    public static function ensureCanCreate(array $company, string $what): void
    {
        $limits = self::limits($company);
        $map = ['products' => 'max_products', 'categories' => 'max_categories', 'couriers' => 'max_couriers'];
        $key = $map[$what] ?? null;
        if ($key === null) {
            return;
        }
        $max = (int) $limits[$key];
        if ($max <= 0) {
            return; // 0 = ilimitado
        }
        $current = self::usage((int) $company['id'])[$what] ?? 0;
        if ($current >= $max) {
            throw HttpException::forbidden(
                "Limite do plano {$limits['label']} atingido ({$max}). Faça upgrade para adicionar mais."
            );
        }
    }

    public static function feature(array $company, string $flag): bool
    {
        return (bool) (self::limits($company)[$flag] ?? false);
    }

    public static function ensureFeature(array $company, string $flag, string $label): void
    {
        if (!self::feature($company, $flag)) {
            throw HttpException::forbidden("O recurso \"{$label}\" não está disponível no seu plano.");
        }
    }
}

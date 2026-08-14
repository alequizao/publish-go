<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Validator;
use PublishGo\Models\AuditLog;
use PublishGo\Models\PlanLimit;

/** Configuração dos limites por plano — somente super-admin do SaaS. */
final class PlanLimitController extends Controller
{
    public function index(Request $request): mixed
    {
        $this->requireRole($request, 'admin');
        return PlanLimit::all();
    }

    public function update(Request $request): mixed
    {
        $this->requireRole($request, 'admin');
        $plan = (string) $request->param('plan');
        if (!in_array($plan, ['free', 'pro', 'enterprise'], true)) {
            throw HttpException::notFound('Plano inválido.');
        }
        $data = Validator::validate($request->all(), [
            'label' => 'string|max:60',
            'max_products' => 'integer',
            'max_categories' => 'integer',
            'max_couriers' => 'integer',
            'monthly_orders' => 'integer',
            'allow_storefront' => 'integer',
            'allow_coupons' => 'integer',
            'allow_promotions' => 'integer',
            'allow_stock' => 'integer',
        ]);
        if ($data !== []) {
            $sets = [];
            $params = ['plan' => $plan];
            foreach ($data as $k => $v) {
                $sets[] = "{$k} = :{$k}";
                $params[$k] = is_bool($v) ? (int) $v : $v;
            }
            Database::execute('UPDATE plan_limits SET ' . implode(', ', $sets) . ' WHERE plan = :plan', $params);
        }
        AuditLog::record(null, $this->userId($request), 'admin.plan.update', 'plan', null, $request->ip(), ['plan' => $plan]);
        return PlanLimit::forPlan($plan);
    }
}

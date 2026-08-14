<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\Company;
use PublishGo\Models\Coupon;
use PublishGo\Services\PlanGate;

/** Cupons de desconto (gestão pelo estabelecimento). Requer feature do plano. */
final class CouponController extends Controller
{
    private function company(Request $request): array
    {
        $c = Company::find($this->companyId($request));
        if ($c === null) {
            throw HttpException::unauthorized();
        }
        PlanGate::ensureFeature($c, 'allow_coupons', 'Cupons');
        return $c;
    }

    public function index(Request $request): mixed
    {
        $company = $this->company($request);
        $rows = Database::select(
            'SELECT * FROM coupons WHERE company_id = :c ORDER BY id DESC',
            ['c' => $company['id']]
        );
        return array_map([$this, 'cast'], $rows);
    }

    public function store(Request $request): mixed
    {
        $company = $this->company($request);
        $data = $this->validate($request);
        $data['company_id'] = $company['id'];
        $data['code'] = strtoupper($data['code']);
        if (Database::first('SELECT id FROM coupons WHERE company_id = :c AND code = :code', ['c' => $company['id'], 'code' => $data['code']]) !== null) {
            throw HttpException::unprocessable('Já existe um cupom com este código.', ['code' => ['Código em uso.']]);
        }
        $id = Coupon::create($data);
        Response::success($this->cast((array) Coupon::find($id, (int) $company['id'])), 201);
        return null;
    }

    public function update(Request $request): mixed
    {
        $company = $this->company($request);
        $id = (int) $request->param('id');
        if (Coupon::find($id, (int) $company['id']) === null) {
            throw HttpException::notFound('Cupom não encontrado.');
        }
        $data = $this->validate($request, false);
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }
        if ($data !== []) {
            Coupon::update($id, $data, (int) $company['id']);
        }
        return $this->cast((array) Coupon::find($id, (int) $company['id']));
    }

    public function delete(Request $request): mixed
    {
        $company = $this->company($request);
        Coupon::delete((int) $request->param('id'), (int) $company['id']);
        return ['deleted' => true];
    }

    private function validate(Request $request, bool $creating = true): array
    {
        $data = Validator::validate($request->all(), [
            'code' => ($creating ? 'required|' : '') . 'string|max:40',
            'type' => 'in:percent,fixed,free_shipping',
            'value' => 'numeric',
            'min_order' => 'numeric',
            'max_uses' => 'integer',
            'starts_at' => 'string|max:25',
            'expires_at' => 'string|max:25',
            'is_active' => 'integer',
        ]);
        foreach (['starts_at', 'expires_at'] as $d) {
            if (array_key_exists($d, $data) && ($data[$d] === '' || $data[$d] === null)) {
                $data[$d] = null;
            }
        }
        if (array_key_exists('max_uses', $data)) {
            $data['max_uses'] = $data['max_uses'] === '' || $data['max_uses'] === null ? null : (int) $data['max_uses'];
        }
        return $data;
    }

    private function cast(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['company_id'] = (int) $c['company_id'];
        $c['value'] = (float) $c['value'];
        $c['min_order'] = (float) $c['min_order'];
        $c['uses'] = (int) $c['uses'];
        $c['max_uses'] = $c['max_uses'] !== null ? (int) $c['max_uses'] : null;
        $c['is_active'] = (int) $c['is_active'] === 1;
        return $c;
    }
}

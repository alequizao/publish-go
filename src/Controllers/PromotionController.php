<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\Company;
use PublishGo\Models\Promotion;
use PublishGo\Services\PlanGate;

/** Promoções/banners (gestão pelo estabelecimento). Requer feature do plano. */
final class PromotionController extends Controller
{
    private function company(Request $request): array
    {
        $c = Company::find($this->companyId($request));
        if ($c === null) {
            throw HttpException::unauthorized();
        }
        PlanGate::ensureFeature($c, 'allow_promotions', 'Promoções');
        return $c;
    }

    public function index(Request $request): mixed
    {
        $company = $this->company($request);
        $rows = Database::select('SELECT * FROM promotions WHERE company_id = :c ORDER BY id DESC', ['c' => $company['id']]);
        return array_map([$this, 'cast'], $rows);
    }

    public function store(Request $request): mixed
    {
        $company = $this->company($request);
        $data = $this->validate($request);
        $data['company_id'] = $company['id'];
        $id = Promotion::create($data);
        Response::success($this->cast((array) Promotion::find($id, (int) $company['id'])), 201);
        return null;
    }

    public function update(Request $request): mixed
    {
        $company = $this->company($request);
        $id = (int) $request->param('id');
        if (Promotion::find($id, (int) $company['id']) === null) {
            throw HttpException::notFound('Promoção não encontrada.');
        }
        $data = $this->validate($request, false);
        if ($data !== []) {
            Promotion::update($id, $data, (int) $company['id']);
        }
        return $this->cast((array) Promotion::find($id, (int) $company['id']));
    }

    public function delete(Request $request): mixed
    {
        $company = $this->company($request);
        Promotion::delete((int) $request->param('id'), (int) $company['id']);
        return ['deleted' => true];
    }

    private function validate(Request $request, bool $creating = true): array
    {
        $data = Validator::validate($request->all(), [
            'title' => ($creating ? 'required|' : '') . 'string|max:150',
            'description' => 'string|max:255',
            'type' => 'in:percent,fixed',
            'value' => 'numeric',
            'scope' => 'in:all,category,product',
            'scope_id' => 'integer',
            'banner_url' => 'string|max:255',
            'starts_at' => 'string|max:25',
            'expires_at' => 'string|max:25',
            'is_active' => 'integer',
        ]);
        foreach (['starts_at', 'expires_at'] as $d) {
            if (array_key_exists($d, $data) && ($data[$d] === '' || $data[$d] === null)) {
                $data[$d] = null;
            }
        }
        if (array_key_exists('scope_id', $data)) {
            $data['scope_id'] = $data['scope_id'] === '' || (int) $data['scope_id'] <= 0 ? null : (int) $data['scope_id'];
        }
        return $data;
    }

    private function cast(array $p): array
    {
        $p['id'] = (int) $p['id'];
        $p['company_id'] = (int) $p['company_id'];
        $p['value'] = (float) $p['value'];
        $p['scope_id'] = $p['scope_id'] !== null ? (int) $p['scope_id'] : null;
        $p['is_active'] = (int) $p['is_active'] === 1;
        return $p;
    }
}

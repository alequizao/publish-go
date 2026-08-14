<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\Courier;
use PublishGo\Services\RealtimeService;

final class CourierController extends Controller
{
    /** Lista todos os motoboys da empresa. */
    public function index(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        return array_map([$this, 'cast'], Courier::forCompany($companyId));
    }

    /** Lista apenas os motoboys online (para o mapa operacional). */
    public function online(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        return array_map([$this, 'cast'], Courier::online($companyId));
    }

    /** Cadastro de motoboy pelo estabelecimento. */
    public function store(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $data = Validator::validate($request->all(), [
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:20',
            'email' => 'email|max:150',
            'document' => 'string|max:20',
            'vehicle' => 'in:moto,bike,car',
            'plate' => 'string|max:10',
            'password' => 'string|min:6|max:100',
        ]);

        $id = Courier::create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'document' => $data['document'] ?? null,
            'vehicle' => $data['vehicle'] ?? 'moto',
            'plate' => $data['plate'] ?? null,
            'password_hash' => isset($data['password']) ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
        ]);

        Response::success($this->cast((array) Courier::find($id, $companyId)), 201);
        return null;
    }

    /** Edita um motoboy (estabelecimento). */
    public function update(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        if (Courier::find($id, $companyId) === null) {
            throw HttpException::notFound('Motoboy não encontrado.');
        }
        $data = Validator::validate($request->all(), [
            'name' => 'string|max:150',
            'phone' => 'string|max:20',
            'email' => 'email|max:150',
            'document' => 'string|max:20',
            'vehicle' => 'in:moto,bike,car',
            'plate' => 'string|max:10',
            'is_verified' => 'in:0,1',
        ]);
        $password = $request->input('password');
        if (is_string($password) && $password !== '') {
            $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        }
        if ($data !== []) {
            Courier::update($id, $data, $companyId);
        }
        return $this->cast((array) Courier::find($id, $companyId));
    }

    /** Resumo financeiro de um motoboy (saldo, ganhos, pagamentos, extrato). */
    public function financial(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $courier = Courier::find($id, $companyId);
        if ($courier === null) {
            throw HttpException::notFound('Motoboy não encontrado.');
        }

        $from = $request->query('from');
        $to = $request->query('to');
        $where = 'courier_id = :c';
        $params = ['c' => $id];
        if ($from) { $where .= ' AND created_at >= :from'; $params['from'] = $from . ' 00:00:00'; }
        if ($to) { $where .= ' AND created_at <= :to'; $params['to'] = $to . ' 23:59:59'; }

        $sum = \PublishGo\Core\Database::first(
            "SELECT
                COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END),0) AS earned,
                COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END),0) AS paid,
                COUNT(*) AS n
             FROM transactions WHERE {$where}",
            $params
        );
        $tx = \PublishGo\Core\Database::select(
            "SELECT t.id, t.type, t.direction, t.amount, t.description, t.created_at, o.code AS order_code
             FROM transactions t LEFT JOIN orders o ON o.id = t.order_id
             WHERE {$where} ORDER BY t.created_at DESC LIMIT 200",
            $params
        );
        foreach ($tx as &$t) {
            $t['amount'] = (float) $t['amount'];
            $t['id'] = (int) $t['id'];
        }

        return [
            'courier' => [
                'id' => (int) $courier['id'],
                'name' => $courier['name'],
                'phone' => $courier['phone'],
                'document' => $courier['cpf'] ?? $courier['document'],
                'balance' => (float) $courier['balance'],
                'total_deliveries' => (int) $courier['total_deliveries'],
            ],
            'earned' => (float) ($sum['earned'] ?? 0),
            'paid' => (float) ($sum['paid'] ?? 0),
            'transactions' => $tx,
        ];
    }

    /** Registra um pagamento (repasse) ao motoboy. */
    public function registerPayment(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $courier = Courier::find($id, $companyId);
        if ($courier === null) {
            throw HttpException::notFound('Motoboy não encontrado.');
        }
        $data = Validator::validate($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'method' => 'in:pix,cash,transfer',
            'description' => 'string|max:255',
        ]);

        $amount = round((float) $data['amount'], 2);
        $method = $data['method'] ?? 'pix';
        $desc = $data['description'] ?? ('Pagamento ' . strtoupper($method));

        \PublishGo\Core\Database::beginTransaction();
        try {
            $txId = \PublishGo\Models\Transaction::create([
                'company_id' => $companyId,
                'courier_id' => $id,
                'type' => 'withdrawal',
                'direction' => 'debit',
                'amount' => $amount,
                'description' => $desc,
            ]);
            \PublishGo\Core\Database::execute(
                'UPDATE couriers SET balance = balance - :a WHERE id = :id AND company_id = :c',
                ['a' => $amount, 'id' => $id, 'c' => $companyId]
            );
            \PublishGo\Core\Database::commit();
        } catch (\Throwable $e) {
            \PublishGo\Core\Database::rollBack();
            throw $e;
        }

        \PublishGo\Models\AuditLog::record($companyId, $this->userId($request), 'courier.payment', 'courier', $id, $request->ip(), ['amount' => $amount, 'method' => $method]);

        $updated = Courier::find($id, $companyId);
        return [
            'payment' => ['id' => $txId, 'amount' => $amount, 'method' => $method, 'description' => $desc, 'created_at' => date('Y-m-d H:i:s')],
            'balance' => (float) $updated['balance'],
        ];
    }

    /** Remove um motoboy. */
    public function destroy(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        if (Courier::find($id, $companyId) === null) {
            throw HttpException::notFound('Motoboy não encontrado.');
        }
        Courier::delete($id, $companyId);
        return ['ok' => true];
    }

    /** Atualiza a localização de um motoboy (usado pelo app do motoboy / simulação). */
    public function updateLocation(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $courier = Courier::find($id, $companyId);
        if ($courier === null) {
            throw HttpException::notFound('Motoboy não encontrado.');
        }

        $data = Validator::validate($request->all(), [
            'lat' => 'required|latitude',
            'lng' => 'required|longitude',
            'heading' => 'integer',
        ]);

        Courier::updateLocation($id, $companyId, (float) $data['lat'], (float) $data['lng'], isset($data['heading']) ? (int) $data['heading'] : null);

        RealtimeService::publish($companyId, 'courier.location', [
            'courier_id' => $id,
            'lat' => (float) $data['lat'],
            'lng' => (float) $data['lng'],
            'heading' => isset($data['heading']) ? (int) $data['heading'] : null,
        ]);

        return ['ok' => true];
    }

    /** Altera o status (online/offline/busy) de um motoboy. */
    public function updateStatus(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $data = Validator::validate($request->all(), [
            'status' => 'required|in:offline,online,busy,suspended',
        ]);
        if (Courier::find($id, $companyId) === null) {
            throw HttpException::notFound('Motoboy não encontrado.');
        }
        Courier::setStatus($id, $companyId, $data['status']);
        RealtimeService::publish($companyId, 'courier.status', ['courier_id' => $id, 'status' => $data['status']]);
        return ['ok' => true];
    }

    private function cast(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['company_id'] = (int) $c['company_id'];
        $c['lat'] = $c['lat'] !== null ? (float) $c['lat'] : null;
        $c['lng'] = $c['lng'] !== null ? (float) $c['lng'] : null;
        $c['heading'] = $c['heading'] !== null ? (int) $c['heading'] : null;
        $c['rating'] = (float) $c['rating'];
        $c['balance'] = (float) $c['balance'];
        $c['total_deliveries'] = (int) $c['total_deliveries'];
        unset($c['password_hash']);
        return $c;
    }
}

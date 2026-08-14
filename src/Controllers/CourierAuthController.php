<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\Env;
use PublishGo\Core\HttpException;
use PublishGo\Core\Jwt;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\Company;
use PublishGo\Models\Courier;
use PublishGo\Models\Delivery;
use PublishGo\Models\Message;
use PublishGo\Models\Order;
use PublishGo\Models\Transaction;
use PublishGo\Services\GeoService;
use PublishGo\Services\RealtimeService;

/**
 * Backend do aplicativo do motoboy: autenticação, status, corridas,
 * rastreamento, ganhos e extrato.
 */
final class CourierAuthController extends Controller
{
    /* ───────────────── Autenticação ───────────────── */

    /** Auto-cadastro público do motoboy numa empresa (por slug). */
    public function register(Request $request): mixed
    {
        $data = Validator::validate($request->all(), [
            'company_slug' => 'required|string|max:80',
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:20',
            'email' => 'email|max:150',
            'cpf' => 'required|string|min:11|max:14',
            'birth_date' => 'string|max:10',
            'address' => 'string|max:255',
            'password' => 'required|string|min:6|max:100',
            // CNH
            'cnh_number' => 'string|max:20',
            'cnh_category' => 'string|max:5',
            'cnh_ear' => 'in:0,1',
            'cnh_expiry' => 'string|max:10',
            'cnh_file_url' => 'string|max:255',
            // Veículo
            'vehicle' => 'in:moto,bike,car',
            'plate' => 'string|max:10',
            'vehicle_brand' => 'string|max:60',
            'vehicle_model' => 'string|max:60',
            'vehicle_year' => 'integer',
            'vehicle_color' => 'string|max:30',
            'vehicle_renavam' => 'string|max:20',
            'vehicle_doc_url' => 'string|max:255',
        ]);

        $company = Company::findBySlug($data['company_slug']);
        if ($company === null) {
            throw HttpException::notFound('Empresa não encontrada.');
        }

        if (Courier::findByLogin($data['phone']) !== null
            || (!empty($data['email']) && Courier::findByLogin($data['email']) !== null)) {
            throw HttpException::unprocessable('Telefone ou e-mail já cadastrado.');
        }

        $id = Courier::create([
            'company_id' => (int) $company['id'],
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'document' => $data['cpf'] ?? null,
            'cpf' => $data['cpf'] ?? null,
            'birth_date' => !empty($data['birth_date']) ? $data['birth_date'] : null,
            'address' => $data['address'] ?? null,
            'cnh_number' => $data['cnh_number'] ?? null,
            'cnh_category' => $data['cnh_category'] ?? null,
            'cnh_ear' => isset($data['cnh_ear']) ? (int) $data['cnh_ear'] : 0,
            'cnh_expiry' => !empty($data['cnh_expiry']) ? $data['cnh_expiry'] : null,
            'cnh_file_url' => $data['cnh_file_url'] ?? null,
            'vehicle' => $data['vehicle'] ?? 'moto',
            'plate' => $data['plate'] ?? null,
            'vehicle_brand' => $data['vehicle_brand'] ?? null,
            'vehicle_model' => $data['vehicle_model'] ?? null,
            'vehicle_year' => isset($data['vehicle_year']) ? (int) $data['vehicle_year'] : null,
            'vehicle_color' => $data['vehicle_color'] ?? null,
            'vehicle_renavam' => $data['vehicle_renavam'] ?? null,
            'vehicle_doc_url' => $data['vehicle_doc_url'] ?? null,
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'status' => 'offline',
            'is_verified' => 0,
        ]);

        $courier = Courier::find($id);
        return $this->tokenResponse($courier);
    }

    /** Login do motoboy por telefone/e-mail + senha. */
    public function login(Request $request): mixed
    {
        $data = Validator::validate($request->all(), [
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $courier = Courier::findByLogin($data['login']);
        if ($courier === null || empty($courier['password_hash']) || !password_verify($data['password'], $courier['password_hash'])) {
            throw HttpException::unauthorized('Credenciais inválidas.');
        }
        if ($courier['status'] === 'suspended') {
            throw HttpException::forbidden('Conta suspensa. Contate o estabelecimento.');
        }

        return $this->tokenResponse($courier);
    }

    /** Dados do motoboy autenticado. */
    public function me(Request $request): mixed
    {
        $courier = Courier::find($this->courierId($request));
        if ($courier === null) {
            throw HttpException::notFound('Motoboy não encontrado.');
        }
        return $this->publicData($courier);
    }

    /* ───────────────── Operação ───────────────── */

    /** Define status (online/offline). */
    public function setStatus(Request $request): mixed
    {
        $data = Validator::validate($request->all(), ['status' => 'required|in:online,offline']);
        $cid = $this->courierId($request);
        $companyId = $this->companyIdOf($request);
        Courier::setStatus($cid, $companyId, $data['status']);
        RealtimeService::publish($companyId, 'courier.status', ['courier_id' => $cid, 'status' => $data['status']]);
        return ['status' => $data['status']];
    }

    /** Atualiza a própria localização (GPS contínuo). */
    public function location(Request $request): mixed
    {
        $data = Validator::validate($request->all(), [
            'lat' => 'required|latitude',
            'lng' => 'required|longitude',
            'heading' => 'integer',
        ]);
        $cid = $this->courierId($request);
        $companyId = $this->companyIdOf($request);
        Courier::updateLocation($cid, $companyId, (float) $data['lat'], (float) $data['lng'], isset($data['heading']) ? (int) $data['heading'] : null);
        RealtimeService::publish($companyId, 'courier.location', [
            'courier_id' => $cid, 'lat' => (float) $data['lat'], 'lng' => (float) $data['lng'],
            'heading' => isset($data['heading']) ? (int) $data['heading'] : null,
        ]);
        return ['ok' => true];
    }

    /** Corridas do motoboy (ativas e oferta pendente). */
    public function deliveries(Request $request): mixed
    {
        $cid = $this->courierId($request);
        $rows = Database::select(
            "SELECT d.*, o.code AS order_code, o.customer_name, o.customer_phone, o.address, o.district,
                    o.lat AS order_lat, o.lng AS order_lng, o.total AS order_total, o.payment_method
             FROM deliveries d JOIN orders o ON o.id = d.order_id
             WHERE d.courier_id = :cid AND d.status IN ('assigned','accepted','picked')
             ORDER BY d.created_at DESC",
            ['cid' => $cid]
        );
        $company = Company::find($this->companyIdOf($request));
        return array_map(fn ($d) => $this->castDelivery($d, $company), $rows);
    }

    /** Aceita uma corrida. */
    public function accept(Request $request): mixed
    {
        $delivery = $this->ownedDelivery($request, ['assigned']);
        Database::execute(
            "UPDATE deliveries SET status='accepted', accepted_at=NOW() WHERE id = :id",
            ['id' => $delivery['id']]
        );
        Courier::setStatus($this->courierId($request), $this->companyIdOf($request), 'busy');
        RealtimeService::publish($this->companyIdOf($request), 'delivery.accepted', [
            'delivery_id' => (int) $delivery['id'], 'order_id' => (int) $delivery['order_id'], 'courier_id' => $this->courierId($request),
        ]);
        return ['status' => 'accepted'];
    }

    /** Rejeita a corrida (volta para a fila). */
    public function reject(Request $request): mixed
    {
        $delivery = $this->ownedDelivery($request, ['assigned']);
        Database::beginTransaction();
        try {
            Database::execute("UPDATE deliveries SET status='rejected' WHERE id = :id", ['id' => $delivery['id']]);
            Order::setStatus((int) $delivery['order_id'], $this->companyIdOf($request), 'ready');
            Courier::setStatus($this->courierId($request), $this->companyIdOf($request), 'online');
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        RealtimeService::publish($this->companyIdOf($request), 'delivery.rejected', [
            'delivery_id' => (int) $delivery['id'], 'order_id' => (int) $delivery['order_id'],
        ]);
        return ['status' => 'rejected'];
    }

    /** Marca a retirada no estabelecimento. */
    public function pickup(Request $request): mixed
    {
        $delivery = $this->ownedDelivery($request, ['accepted']);
        Database::execute("UPDATE deliveries SET status='picked', picked_at=NOW() WHERE id = :id", ['id' => $delivery['id']]);
        Order::setStatus((int) $delivery['order_id'], $this->companyIdOf($request), 'picked');
        RealtimeService::publish($this->companyIdOf($request), 'delivery.picked', [
            'delivery_id' => (int) $delivery['id'], 'order_id' => (int) $delivery['order_id'],
        ]);
        return ['status' => 'picked'];
    }

    /** Conclui a entrega (com comprovante/assinatura opcionais) e credita o motoboy. */
    public function complete(Request $request): mixed
    {
        $delivery = $this->ownedDelivery($request, ['picked', 'accepted']);
        $data = Validator::validate($request->all(), [
            'proof_url' => 'string|max:255',
            'signature_url' => 'string|max:255',
            'receiver_name' => 'string|max:150',
            'receiver_document' => 'string|max:30',
            'proof_lat' => 'latitude',
            'proof_lng' => 'longitude',
        ]);
        $cid = $this->courierId($request);
        $companyId = $this->companyIdOf($request);
        $fee = (float) $delivery['courier_fee'];

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE deliveries SET status='delivered', delivered_at=NOW(),
                    proof_url=:p, signature_url=:s, receiver_name=:rn, receiver_document=:rd,
                    proof_lat=:plat, proof_lng=:plng
                 WHERE id=:id",
                [
                    'p' => $data['proof_url'] ?? null,
                    's' => $data['signature_url'] ?? null,
                    'rn' => $data['receiver_name'] ?? null,
                    'rd' => $data['receiver_document'] ?? null,
                    'plat' => $data['proof_lat'] ?? null,
                    'plng' => $data['proof_lng'] ?? null,
                    'id' => $delivery['id'],
                ]
            );
            Order::setStatus((int) $delivery['order_id'], $companyId, 'delivered');
            Database::execute(
                "UPDATE couriers SET status='online', balance = balance + :fee, total_deliveries = total_deliveries + 1 WHERE id = :id",
                ['fee' => $fee, 'id' => $cid]
            );
            Transaction::create([
                'company_id' => $companyId, 'courier_id' => $cid, 'order_id' => (int) $delivery['order_id'],
                'delivery_id' => (int) $delivery['id'], 'type' => 'courier_payout', 'direction' => 'credit',
                'amount' => $fee, 'description' => 'Repasse de entrega #' . $delivery['order_code'],
            ]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        RealtimeService::publish($companyId, 'delivery.delivered', [
            'delivery_id' => (int) $delivery['id'], 'order_id' => (int) $delivery['order_id'], 'courier_id' => $cid,
        ]);
        return ['status' => 'delivered', 'earned' => $fee];
    }

    /** Mensagens do chat de uma corrida (motoboy). */
    public function messages(Request $request): mixed
    {
        $id = (int) $request->param('id');
        $this->ownDeliveryAny($request, $id);
        $after = (int) $request->query('after', '0');
        Message::markRead($id, 'courier');
        return Message::forDelivery($id, $after);
    }

    /** Envia mensagem ao estabelecimento (motoboy). */
    public function sendMessage(Request $request): mixed
    {
        $id = (int) $request->param('id');
        $this->ownDeliveryAny($request, $id);
        $companyId = $this->companyIdOf($request);
        $data = Validator::validate($request->all(), ['body' => 'required|string|max:1000']);
        $msgId = Message::create([
            'company_id' => $companyId,
            'delivery_id' => $id,
            'sender' => 'courier',
            'body' => $data['body'],
        ]);
        $payload = ['id' => $msgId, 'delivery_id' => $id, 'sender' => 'courier', 'body' => $data['body'], 'created_at' => date('Y-m-d H:i:s')];
        RealtimeService::publish($companyId, 'chat.message', $payload);
        Response::success($payload, 201);
        return null;
    }

    /** Garante que a corrida pertence ao motoboy (qualquer status). */
    private function ownDeliveryAny(Request $request, int $deliveryId): void
    {
        $exists = Database::first(
            'SELECT id FROM deliveries WHERE id = :id AND courier_id = :cid LIMIT 1',
            ['id' => $deliveryId, 'cid' => $this->courierId($request)]
        );
        if ($exists === null) {
            throw HttpException::notFound('Corrida não encontrada.');
        }
    }

    /** Ganhos e extrato do motoboy. */
    public function earnings(Request $request): mixed
    {
        $cid = $this->courierId($request);
        $courier = Courier::find($cid);

        $today = Database::first(
            "SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS n FROM transactions
             WHERE courier_id = :c AND direction='credit' AND DATE(created_at)=CURDATE()",
            ['c' => $cid]
        );
        $week = Database::first(
            "SELECT COALESCE(SUM(amount),0) AS total FROM transactions
             WHERE courier_id = :c AND direction='credit' AND created_at >= (NOW() - INTERVAL 7 DAY)",
            ['c' => $cid]
        );
        $statement = Database::select(
            "SELECT t.*, o.code AS order_code FROM transactions t
             LEFT JOIN orders o ON o.id = t.order_id
             WHERE t.courier_id = :c ORDER BY t.created_at DESC LIMIT 40",
            ['c' => $cid]
        );

        // Ranking dentro da empresa (por entregas).
        $rank = Database::first(
            "SELECT COUNT(*) + 1 AS position FROM couriers
             WHERE company_id = :co AND total_deliveries > :td",
            ['co' => (int) $courier['company_id'], 'td' => (int) $courier['total_deliveries']]
        );

        return [
            'balance' => (float) $courier['balance'],
            'today' => (float) ($today['total'] ?? 0),
            'today_count' => (int) ($today['n'] ?? 0),
            'week' => (float) ($week['total'] ?? 0),
            'total_deliveries' => (int) $courier['total_deliveries'],
            'rating' => (float) $courier['rating'],
            'rank' => (int) ($rank['position'] ?? 1),
            'statement' => array_map(static function ($t) {
                $t['amount'] = (float) $t['amount'];
                return $t;
            }, $statement),
        ];
    }

    /* ───────────────── Helpers ───────────────── */

    private function courierId(Request $request): int
    {
        $id = (int) ($request->auth['courier_id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::unauthorized();
        }
        return $id;
    }

    private function companyIdOf(Request $request): int
    {
        return (int) ($request->auth['company_id'] ?? 0);
    }

    /** @param string[] $allowedStatuses */
    private function ownedDelivery(Request $request, array $allowedStatuses): array
    {
        $id = (int) $request->param('id');
        $delivery = Database::first(
            'SELECT d.*, o.code AS order_code FROM deliveries d JOIN orders o ON o.id = d.order_id
             WHERE d.id = :id AND d.courier_id = :cid LIMIT 1',
            ['id' => $id, 'cid' => $this->courierId($request)]
        );
        if ($delivery === null) {
            throw HttpException::notFound('Corrida não encontrada.');
        }
        if (!in_array($delivery['status'], $allowedStatuses, true)) {
            throw HttpException::unprocessable('Ação não permitida para o status atual (' . $delivery['status'] . ').');
        }
        return $delivery;
    }

    private function castDelivery(array $d, ?array $company): array
    {
        $d['id'] = (int) $d['id'];
        $d['order_id'] = (int) $d['order_id'];
        $d['courier_fee'] = (float) $d['courier_fee'];
        $d['order_total'] = (float) $d['order_total'];
        $d['order_lat'] = $d['order_lat'] !== null ? (float) $d['order_lat'] : null;
        $d['order_lng'] = $d['order_lng'] !== null ? (float) $d['order_lng'] : null;
        $d['origin'] = [
            'lat' => $company && $company['lat'] !== null ? (float) $company['lat'] : null,
            'lng' => $company && $company['lng'] !== null ? (float) $company['lng'] : null,
            'name' => $company['name'] ?? null,
            'address' => $company['address'] ?? null,
        ];
        if ($d['order_lat'] !== null && $d['origin']['lat'] !== null) {
            $d['distance_km'] = GeoService::distanceKm($d['origin']['lat'], $d['origin']['lng'], $d['order_lat'], $d['order_lng']);
            $d['eta_minutes'] = GeoService::etaMinutes($d['distance_km']);
        }
        return $d;
    }

    private function publicData(array $c): array
    {
        unset($c['password_hash']);
        $c['id'] = (int) $c['id'];
        $c['company_id'] = (int) $c['company_id'];
        $c['balance'] = (float) $c['balance'];
        $c['rating'] = (float) $c['rating'];
        $c['total_deliveries'] = (int) $c['total_deliveries'];
        $c['is_verified'] = (int) $c['is_verified'] === 1;
        return $c;
    }

    private function tokenResponse(array $courier): array
    {
        $claims = [
            'sub' => (int) $courier['id'],
            'company_id' => (int) $courier['company_id'],
            'name' => $courier['name'],
            'scope' => 'courier',
        ];
        return [
            'access_token' => Jwt::issueAccess($claims),
            'refresh_token' => Jwt::issueRefresh(['sub' => (int) $courier['id'], 'company_id' => (int) $courier['company_id'], 'scope' => 'courier']),
            'token_type' => 'Bearer',
            'expires_in' => Env::int('JWT_TTL', 3600),
            'courier' => $this->publicData($courier),
            'company' => Company::publicTheme((int) $courier['company_id']),
        ];
    }
}

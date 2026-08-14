<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\Company;
use PublishGo\Models\Delivery;
use PublishGo\Models\Message;
use PublishGo\Models\Order;
use PublishGo\Services\DispatchService;
use PublishGo\Services\GeoService;
use PublishGo\Services\RealtimeService;

final class DeliveryController extends Controller
{
    /** Despacha um pedido (manual com courier_id, ou auto pelo mais próximo). */
    public function dispatch(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $data = Validator::validate($request->all(), [
            'order_id' => 'required|integer',
            'type' => 'required|in:manual,auto',
            'courier_id' => 'integer',
        ]);

        if ($data['type'] === 'manual' && empty($data['courier_id'])) {
            throw HttpException::unprocessable('Selecione um motoboy para o despacho manual.', [
                'courier_id' => ['Obrigatório no despacho manual.'],
            ]);
        }

        $delivery = DispatchService::dispatch(
            $companyId,
            (int) $data['order_id'],
            $data['type'],
            isset($data['courier_id']) ? (int) $data['courier_id'] : null
        );

        Response::success($delivery, 201);
        return null;
    }

    /** Dados de rastreamento de uma entrega (posições + rota + ETA). */
    public function track(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $delivery = Delivery::withContext($id, $companyId);
        if ($delivery === null) {
            throw HttpException::notFound('Entrega não encontrada.');
        }

        $company = Company::find($companyId);
        $route = [];
        $distance = $delivery['distance_km'] !== null ? (float) $delivery['distance_km'] : null;
        $eta = $delivery['eta_minutes'] !== null ? (int) $delivery['eta_minutes'] : null;

        // Recalcula distância courier -> cliente em tempo real, se houver posições.
        if ($delivery['courier_lat'] !== null && $delivery['order_lat'] !== null) {
            $distance = GeoService::distanceKm(
                (float) $delivery['courier_lat'],
                (float) $delivery['courier_lng'],
                (float) $delivery['order_lat'],
                (float) $delivery['order_lng']
            );
            $eta = GeoService::etaMinutes($distance);
            $route = [
                ['lat' => (float) $delivery['courier_lat'], 'lng' => (float) $delivery['courier_lng']],
                ['lat' => (float) $delivery['order_lat'], 'lng' => (float) $delivery['order_lng']],
            ];
        }

        return [
            'delivery' => [
                'id' => (int) $delivery['id'],
                'status' => $delivery['status'],
                'order_code' => $delivery['order_code'],
                'customer_name' => $delivery['customer_name'],
                'customer_phone' => $delivery['customer_phone'] ?? null,
                'address' => $delivery['address'],
                'courier_name' => $delivery['courier_name'],
                'courier_phone' => $delivery['courier_phone'],
                'track_token' => $delivery['track_token'] ?? null,
            ],
            'origin' => [
                'lat' => $company['lat'] !== null ? (float) $company['lat'] : null,
                'lng' => $company['lng'] !== null ? (float) $company['lng'] : null,
                'name' => $company['name'],
            ],
            'courier' => [
                'lat' => $delivery['courier_lat'] !== null ? (float) $delivery['courier_lat'] : null,
                'lng' => $delivery['courier_lng'] !== null ? (float) $delivery['courier_lng'] : null,
                'heading' => $delivery['courier_heading'] !== null ? (int) $delivery['courier_heading'] : null,
            ],
            'destination' => [
                'lat' => $delivery['order_lat'] !== null ? (float) $delivery['order_lat'] : null,
                'lng' => $delivery['order_lng'] !== null ? (float) $delivery['order_lng'] : null,
            ],
            'route' => $route,
            'distance_km' => $distance,
            'eta_minutes' => $eta,
        ];
    }

    /** Mensagens do chat de uma entrega (estabelecimento). */
    public function messages(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        if (Delivery::find($id, $companyId) === null) {
            throw HttpException::notFound('Entrega não encontrada.');
        }
        $after = (int) $request->query('after', '0');
        Message::markRead($id, 'establishment');
        return Message::forDelivery($id, $after);
    }

    /** Envia mensagem ao motoboy (estabelecimento). */
    public function sendMessage(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $delivery = Delivery::find($id, $companyId);
        if ($delivery === null) {
            throw HttpException::notFound('Entrega não encontrada.');
        }
        $data = Validator::validate($request->all(), ['body' => 'required|string|max:1000']);
        $msgId = Message::create([
            'company_id' => $companyId,
            'delivery_id' => $id,
            'sender' => 'establishment',
            'body' => $data['body'],
        ]);
        $payload = ['id' => $msgId, 'delivery_id' => $id, 'sender' => 'establishment', 'body' => $data['body'], 'created_at' => date('Y-m-d H:i:s')];
        RealtimeService::publish($companyId, 'chat.message', $payload);
        Response::success($payload, 201);
        return null;
    }

    /** Otimiza a rota para múltiplas entregas pendentes (nearest neighbour). */
    public function optimizeRoute(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $data = Validator::validate($request->all(), [
            'order_ids' => 'required|array',
        ]);

        $company = Company::find($companyId);
        if ($company['lat'] === null || $company['lng'] === null) {
            throw HttpException::unprocessable('Defina o endereço do estabelecimento antes de roteirizar.');
        }

        $stops = [];
        foreach ($data['order_ids'] as $orderId) {
            $order = Order::find((int) $orderId, $companyId);
            if ($order === null || $order['lat'] === null || $order['lng'] === null) {
                continue;
            }
            $stops[] = [
                'order_id' => (int) $order['id'],
                'code' => $order['code'],
                'customer_name' => $order['customer_name'],
                'lat' => (float) $order['lat'],
                'lng' => (float) $order['lng'],
            ];
        }

        $route = GeoService::optimizeRoute(
            ['lat' => (float) $company['lat'], 'lng' => (float) $company['lng']],
            $stops
        );

        $totalKm = 0.0;
        foreach ($route as $stop) {
            $totalKm += (float) ($stop['_leg_km'] ?? 0);
        }

        return [
            'route' => $route,
            'total_km' => round($totalKm, 3),
            'eta_minutes' => GeoService::etaMinutes($totalKm),
        ];
    }
}

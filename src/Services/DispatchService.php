<?php

declare(strict_types=1);

namespace PublishGo\Services;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Models\Company;
use PublishGo\Models\Courier;
use PublishGo\Models\Delivery;
use PublishGo\Models\Order;

/**
 * Orquestra o despacho de pedidos para motoboys (manual e automático),
 * calculando distância, ETA e remuneração do entregador.
 */
final class DispatchService
{
    /**
     * Despacha um pedido. No modo 'auto' escolhe o motoboy online mais próximo.
     *
     * @return array<string,mixed>  A entrega criada/atualizada com contexto.
     */
    public static function dispatch(int $companyId, int $orderId, string $type, ?int $courierId = null): array
    {
        $order = Order::find($orderId, $companyId);
        if ($order === null) {
            throw HttpException::notFound('Pedido não encontrado.');
        }
        if (in_array($order['status'], ['delivered', 'canceled'], true)) {
            throw HttpException::unprocessable('Pedido já finalizado, não pode ser despachado.');
        }
        if (Delivery::activeForOrder($orderId) !== null) {
            throw HttpException::unprocessable('Este pedido já possui uma entrega em andamento.');
        }

        $company = Company::find($companyId);
        $originLat = (float) ($company['lat'] ?? 0);
        $originLng = (float) ($company['lng'] ?? 0);

        $courier = null;
        if ($type === 'auto') {
            $courier = self::nearestCourier($companyId, $originLat, $originLng);
            if ($courier === null) {
                throw HttpException::unprocessable('Nenhum motoboy online disponível no momento.');
            }
        } elseif ($courierId !== null) {
            $courier = Courier::find($courierId, $companyId);
            if ($courier === null) {
                throw HttpException::notFound('Motoboy não encontrado.');
            }
            if ($courier['status'] === 'offline' || $courier['status'] === 'suspended') {
                throw HttpException::unprocessable('Motoboy indisponível.');
            }
        }

        // Distância origem (estabelecimento) -> cliente.
        $distance = 0.0;
        $eta = null;
        if ($order['lat'] !== null && $order['lng'] !== null && $originLat && $originLng) {
            $distance = GeoService::distanceKm($originLat, $originLng, (float) $order['lat'], (float) $order['lng']);
            $eta = GeoService::etaMinutes($distance);
        }

        $deliveryFee = (float) ($order['delivery_fee'] ?: ($company['delivery_fee'] ?? 0));
        $commissionPct = (float) ($company['courier_commission'] ?? 80);
        $courierFee = round($deliveryFee * ($commissionPct / 100), 2);

        Database::beginTransaction();
        try {
            $deliveryId = Delivery::create([
                'company_id' => $companyId,
                'order_id' => $orderId,
                'courier_id' => $courier['id'] ?? null,
                'dispatch_type' => $type,
                'status' => $courier !== null ? 'assigned' : 'pending',
                'track_token' => bin2hex(random_bytes(16)),
                'distance_km' => $distance ?: null,
                'eta_minutes' => $eta,
                'courier_fee' => $courierFee,
                'assigned_at' => $courier !== null ? date('Y-m-d H:i:s') : null,
            ]);

            Order::setStatus($orderId, $companyId, 'dispatched');

            if ($courier !== null) {
                Courier::setStatus((int) $courier['id'], $companyId, 'busy');
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $delivery = Delivery::withContext($deliveryId, $companyId);

        RealtimeService::publish($companyId, 'delivery.dispatched', [
            'delivery_id' => $deliveryId,
            'order_id' => $orderId,
            'courier_id' => $courier['id'] ?? null,
            'eta_minutes' => $eta,
            'distance_km' => $distance,
        ]);

        return $delivery ?? [];
    }

    /**
     * Seleciona o motoboy online mais próximo da origem.
     *
     * @return array<string,mixed>|null
     */
    private static function nearestCourier(int $companyId, float $originLat, float $originLng): ?array
    {
        $online = Courier::online($companyId);
        if ($online === []) {
            return null;
        }
        if (!$originLat || !$originLng) {
            return $online[0];
        }
        $sorted = GeoService::sortByProximity($originLat, $originLng, $online);
        return $sorted[0];
    }
}

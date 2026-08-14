<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\AuditLog;
use PublishGo\Models\Order;
use PublishGo\Models\OrderItem;
use PublishGo\Services\RealtimeService;

final class OrderController extends Controller
{
    private const STATUSES = ['received', 'preparing', 'ready', 'dispatched', 'picked', 'delivered', 'canceled'];

    /** Lista pedidos (com filtros: status, active, search). */
    public function index(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $filters = [
            'status' => $request->query('status'),
            'active' => $request->query('active'),
            'search' => $request->query('search'),
        ];
        $orders = Order::list($companyId, $filters);
        foreach ($orders as &$order) {
            $order = $this->cast($order);
        }
        return $orders;
    }

    /** Detalhe de um pedido com itens. */
    public function show(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $order = Order::find((int) $request->param('id'), $companyId);
        if ($order === null) {
            throw HttpException::notFound('Pedido não encontrado.');
        }
        $order = $this->cast($order);
        $order['items'] = Order::items((int) $order['id']);
        return $order;
    }

    /** Cria um pedido (com itens e múltiplos endereços/observações). */
    public function store(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $data = Validator::validate($request->all(), [
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'string|max:20',
            'address' => 'required|string|max:255',
            'district' => 'string|max:120',
            'lat' => 'latitude',
            'lng' => 'longitude',
            'priority' => 'in:low,normal,high,urgent',
            'payment_method' => 'in:cash,card,pix,online',
            'delivery_fee' => 'numeric',
            'notes' => 'string|max:500',
            'items' => 'array',
        ]);

        $items = is_array($request->input('items')) ? $request->input('items') : [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 1);
        }
        $deliveryFee = (float) ($data['delivery_fee'] ?? 0);
        $total = $subtotal + $deliveryFee;

        Database::beginTransaction();
        try {
            $orderId = Order::create([
                'company_id' => $companyId,
                'code' => Order::nextCode($companyId),
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'address' => $data['address'],
                'district' => $data['district'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'payment_method' => $data['payment_method'] ?? 'pix',
                'source' => 'manual',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                if (empty($item['name'])) {
                    continue;
                }
                OrderItem::create([
                    'order_id' => $orderId,
                    'name' => mb_substr((string) $item['name'], 0, 150),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'notes' => isset($item['notes']) ? mb_substr((string) $item['notes'], 0, 255) : null,
                ]);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $order = $this->cast((array) Order::find($orderId, $companyId));
        AuditLog::record($companyId, $this->userId($request), 'order.create', 'order', $orderId, $request->ip());
        RealtimeService::publish($companyId, 'order.created', $order);

        Response::success($order, 201);
        return null;
    }

    /** Atualiza dados do pedido. */
    public function update(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $order = Order::find($id, $companyId);
        if ($order === null) {
            throw HttpException::notFound('Pedido não encontrado.');
        }

        $data = Validator::validate($request->all(), [
            'customer_name' => 'string|max:150',
            'customer_phone' => 'string|max:20',
            'address' => 'string|max:255',
            'district' => 'string|max:120',
            'lat' => 'latitude',
            'lng' => 'longitude',
            'priority' => 'in:low,normal,high,urgent',
            'payment_method' => 'in:cash,card,pix,online',
            'notes' => 'string|max:500',
        ]);

        if ($data !== []) {
            Order::update($id, $data, $companyId);
        }
        AuditLog::record($companyId, $this->userId($request), 'order.update', 'order', $id, $request->ip());

        $updated = $this->cast((array) Order::find($id, $companyId));
        RealtimeService::publish($companyId, 'order.updated', $updated);
        return $updated;
    }

    /** Atualiza apenas o status do pedido. */
    public function updateStatus(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $data = Validator::validate($request->all(), [
            'status' => 'required|in:' . implode(',', self::STATUSES),
        ]);

        $order = Order::find($id, $companyId);
        if ($order === null) {
            throw HttpException::notFound('Pedido não encontrado.');
        }

        Order::setStatus($id, $companyId, $data['status']);
        AuditLog::record($companyId, $this->userId($request), 'order.status', 'order', $id, $request->ip(), ['to' => $data['status']]);

        $updated = $this->cast((array) Order::find($id, $companyId));
        RealtimeService::publish($companyId, 'order.status', $updated);
        return $updated;
    }

    /** Cancela um pedido. */
    public function cancel(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        $order = Order::find($id, $companyId);
        if ($order === null) {
            throw HttpException::notFound('Pedido não encontrado.');
        }
        Order::setStatus($id, $companyId, 'canceled');
        AuditLog::record($companyId, $this->userId($request), 'order.cancel', 'order', $id, $request->ip());

        $updated = $this->cast((array) Order::find($id, $companyId));
        RealtimeService::publish($companyId, 'order.status', $updated);
        return $updated;
    }

    /** Normaliza tipos numéricos para o JSON de saída. */
    private function cast(array $order): array
    {
        foreach (['id', 'company_id'] as $k) {
            if (isset($order[$k])) {
                $order[$k] = (int) $order[$k];
            }
        }
        foreach (['subtotal', 'delivery_fee', 'total'] as $k) {
            if (isset($order[$k])) {
                $order[$k] = (float) $order[$k];
            }
        }
        foreach (['lat', 'lng'] as $k) {
            $order[$k] = isset($order[$k]) && $order[$k] !== null ? (float) $order[$k] : null;
        }
        return $order;
    }
}

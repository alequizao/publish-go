<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Services\GeoService;

/**
 * Endpoints públicos (sem autenticação) usados em telas abertas,
 * como o auto-cadastro do motoboy.
 */
final class PublicController extends Controller
{
    /** Lista as empresas ativas (para o motoboy escolher onde se cadastrar). */
    public function companies(Request $request): mixed
    {
        $rows = Database::select(
            "SELECT name, slug, logo_url, primary_color FROM companies
             WHERE is_active = 1 AND slug <> 'publish-go'
             ORDER BY name ASC"
        );
        return $rows;
    }

    /**
     * Rastreamento público da entrega pelo token compartilhável.
     * O link "se destrói" ao concluir: quando entregue/cancelado, deixa de
     * transmitir a posição e retorna apenas o estado final.
     */
    public function track(Request $request): mixed
    {
        $token = (string) $request->param('token');
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw HttpException::notFound('Link inválido.');
        }

        $d = Database::first(
            'SELECT d.id, d.status, d.eta_minutes, d.distance_km, d.delivered_at, d.created_at,
                    d.receiver_name, d.receiver_document, d.proof_url,
                    o.code AS order_code, o.customer_name, o.address, o.district, o.total AS order_total,
                    o.lat AS dest_lat, o.lng AS dest_lng,
                    co.name AS company_name, co.logo_url, co.primary_color, co.accent_color, co.phone AS company_phone,
                    co.lat AS origin_lat, co.lng AS origin_lng,
                    c.name AS courier_name, c.vehicle, c.plate, c.rating, c.lat AS courier_lat, c.lng AS courier_lng, c.heading
             FROM deliveries d
             JOIN orders o ON o.id = d.order_id
             JOIN companies co ON co.id = d.company_id
             LEFT JOIN couriers c ON c.id = d.courier_id
             WHERE d.track_token = :t LIMIT 1',
            ['t' => $token]
        );

        if ($d === null) {
            throw HttpException::notFound('Link inválido ou expirado.');
        }

        $concluded = in_array($d['status'], ['delivered', 'canceled'], true);

        $brand = [
            'company_name' => $d['company_name'],
            'logo_url' => $d['logo_url'],
            'primary_color' => $d['primary_color'],
            'accent_color' => $d['accent_color'],
        ];

        if ($concluded) {
            // Entrega finalizada: o link permanece e mostra o comprovante completo.
            return [
                'concluded' => true,
                'status' => $d['status'],
                'order_code' => $d['order_code'],
                'customer_name' => $d['customer_name'],
                'address' => $d['address'],
                'district' => $d['district'],
                'order_total' => (float) $d['order_total'],
                'created_at' => $d['created_at'],
                'delivered_at' => $d['delivered_at'],
                'courier_name' => $d['courier_name'],
                'vehicle' => $d['vehicle'],
                'plate' => $d['plate'],
                'distance_km' => $d['distance_km'] !== null ? (float) $d['distance_km'] : null,
                'receiver_name' => $d['receiver_name'],
                'receiver_document' => $d['receiver_document'],
                'proof_url' => $d['proof_url'],
                'company_phone' => $d['company_phone'],
                'brand' => $brand,
            ];
        }

        $courier = ($d['courier_lat'] !== null) ? [
            'name' => $d['courier_name'],
            'vehicle' => $d['vehicle'],
            'lat' => (float) $d['courier_lat'],
            'lng' => (float) $d['courier_lng'],
            'heading' => $d['heading'] !== null ? (int) $d['heading'] : null,
        ] : null;

        // ETA dinâmico motoboy -> cliente.
        $eta = $d['eta_minutes'] !== null ? (int) $d['eta_minutes'] : null;
        $distance = $d['distance_km'] !== null ? (float) $d['distance_km'] : null;
        if ($courier && $d['dest_lat'] !== null) {
            $distance = GeoService::distanceKm($courier['lat'], $courier['lng'], (float) $d['dest_lat'], (float) $d['dest_lng']);
            $eta = GeoService::etaMinutes($distance);
        }

        return [
            'concluded' => false,
            'status' => $d['status'],
            'order_code' => $d['order_code'],
            'customer_name' => $d['customer_name'],
            'address' => $d['address'],
            'origin' => ['lat' => $d['origin_lat'] !== null ? (float) $d['origin_lat'] : null, 'lng' => $d['origin_lng'] !== null ? (float) $d['origin_lng'] : null],
            'destination' => ['lat' => $d['dest_lat'] !== null ? (float) $d['dest_lat'] : null, 'lng' => $d['dest_lng'] !== null ? (float) $d['dest_lng'] : null],
            'courier' => $courier,
            'eta_minutes' => $eta,
            'distance_km' => $distance,
            'brand' => $brand,
        ];
    }
}

<?php

declare(strict_types=1);

/**
 * Simulador de movimento dos motoboys (para demonstração do mapa em tempo real).
 * Move levemente cada motoboy online/ocupado e publica a nova posição no WebSocket.
 *
 *   php bin/simulate.php             (loop contínuo, ~2s)
 *   php bin/simulate.php once        (uma iteração)
 */

use PublishGo\Core\Database;
use PublishGo\Core\Env;
use PublishGo\Models\Courier;
use PublishGo\Services\RealtimeService;

require __DIR__ . '/../vendor/autoload.php';
Env::load(__DIR__ . '/../.env');

$once = ($argv[1] ?? '') === 'once';

do {
    $couriers = Database::select(
        "SELECT id, company_id, lat, lng, heading, status FROM couriers
         WHERE status IN ('online','busy') AND lat IS NOT NULL AND lng IS NOT NULL"
    );

    foreach ($couriers as $c) {
        // Deslocamento suave (~30-90 metros) numa direção que varia gradualmente.
        $heading = ($c['heading'] ?? random_int(0, 359));
        $heading = ($heading + random_int(-25, 25) + 360) % 360;
        $rad = deg2rad($heading);
        $stepKm = random_int(30, 90) / 1000;
        $dLat = ($stepKm / 111.0) * cos($rad);
        $dLng = ($stepKm / (111.0 * cos(deg2rad((float) $c['lat'])))) * sin($rad);

        $newLat = round((float) $c['lat'] + $dLat, 7);
        $newLng = round((float) $c['lng'] + $dLng, 7);

        Courier::updateLocation((int) $c['id'], (int) $c['company_id'], $newLat, $newLng, $heading);
        RealtimeService::publish((int) $c['company_id'], 'courier.location', [
            'courier_id' => (int) $c['id'],
            'lat' => $newLat,
            'lng' => $newLng,
            'heading' => $heading,
        ]);
    }

    if (!$once) {
        fwrite(STDOUT, '.' );
        sleep(2);
    }
} while (!$once);

if ($once) {
    fwrite(STDOUT, "✓ Posições atualizadas (" . count($couriers) . " motoboys).\n");
}

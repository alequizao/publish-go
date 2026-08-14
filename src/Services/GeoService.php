<?php

declare(strict_types=1);

namespace PublishGo\Services;

/**
 * Cálculos geográficos: distância (Haversine), ETA e ordenação por proximidade.
 */
final class GeoService
{
    private const EARTH_RADIUS_KM = 6371.0;

    /** Distância em km entre dois pontos (lat/lng em graus decimais). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_KM * $c, 3);
    }

    /**
     * Estimativa de tempo de chegada em minutos.
     * Considera velocidade média urbana de moto (~22 km/h) + tolerância.
     */
    public static function etaMinutes(float $distanceKm, float $avgSpeedKmh = 22.0): int
    {
        if ($distanceKm <= 0) {
            return 1;
        }
        $minutes = ($distanceKm / $avgSpeedKmh) * 60;
        // Acréscimo fixo de manuseio/retirada.
        return (int) max(1, ceil($minutes + 3));
    }

    /**
     * Ordena candidatos por distância a um ponto de origem.
     *
     * @param array<int,array<string,mixed>> $candidates  Cada item precisa de lat/lng.
     * @return array<int,array<string,mixed>>  Mesmos itens com chave `_distance_km`, ordenados.
     */
    public static function sortByProximity(float $lat, float $lng, array $candidates): array
    {
        foreach ($candidates as &$c) {
            if ($c['lat'] === null || $c['lng'] === null) {
                $c['_distance_km'] = PHP_FLOAT_MAX;
                continue;
            }
            $c['_distance_km'] = self::distanceKm($lat, $lng, (float) $c['lat'], (float) $c['lng']);
        }
        unset($c);

        usort($candidates, static fn ($a, $b) => $a['_distance_km'] <=> $b['_distance_km']);
        return $candidates;
    }

    /**
     * Roteirização simples por vizinho mais próximo (nearest neighbour) para múltiplos pontos.
     *
     * @param array{lat:float,lng:float} $start
     * @param array<int,array<string,mixed>> $stops  Cada parada com lat/lng.
     * @return array<int,array<string,mixed>>  Paradas reordenadas na melhor sequência aproximada.
     */
    public static function optimizeRoute(array $start, array $stops): array
    {
        $route = [];
        $current = $start;
        $pending = $stops;

        while ($pending !== []) {
            $bestIndex = 0;
            $bestDistance = PHP_FLOAT_MAX;
            foreach ($pending as $i => $stop) {
                $d = self::distanceKm($current['lat'], $current['lng'], (float) $stop['lat'], (float) $stop['lng']);
                if ($d < $bestDistance) {
                    $bestDistance = $d;
                    $bestIndex = $i;
                }
            }
            $next = $pending[$bestIndex];
            $next['_leg_km'] = round($bestDistance, 3);
            $route[] = $next;
            $current = ['lat' => (float) $next['lat'], 'lng' => (float) $next['lng']];
            array_splice($pending, $bestIndex, 1);
        }

        return $route;
    }
}

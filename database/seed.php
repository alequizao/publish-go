<?php

declare(strict_types=1);

/**
 * Popula o banco com dados de demonstração realistas.
 *   php database/seed.php
 *
 * Credenciais geradas:
 *   E-mail: demo@publishgo.com.br
 *   Senha:  publishgo
 */

use PublishGo\Core\Database;
use PublishGo\Core\Env;

require __DIR__ . '/../vendor/autoload.php';
Env::load(__DIR__ . '/../.env');

$pdo = Database::connection();

fwrite(STDOUT, "→ Semeando dados de demonstração...\n");

// Limpa dados anteriores (mantém estrutura).
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['transactions', 'deliveries', 'order_items', 'orders', 'couriers', 'users', 'companies', 'audit_logs'] as $t) {
    $pdo->exec("TRUNCATE TABLE {$t}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ── Empresa demo (Pajuçara, Maceió - AL) ──
$originLat = -9.6679000;
$originLng = -35.7106000;

$pdo->prepare(
    'INSERT INTO companies (name, slug, subdomain, document, phone, email, primary_color, accent_color, theme, plan, address, lat, lng, delivery_fee, courier_commission)
     VALUES (:name, :slug, :subdomain, :doc, :phone, :email, :pc, :ac, :theme, :plan, :addr, :lat, :lng, :fee, :comm)'
)->execute([
    'name' => 'Pizzaria Bella Massa',
    'slug' => 'bella-massa',
    'subdomain' => 'bella-massa',
    'doc' => '12.345.678/0001-90',
    'phone' => '(11) 4002-8922',
    'email' => 'contato@bellamassa.com.br',
    'pc' => '#2563eb',
    'ac' => '#38bdf8',
    'theme' => 'dark',
    'plan' => 'pro',
    'addr' => 'Av. Dr. Antônio Gouveia, 487 - Pajuçara, Maceió - AL',
    'lat' => $originLat,
    'lng' => $originLng,
    'fee' => 7.90,
    'comm' => 80.00,
]);
$companyId = (int) $pdo->lastInsertId();

// ── Usuário do estabelecimento ──
$pdo->prepare(
    'INSERT INTO users (company_id, name, email, password_hash, role) VALUES (:c, :n, :e, :p, :r)'
)->execute([
    'c' => $companyId,
    'n' => 'Alex Oliveira',
    'e' => 'demo@publishgo.com.br',
    'p' => password_hash('publishgo', PASSWORD_BCRYPT),
    'r' => 'establishment',
]);

// ── Central Publish Go + Super-Admin da plataforma ──
$pdo->prepare(
    'INSERT INTO companies (name, slug, subdomain, primary_color, accent_color, theme, plan, address, lat, lng)
     VALUES (:name, :slug, :sub, :pc, :ac, :theme, :plan, :addr, :lat, :lng)'
)->execute([
    'name' => 'Publish Go (Central)',
    'slug' => 'publish-go',
    'sub' => 'central',
    'pc' => '#2563eb',
    'ac' => '#38bdf8',
    'theme' => 'dark',
    'plan' => 'enterprise',
    'addr' => 'Maceió - AL',
    'lat' => $originLat,
    'lng' => $originLng,
]);
$platformId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO users (company_id, name, email, password_hash, role) VALUES (:c, :n, :e, :p, :r)'
)->execute([
    'c' => $platformId,
    'n' => 'Administrador Publish Go',
    'e' => 'admin@publishgo.com.br',
    'p' => password_hash('publishgo', PASSWORD_BCRYPT),
    'r' => 'admin',
]);

// ── Motoboys (alguns online, posicionados ao redor da central, em Maceió) ──
$couriers = [
    ['João Silva', '(82) 98888-1001', 'moto', 'JKL-1A23', 'online', 0.004, -0.003, 4.9, 128],
    ['Marcos Souza', '(82) 98888-1002', 'moto', 'MNB-2C45', 'online', -0.006, 0.005, 4.7, 96],
    ['Pedro Lima', '(82) 98888-1003', 'moto', 'QRS-3D67', 'online', 0.008, 0.007, 4.8, 211],
    ['Lucas Rocha', '(82) 98888-1004', 'bike', 'TUV-4E89', 'busy', -0.003, -0.008, 4.6, 64],
    ['Rafael Dias', '(82) 98888-1005', 'moto', 'WXY-5F01', 'offline', 0.012, -0.010, 4.5, 152],
];
$courierIds = [];
$stmt = $pdo->prepare(
    'INSERT INTO couriers (company_id, name, phone, email, password_hash, vehicle, plate, status, lat, lng, heading, rating, balance, total_deliveries, is_verified, last_seen_at)
     VALUES (:c, :n, :ph, :em, :pw, :v, :pl, :st, :lat, :lng, :hd, :rt, :bal, :td, 1, NOW())'
);
$courierPass = password_hash('publishgo', PASSWORD_BCRYPT);
foreach ($couriers as $i => $c) {
    $stmt->execute([
        'c' => $companyId, 'n' => $c[0], 'ph' => $c[1],
        'em' => 'motoboy' . ($i + 1) . '@publishgo.com.br', 'pw' => $courierPass,
        'v' => $c[2], 'pl' => $c[3], 'st' => $c[4],
        'lat' => $originLat + $c[5], 'lng' => $originLng + $c[6], 'hd' => random_int(0, 359),
        'rt' => $c[7], 'bal' => random_int(8000, 45000) / 100, 'td' => $c[8],
    ]);
    $courierIds[] = (int) $pdo->lastInsertId();
}

// ── Pedidos demo distribuídos por bairros de Maceió ──
$districts = ['Pajuçara', 'Ponta Verde', 'Jatiúca', 'Mangabeiras', 'Farol', 'Centro', 'Jacarecica', 'Poço'];
$streets = ['Av. Álvaro Otacílio', 'R. Jangadeiros Alagoanos', 'Av. Dr. Antônio Gouveia', 'R. Epaminondas Gracindo', 'Av. Comendador Leão', 'R. Sá e Albuquerque', 'Av. Roberto Cortizo'];
$customers = ['Maria Fernanda', 'Carlos Eduardo', 'Ana Beatriz', 'Roberto Carlos', 'Juliana Paes', 'Bruno Gomes', 'Camila Reis', 'Diego Nunes'];
$statuses = ['received', 'preparing', 'ready', 'dispatched', 'delivered', 'delivered', 'delivered', 'canceled'];
$products = [
    ['Pizza Margherita G', 54.90], ['Pizza Calabresa G', 56.90], ['Pizza Portuguesa M', 48.90],
    ['Refrigerante 2L', 12.00], ['Pizza Quatro Queijos G', 62.90], ['Esfiha de Carne', 6.50],
];

$orderStmt = $pdo->prepare(
    'INSERT INTO orders (company_id, code, customer_name, customer_phone, address, district, lat, lng, status, priority, source, payment_method, subtotal, delivery_fee, total, notes, created_at, delivered_at)
     VALUES (:c, :code, :cn, :cp, :addr, :dist, :lat, :lng, :st, :pr, :src, :pm, :sub, :fee, :tot, :notes, :created, :delivered)'
);
$itemStmt = $pdo->prepare(
    'INSERT INTO order_items (order_id, name, quantity, unit_price) VALUES (:o, :n, :q, :p)'
);

for ($i = 0; $i < 24; $i++) {
    $status = $statuses[array_rand($statuses)];
    $nItems = random_int(1, 3);
    $subtotal = 0.0;
    $chosen = [];
    for ($j = 0; $j < $nItems; $j++) {
        $p = $products[array_rand($products)];
        $qty = random_int(1, 2);
        $subtotal += $p[1] * $qty;
        $chosen[] = [$p[0], $qty, $p[1]];
    }
    $fee = 7.90;
    $createdAgo = random_int(0, 23 * 3600);
    $created = date('Y-m-d H:i:s', time() - $createdAgo);
    $delivered = $status === 'delivered'
        ? date('Y-m-d H:i:s', time() - $createdAgo + random_int(1200, 3000))
        : null;

    $orderStmt->execute([
        'c' => $companyId,
        'code' => (string) (1001 + $i),
        'cn' => $customers[array_rand($customers)],
        'cp' => '(82) 9' . random_int(7000, 9999) . '-' . random_int(1000, 9999),
        'addr' => $streets[array_rand($streets)] . ', ' . random_int(10, 1999) . ' - Maceió - AL',
        'dist' => $districts[array_rand($districts)],
        'lat' => $originLat + (random_int(-90, 90) / 1000),
        'lng' => $originLng + (random_int(-90, 90) / 1000),
        'st' => $status,
        'pr' => ['normal', 'normal', 'high', 'urgent', 'low'][array_rand(['normal', 'normal', 'high', 'urgent', 'low'])],
        'src' => ['manual', 'manual', 'ifood', 'api'][array_rand(['manual', 'manual', 'ifood', 'api'])],
        'pm' => ['pix', 'card', 'cash', 'online'][array_rand(['pix', 'card', 'cash', 'online'])],
        'sub' => $subtotal,
        'fee' => $fee,
        'tot' => $subtotal + $fee,
        'notes' => random_int(0, 1) ? 'Sem cebola, por favor.' : null,
        'created' => $created,
        'delivered' => $delivered,
    ]);
    $orderId = (int) $pdo->lastInsertId();
    foreach ($chosen as $item) {
        $itemStmt->execute(['o' => $orderId, 'n' => $item[0], 'q' => $item[1], 'p' => $item[2]]);
    }

    // Cria entregas + transações para pedidos entregues.
    if ($status === 'delivered') {
        $courierId = $courierIds[array_rand($courierIds)];
        $courierFee = round($fee * 0.8, 2);
        $pdo->prepare(
            'INSERT INTO deliveries (company_id, order_id, courier_id, dispatch_type, status, distance_km, eta_minutes, courier_fee, assigned_at, accepted_at, picked_at, delivered_at, created_at)
             VALUES (:c, :o, :cr, :dt, :st, :d, :eta, :cf, :assigned, :accepted, :picked, :delivered, :created)'
        )->execute([
            'c' => $companyId, 'o' => $orderId, 'cr' => $courierId,
            'dt' => ['manual', 'auto'][array_rand(['manual', 'auto'])], 'st' => 'delivered',
            'd' => random_int(800, 6000) / 1000, 'eta' => random_int(8, 35), 'cf' => $courierFee,
            'assigned' => $created, 'accepted' => $created, 'picked' => $created,
            'delivered' => $delivered, 'created' => $created,
        ]);
        $deliveryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO transactions (company_id, courier_id, order_id, delivery_id, type, direction, amount, description, created_at)
             VALUES (:c, :cr, :o, :dl, :tp, :dir, :amt, :desc, :created)'
        )->execute([
            'c' => $companyId, 'cr' => $courierId, 'o' => $orderId, 'dl' => $deliveryId,
            'tp' => 'courier_payout', 'dir' => 'credit', 'amt' => $courierFee,
            'desc' => 'Repasse de entrega #' . (1001 + $i), 'created' => $delivered,
        ]);
    }
}

fwrite(STDOUT, "✓ Central : Publish Go (id {$platformId})\n");
fwrite(STDOUT, "✓ Empresa : Pizzaria Bella Massa (id {$companyId}) — Maceió/AL\n");
fwrite(STDOUT, "✓ Super-Admin... admin@publishgo.com.br / publishgo\n");
fwrite(STDOUT, "✓ Estabelecimento demo@publishgo.com.br / publishgo\n");
fwrite(STDOUT, "✓ " . count($courierIds) . " motoboys, 24 pedidos e entregas criados.\n");
fwrite(STDOUT, "✓ Seed concluído.\n");

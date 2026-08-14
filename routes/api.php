<?php

declare(strict_types=1);

/**
 * Definição das rotas da API REST do Publish Go.
 * Variável $router (instância de PublishGo\Core\Router) disponibilizada pelo kernel.
 *
 * @var \PublishGo\Core\Router $router
 */

use PublishGo\Controllers\AdminController;
use PublishGo\Controllers\AuthController;
use PublishGo\Controllers\CatalogController;
use PublishGo\Controllers\CompanyController;
use PublishGo\Controllers\CouponController;
use PublishGo\Controllers\CourierAuthController;
use PublishGo\Controllers\CourierController;
use PublishGo\Controllers\DashboardController;
use PublishGo\Controllers\DeliveryController;
use PublishGo\Controllers\LookupController;
use PublishGo\Controllers\OrderController;
use PublishGo\Controllers\PlanLimitController;
use PublishGo\Controllers\PromotionController;
use PublishGo\Controllers\PublicController;
use PublishGo\Controllers\ReportController;
use PublishGo\Controllers\StorefrontController;
use PublishGo\Controllers\UploadController;

$router->group(['prefix' => '/api', 'middleware' => ['cors', 'throttle']], function ($router) {

    // Saúde do serviço.
    $router->get('/health', fn () => ['status' => 'ok', 'service' => 'publish-go', 'time' => date('c')]);

    // Autenticação do estabelecimento/admin (pública).
    $router->post('/auth/register', [AuthController::class, 'register']);
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/refresh', [AuthController::class, 'refresh']);

    // Endpoints públicos.
    $router->get('/public/companies', [PublicController::class, 'companies']);
    // Upload de documentos no auto-cadastro do motoboy (antes de ter conta).
    $router->post('/public/upload', [UploadController::class, 'image']);
    // Rastreamento público da entrega pelo cliente (link compartilhável).
    $router->get('/public/track/{token}', [PublicController::class, 'track']);

    // Loja pública (storefront) por empresa — sem autenticação.
    $router->get('/shop/{slug}', [StorefrontController::class, 'show']);
    $router->post('/shop/{slug}/coupon', [StorefrontController::class, 'coupon']);
    $router->post('/shop/{slug}/checkout', [StorefrontController::class, 'checkout']);

    // Autenticação e auto-cadastro do motoboy (pública).
    $router->post('/courier/login', [CourierAuthController::class, 'login']);
    $router->post('/courier/register', [CourierAuthController::class, 'register']);

    // Rotas protegidas por JWT.
    $router->group(['middleware' => ['auth']], function ($router) {
        $router->get('/auth/me', [AuthController::class, 'me']);

        // Dashboard.
        $router->get('/dashboard/summary', [DashboardController::class, 'summary']);
        $router->get('/dashboard/chart', [DashboardController::class, 'chart']);

        // Pedidos.
        $router->get('/orders', [OrderController::class, 'index']);
        $router->post('/orders', [OrderController::class, 'store']);
        $router->get('/orders/{id}', [OrderController::class, 'show']);
        $router->put('/orders/{id}', [OrderController::class, 'update']);
        $router->patch('/orders/{id}/status', [OrderController::class, 'updateStatus']);
        $router->delete('/orders/{id}', [OrderController::class, 'cancel']);

        // Motoboys (gestão pelo estabelecimento).
        $router->get('/couriers', [CourierController::class, 'index']);
        $router->get('/couriers/online', [CourierController::class, 'online']);
        $router->post('/couriers', [CourierController::class, 'store']);
        $router->put('/couriers/{id}', [CourierController::class, 'update']);
        $router->delete('/couriers/{id}', [CourierController::class, 'destroy']);
        $router->get('/couriers/{id}/financial', [CourierController::class, 'financial']);
        $router->post('/couriers/{id}/payments', [CourierController::class, 'registerPayment']);
        $router->post('/couriers/{id}/location', [CourierController::class, 'updateLocation']);
        $router->patch('/couriers/{id}/status', [CourierController::class, 'updateStatus']);

        // Upload de imagens (logo etc.).
        $router->post('/upload', [UploadController::class, 'image']);

        // Entregas / despacho / rotas.
        $router->post('/deliveries/dispatch', [DeliveryController::class, 'dispatch']);
        $router->get('/deliveries/{id}/track', [DeliveryController::class, 'track']);
        $router->post('/deliveries/optimize-route', [DeliveryController::class, 'optimizeRoute']);
        $router->get('/deliveries/{id}/messages', [DeliveryController::class, 'messages']);
        $router->post('/deliveries/{id}/messages', [DeliveryController::class, 'sendMessage']);

        // Empresa (whitelabel).
        $router->get('/company', [CompanyController::class, 'show']);
        $router->put('/company', [CompanyController::class, 'update']);

        // Consultas externas (CEP / CNPJ).
        $router->get('/lookup/cep/{cep}', [LookupController::class, 'cep']);
        $router->get('/lookup/cnpj/{cnpj}', [LookupController::class, 'cnpj']);

        // Catálogo (produtos, categorias, complementos, estoque) — escopado por empresa.
        $router->get('/catalog/limits', [CatalogController::class, 'limits']);
        $router->get('/catalog/categories', [CatalogController::class, 'categories']);
        $router->post('/catalog/categories', [CatalogController::class, 'storeCategory']);
        $router->put('/catalog/categories/{id}', [CatalogController::class, 'updateCategory']);
        $router->delete('/catalog/categories/{id}', [CatalogController::class, 'deleteCategory']);
        $router->get('/catalog/products', [CatalogController::class, 'products']);
        $router->post('/catalog/products', [CatalogController::class, 'storeProduct']);
        $router->get('/catalog/products/{id}', [CatalogController::class, 'showProduct']);
        $router->put('/catalog/products/{id}', [CatalogController::class, 'updateProduct']);
        $router->delete('/catalog/products/{id}', [CatalogController::class, 'deleteProduct']);
        $router->post('/catalog/products/{id}/groups', [CatalogController::class, 'storeGroup']);
        $router->delete('/catalog/groups/{groupId}', [CatalogController::class, 'deleteGroup']);
        $router->post('/catalog/groups/{groupId}/options', [CatalogController::class, 'storeOption']);
        $router->delete('/catalog/options/{optionId}', [CatalogController::class, 'deleteOption']);

        // Cupons e promoções.
        $router->get('/coupons', [CouponController::class, 'index']);
        $router->post('/coupons', [CouponController::class, 'store']);
        $router->put('/coupons/{id}', [CouponController::class, 'update']);
        $router->delete('/coupons/{id}', [CouponController::class, 'delete']);
        $router->get('/promotions', [PromotionController::class, 'index']);
        $router->post('/promotions', [PromotionController::class, 'store']);
        $router->put('/promotions/{id}', [PromotionController::class, 'update']);
        $router->delete('/promotions/{id}', [PromotionController::class, 'delete']);

        // Relatórios do proprietário.
        $router->get('/reports/sales', [ReportController::class, 'sales']);
        $router->get('/reports/top-products', [ReportController::class, 'topProducts']);
        $router->get('/reports/by-payment', [ReportController::class, 'byPayment']);
        $router->get('/reports/stock', [ReportController::class, 'stock']);
        $router->get('/reports/coupons', [ReportController::class, 'coupons']);

        // Central Publish Go (super-admin — guard de papel no controller).
        $router->get('/admin/overview', [AdminController::class, 'overview']);
        $router->get('/admin/companies', [AdminController::class, 'companies']);
        $router->post('/admin/companies', [AdminController::class, 'createCompany']);
        $router->put('/admin/companies/{id}', [AdminController::class, 'updateCompany']);
        $router->patch('/admin/companies/{id}/toggle', [AdminController::class, 'toggleCompany']);
        $router->post('/admin/companies/{id}/impersonate', [AdminController::class, 'impersonate']);

        // Limites por plano (configuráveis pelo super-admin).
        $router->get('/admin/plan-limits', [PlanLimitController::class, 'index']);
        $router->put('/admin/plan-limits/{plan}', [PlanLimitController::class, 'update']);
    });

    // App do motoboy (token de escopo 'courier').
    $router->group(['middleware' => ['courier']], function ($router) {
        $router->get('/courier/me', [CourierAuthController::class, 'me']);
        $router->patch('/courier/status', [CourierAuthController::class, 'setStatus']);
        $router->post('/courier/location', [CourierAuthController::class, 'location']);
        $router->get('/courier/deliveries', [CourierAuthController::class, 'deliveries']);
        $router->post('/courier/deliveries/{id}/accept', [CourierAuthController::class, 'accept']);
        $router->post('/courier/deliveries/{id}/reject', [CourierAuthController::class, 'reject']);
        $router->post('/courier/deliveries/{id}/pickup', [CourierAuthController::class, 'pickup']);
        $router->post('/courier/deliveries/{id}/complete', [CourierAuthController::class, 'complete']);
        $router->get('/courier/deliveries/{id}/messages', [CourierAuthController::class, 'messages']);
        $router->post('/courier/deliveries/{id}/messages', [CourierAuthController::class, 'sendMessage']);
        $router->post('/courier/upload', [UploadController::class, 'image']);
        $router->get('/courier/earnings', [CourierAuthController::class, 'earnings']);
    });
});

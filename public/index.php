<?php

declare(strict_types=1);

/*
 * Publish Go · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * Front controller do Publish Go.
 * Aponte o docroot do servidor web para este diretório (public/).
 */

use PublishGo\Core\App;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

$app = new App($basePath);
$app->loadRoutes($basePath . '/routes/api.php');
$app->run();

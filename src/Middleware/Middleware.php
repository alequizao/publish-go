<?php

declare(strict_types=1);

/*
 * Publish Go · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
namespace PublishGo\Middleware;

use PublishGo\Core\Request;

interface Middleware
{
    /**
     * Executa o middleware. Deve lançar HttpException para interromper a cadeia.
     */
    public function handle(Request $request): void;
}

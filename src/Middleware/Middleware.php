<?php

declare(strict_types=1);

namespace PublishGo\Middleware;

use PublishGo\Core\Request;

interface Middleware
{
    /**
     * Executa o middleware. Deve lançar HttpException para interromper a cadeia.
     */
    public function handle(Request $request): void;
}

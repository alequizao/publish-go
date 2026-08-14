<?php

declare(strict_types=1);

namespace PublishGo\Core;

use RuntimeException;

/**
 * Exceção que carrega um status HTTP — capturada pelo kernel para virar resposta JSON.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string,mixed> $details */
    public static function unprocessable(string $message, array $details = []): self
    {
        return new self($message, 422, $details);
    }

    public static function unauthorized(string $message = 'Não autenticado.'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Acesso negado.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Recurso não encontrado.'): self
    {
        return new self($message, 404);
    }
}

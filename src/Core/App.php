<?php

declare(strict_types=1);

namespace PublishGo\Core;

use PublishGo\Middleware\AuthMiddleware;
use PublishGo\Middleware\CorsMiddleware;
use PublishGo\Middleware\CourierAuthMiddleware;
use PublishGo\Middleware\Middleware;
use PublishGo\Middleware\RateLimitMiddleware;
use Throwable;

/**
 * Kernel da aplicação: carrega ambiente, monta rotas, despacha middlewares e controllers.
 */
final class App
{
    private Router $router;

    /** @var array<string,class-string<Middleware>> */
    private array $middlewareAliases = [
        'cors' => CorsMiddleware::class,
        'auth' => AuthMiddleware::class,
        'courier' => CourierAuthMiddleware::class,
        'throttle' => RateLimitMiddleware::class,
    ];

    public function __construct(private readonly string $basePath)
    {
        Env::load($basePath . '/.env');
        date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Sao_Paulo') ?? 'UTC');
        Request::setBasePath(Env::get('APP_BASE', '') ?? '');
        $this->router = new Router();
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function loadRoutes(string $file): void
    {
        $router = $this->router;
        require $file;
    }

    public function run(): void
    {
        $request = Request::capture();

        // Pré-flight CORS.
        if ($request->method === 'OPTIONS') {
            (new CorsMiddleware())->handle($request);
            http_response_code(204);
            return;
        }

        try {
            $match = $this->router->match($request->method, $request->path);

            if ($match === null) {
                if ($this->router->pathExists($request->path)) {
                    Response::error('Método não permitido.', 405);
                } else {
                    Response::error('Endpoint não encontrado.', 404);
                }
                return;
            }

            $request->setParams($match['params']);

            foreach ($match['middleware'] as $alias) {
                $class = $this->middlewareAliases[$alias] ?? null;
                if ($class === null) {
                    continue;
                }
                /** @var Middleware $mw */
                $mw = new $class();
                $mw->handle($request);
            }

            $result = $this->dispatch($match['handler'], $request);

            // Se o controller já emitiu a resposta, nada a fazer.
            if ($result !== null) {
                Response::success($result);
            }
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->status, $e->details);
        } catch (Throwable $e) {
            $debug = Env::bool('APP_DEBUG', false);
            $message = $debug ? $e->getMessage() : 'Erro interno do servidor.';
            $details = $debug ? ['file' => $e->getFile(), 'line' => $e->getLine()] : [];
            Response::error($message, 500, $details);
        }
    }

    /**
     * @param mixed $handler  [ControllerClass::class, 'method'] ou Closure.
     */
    private function dispatch(mixed $handler, Request $request): mixed
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            return $controller->{$method}($request);
        }
        if (is_callable($handler)) {
            return $handler($request);
        }
        throw new HttpException('Handler de rota inválido.', 500);
    }
}

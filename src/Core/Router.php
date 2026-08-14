<?php

declare(strict_types=1);

namespace PublishGo\Core;

/**
 * Roteador com suporte a parâmetros nomeados ({id}), grupos e middlewares.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:string[],handler:mixed,middleware:string[]}> */
    private array $routes = [];

    /** @var array{prefix:string,middleware:string[]} */
    private array $groupStack = ['prefix' => '', 'middleware' => []];

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @param array{prefix?:string,middleware?:string[]} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $previous = $this->groupStack;
        $this->groupStack = [
            'prefix' => $previous['prefix'] . ($attributes['prefix'] ?? ''),
            'middleware' => array_merge($previous['middleware'], $attributes['middleware'] ?? []),
        ];
        $callback($this);
        $this->groupStack = $previous;
    }

    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $fullPath = $this->groupStack['prefix'] . $path;
        $fullPath = '/' . trim($fullPath, '/');
        if ($fullPath === '/') {
            $fullPath = '/';
        }

        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $fullPath);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $fullPath,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middleware' => array_merge($this->groupStack['middleware'], $middleware),
        ];
    }

    /**
     * @return array{handler:mixed,params:array<string,string>,middleware:string[]}|null
     */
    public function match(string $method, string $path): ?array
    {
        $path = '/' . trim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? '';
                }
                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }
        return null;
    }

    /** Verifica se o caminho existe sob outro método (para responder 405). */
    public function pathExists(string $path): bool
    {
        $path = '/' . trim($path, '/');
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path)) {
                return true;
            }
        }
        return false;
    }
}

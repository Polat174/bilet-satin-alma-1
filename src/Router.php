<?php
declare(strict_types=1);

namespace App;

class Router
{
    /** @var array<string,array<string,callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        // 1) Exact match
        if (isset($this->routes[$method][$path])) {
            ($this->routes[$method][$path])();
            return;
        }

        // 2) Parameterized routes like /ticket/{pnr}
        foreach ($this->routes[$method] ?? [] as $routePath => $handler) {
            if (strpos($routePath, '{') === false) {
                continue;
            }
            $regex = preg_replace('/\{[^}]+\}/', '([^/]+)', $routePath);
            $regex = '#^' . $regex . '$#';
            if ($regex && preg_match($regex, $path, $matches)) {
                array_shift($matches);
                $handler(...$matches);
                return;
            }
        }

        http_response_code(404);
        echo 'Not Found';
    }
}



<?php
namespace SOI\Core;

/**
 * Router - Simple URL router for the frontend
 */
class Router {
    private array $routes = [];
    private ?string $currentRoute = null;

    public function add(string $pattern, callable $handler, string $method = 'GET'): void {
        $this->routes[] = compact('pattern', 'handler', 'method');
    }

    public function dispatch(string $uri, string $method = 'GET'): bool {
        $uri = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '', '/');

        foreach ($this->routes as $route) {
            if (strtoupper($route['method']) !== strtoupper($method) && $route['method'] !== 'ANY') {
                continue;
            }

            $pattern = $this->buildRegex($route['pattern']);
            if (preg_match($pattern, $uri, $matches)) {
                $this->currentRoute = $route['pattern'];
                array_shift($matches);
                call_user_func_array($route['handler'], $matches);
                return true;
            }
        }
        return false;
    }

    private function buildRegex(string $pattern): string {
        $pattern = preg_replace('/\{([a-z_]+)\}/', '([^/]+)', $pattern);
        $pattern = preg_replace('/\{([a-z_]+):([^}]+)\}/', '($2)', $pattern);
        return '#^' . $pattern . '$#i';
    }

    public function getCurrentRoute(): ?string {
        return $this->currentRoute;
    }

    /** Get the current request URI (from mod_rewrite or REQUEST_URI) */
    public static function getUri(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($base && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }
        $uri = strtok($uri, '?') ?: '/';
        if ($uri === '/index.php') {
            return '/';
        }
        return '/' . trim($uri, '/');
    }
}

<?php
// src/Router/Router.php
declare(strict_types=1);

namespace App\Router;

class Router
{
    protected array $routes = [];

    public function get(string $path, $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, $handler): void { $this->add('POST', $path, $handler); }
    public function put(string $path, $handler): void { $this->add('PUT', $path, $handler); }
    public function patch(string $path, $handler): void { $this->add('PATCH', $path, $handler); }
    public function delete(string $path, $handler): void { $this->add('DELETE', $path, $handler); }
    public function any(string $path, $handler): void { $this->add('*', $path, $handler); }

    protected function add(string $method, string $path, $handler): void
    {
        $method = strtoupper($method);
        $this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler];
    }

    public function dispatch(string $requestMethod, string $requestPath): void
    {
        $requestMethod = strtoupper($requestMethod);
        $requestPath = rtrim($requestPath, '/');
        if ($requestPath === '') $requestPath = '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== '*' && $route['method'] !== $requestMethod) {
                continue;
            }

            $pattern = $this->convertPathToRegex($route['path']);
            if (preg_match($pattern, $requestPath, $matches)) {
                // extract params in order of named capture groups
                $params = $this->extractParams($matches);
                $this->invokeHandler($route['handler'], $params);
                return;
            }
        }

        // not found
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not Found']);
    }

    protected function convertPathToRegex(string $path): string
    {
        // normalize
        $p = rtrim($path, '/');
        if ($p === '') $p = '/';

        // replace {param} with named capture groups
        $regex = preg_replace_callback('#\{([^/]+)\}#', function($m){
            $name = preg_replace('#[^a-zA-Z0-9_]#', '', $m[1]);
            return '(?P<' . $name . '>[^/]+)';
        }, $p);

        // allow optional trailing slash equivalence
        return '#^' . $regex . '$#';
    }

    protected function extractParams(array $matches): array
    {
        $params = [];
        foreach ($matches as $k => $v) {
            if (!is_int($k)) {
                $params[$k] = $v;
            }
        }
        return $params;
    }

    protected function invokeHandler($handler, array $params): void
    {
        // callable (closure)
        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }

        // 'Controller@method'
        if (is_string($handler) && strpos($handler, '@') !== false) {
            [$class, $method] = explode('@', $handler, 2);
            if (!class_exists($class)) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => "Handler class {$class} not found"]);
                return;
            }
            $controller = new $class();
            if (!method_exists($controller, $method)) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => "Handler method {$method} not found in {$class}"]);
                return;
            }
            // call with params (maintains order)
            call_user_func_array([$controller, $method], array_values($params));
            return;
        }

        // unsupported handler type
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid route handler']);
    }
}

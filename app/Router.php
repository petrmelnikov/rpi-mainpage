<?php

namespace App;

class Router
{
    private array $routes = [];

    public function parse(array $server): RouteDataDto
    {
        $method = $server['REQUEST_METHOD'];
        $path = $server['REQUEST_URI'];

        $path = trim($path, '/');

        $routeData = new RouteDataDto();

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && trim($route['path'], '/') === $path) {
                $routeData->handler = $route['handler'];
                $routeData->templatePath = $route['template'];

                return $routeData;
            }
        }

        $routeData->handler = function () {
            return '404 Not Found';
        };

        return $routeData;
    }

    public function addRoute(string $method, string $path, callable $handler, string $template = '')
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'template' => $template
        ];
    }
}
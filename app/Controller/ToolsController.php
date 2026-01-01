<?php

namespace App\Controller;

use App\Router;

class ToolsController
{
    public function registerRoutes(Router $router, string $appRoot): void
    {
        $router->addRoute('GET', '/tools', [$this, 'tools'], $appRoot . '/templates/tools.html.php');
        $router->addRoute('GET', '/tools/example1', [$this, 'example1'], $appRoot . '/templates/example1.html.php');
        $router->addRoute('GET', '/tools/example2', [$this, 'example2'], $appRoot . '/templates/example2.html.php');
    }

    public function tools(): array
    {
        return [];
    }

    public function example1(): array
    {
        return [];
    }

    public function example2(): array
    {
        return [];
    }
}

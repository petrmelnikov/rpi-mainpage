<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Router;
use App\TemplateRenderer;
use App\MenuBuilder;
use App\App;
use App\Controller\FileIndexController;
use App\Controller\SettingsController;
use App\Controller\SystemController;
use App\Controller\ToolsController;
use App\Controller\YouTubePlayerController;

$app = App::getInstance();
$app->appRoot = __DIR__;

$topMainMenu = (new MenuBuilder())->buildMenuArray();

$router = new Router();

(new SystemController())->registerRoutes($router, $app->appRoot);
(new ToolsController())->registerRoutes($router, $app->appRoot);
(new FileIndexController())->registerRoutes($router, $app->appRoot);
(new SettingsController())->registerRoutes($router, $app->appRoot);
(new YouTubePlayerController())->registerRoutes($router, $app->appRoot);

$routeDataDto = $router->parse($_SERVER);
$handler = $routeDataDto->handler;

//$content = file_get_contents(__DIR__ . '/templates/top_main_menu.html.php');
$content = TemplateRenderer::render($app->appRoot . '/templates/top_main_menu.html.php', ['topMainMenu' => $topMainMenu]);
if (!empty($routeDataDto->templatePath)){
    $content .= TemplateRenderer::render($routeDataDto->templatePath, $handler());
} else {
    $content .= $handler();
}
$content .= file_get_contents($app->appRoot . '/templates/bottom_main_menu.html.php');

echo TemplateRenderer::render($app->appRoot . '/web/index.html.php', ['body' => $content]);

<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Router;
use App\ShellCommandExecutor;
use App\TemplateRenderer;
use App\MenuBuilder;
use App\App;

$app = App::getInstance();
$app->appRoot = __DIR__;

$topMainMenu = (new MenuBuilder())->buildMenuArray();

$router = new Router();

$router->addRoute('GET', '', function () {
    return ['shellCommandRawContent' => array_merge(
        ShellCommandExecutor::executeWithSplitByLines('landscape-sysinfo'),
        ShellCommandExecutor::executeWithSplitByLines("df -h | grep 'usb' 2>&1")
    )];
}, $app->appRoot . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/top', function () {
    $command = 'top -b -n 1 2>&1 | head -20 2>&1';
    return ['shellCommandRawContent' => ShellCommandExecutor::executeWithSplitByLines($command)];
}, $app->appRoot . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/update-code', function () {
    return ['shellCommandRawContent' => array_merge(
        ShellCommandExecutor::executeWithSplitByLines('ssh -T git@github.com 2>&1'),
        ShellCommandExecutor::executeWithSplitByLines('git pull 2>&1'),
        ShellCommandExecutor::executeWithSplitByLines('composer install 2>&1')
    )];
}, $app->appRoot . '/templates/shell_command_raw_content.html.php');

$routeDataDto = $router->parse($_SERVER);
$handler = $routeDataDto->handler;

//$content = file_get_contents(__DIR__ . '/templates/top_main_menu.html.php');
$content = TemplateRenderer::render($app->appRoot . '/templates/top_main_menu.html.php', ['menuItems' => $topMainMenu]);
if (!empty($routeDataDto->templatePath)){
    $content .= TemplateRenderer::render($routeDataDto->templatePath, $handler());
} else {
    $content .= $handler();
}
$content .= file_get_contents($app->appRoot . '/templates/bottom_main_menu.html.php');

echo TemplateRenderer::render($app->appRoot . '/web/index.html.php', ['body' => $content]);
<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Router;
use App\ShellCommandExecutor;
use App\TemplateRenderer;

$router = new Router();

$router->addRoute('GET', '', function () {
    return ['shellCommandRawContent' => [
        ShellCommandExecutor::executeWithSplitByLines('landscape-sysinfo'),
        ShellCommandExecutor::executeWithSplitByLines("df -h | grep 'usb' 2>&1")
    ]];
}, __DIR__ . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/top', function () {
    $command = 'top -b -n 1 2>&1 | head -20 2>&1';
    return ['shellCommandRawContent' => ShellCommandExecutor::executeWithSplitByLines($command)];
}, __DIR__ . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/git-pull', function () {
    $command = 'git pull 2>&1';
    return ['shellCommandRawContent' => ShellCommandExecutor::execute($command)];
}, __DIR__ . '/templates/shell_command_raw_content.html.php');

$routeDataDto = $router->parse($_SERVER);
$handler = $routeDataDto->handler;

$content = file_get_contents(__DIR__ . '/templates/top_main_menu.html.php');
if (!empty($routeDataDto->templatePath)){
    $content .= TemplateRenderer::render($routeDataDto->templatePath, $handler());
} else {
    $content .= $handler();
}
$content .= file_get_contents(__DIR__ . '/templates/bottom_main_menu.html.php');

echo TemplateRenderer::render(__DIR__ . '/web/index.html.php', ['body' => $content]);
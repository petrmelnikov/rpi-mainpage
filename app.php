<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Router;
use App\ShellCommandExecutor;
use App\TemplateRenderer;

$router = new Router();

$router->addRoute('GET', '', function () {
    return ['shellCommandRawContent' =>
        ShellCommandExecutor::multipleExecute('landscape-sysinfo', "df -h | grep 'usb' 2>&1")];
}, __DIR__ . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/top', function () {
    $command = 'top -b -n 1 2>&1 | head -20 2>&1';
    return ['shellCommandRawContent' => ShellCommandExecutor::multipleExecute($command)];
}, __DIR__ . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/update-code', function () {
    return ['shellCommandRawContent' =>
        ShellCommandExecutor::multipleExecute('git pull 2>&1', 'composer install 2>&1')];
}, __DIR__ . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/json-test', function () {
    return ['key' => 'value'];
});


$routeDataDto = $router->parse($_SERVER);
$handler = $routeDataDto->handler;


header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Access-Control-Request-Method');

if (empty($routeDataDto->templatePath)) {
    header('Content-Type: application/json');
    echo json_encode($handler());
    return;
}

$content = file_get_contents(__DIR__ . '/templates/top_main_menu.html.php');
if (!empty($routeDataDto->templatePath)){
    $content .= TemplateRenderer::render($routeDataDto->templatePath, $handler());
} else {
    $content .= $handler();
}
$content .= file_get_contents(__DIR__ . '/templates/bottom_main_menu.html.php');

echo TemplateRenderer::render(__DIR__ . '/web/index.html.php', ['body' => $content]);
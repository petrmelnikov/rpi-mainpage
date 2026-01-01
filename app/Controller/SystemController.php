<?php

namespace App\Controller;

use App\Router;
use App\ShellCommandExecutor;

class SystemController
{
    public function registerRoutes(Router $router, string $appRoot): void
    {
        $router->addRoute('GET', '', [$this, 'index'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/top', [$this, 'top'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/update-code', [$this, 'updateCode'], $appRoot . '/templates/shell_command_raw_content.html.php');
    }

    public function index(): array
    {
        return ['shellCommandRawContent' => array_merge(
            ShellCommandExecutor::executeWithSplitByLines('landscape-sysinfo 2>&1'),
            ShellCommandExecutor::executeWithSplitByLines("df -h | grep 'usb' 2>&1")
        )];
    }

    public function top(): array
    {
        $command = 'top -b -n 1 2>&1 | head -20 2>&1';
        return ['shellCommandRawContent' => ShellCommandExecutor::executeWithSplitByLines($command)];
    }

    public function updateCode(): array
    {
        return ['shellCommandRawContent' => array_merge(
            ShellCommandExecutor::executeWithSplitByLines('ssh -T git@github.com 2>&1'),
            ShellCommandExecutor::executeWithSplitByLines('git pull 2>&1'),
            ShellCommandExecutor::executeWithSplitByLines('composer install 2>&1')
        )];
    }
}

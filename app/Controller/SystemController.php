<?php

namespace App\Controller;

use App\Router;
use App\ShellCommandExecutor;

class SystemController
{
    private string $appRoot = '';

    public function registerRoutes(Router $router, string $appRoot): void
    {
        $this->appRoot = $appRoot;

        $router->addRoute('GET', '', [$this, 'index'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/top', [$this, 'top'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/update-code', [$this, 'updateCode'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('POST', '/update-code', [$this, 'updateCode'], $appRoot . '/templates/shell_command_raw_content.html.php');
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
        $isShellOverSsh = getenv('SHELL_OVER_SSH') === '1';

        $localAppRoot = $this->appRoot !== '' ? $this->appRoot : getcwd();
        $remoteAppRoot = getenv('SSH_REMOTE_APP_DIR') ?: '/apps/rpi-mainpage';
        $targetAppRoot = $isShellOverSsh ? $remoteAppRoot : $localAppRoot;
        $appRootArg = escapeshellarg($targetAppRoot);

        $wrapCommand = static function (string $command): string {
            // In Docker SSH mode we already connect as SSH_REMOTE_USER (default: ubuntu),
            // so extra sudo wrapping may fail and break pull/composer commands.
            if (getenv('SHELL_OVER_SSH') === '1') {
                return $command;
            }

            // Legacy non-SSH mode: keep previous behavior.
            return 'sudo -n -u ubuntu -H bash -lc ' . escapeshellarg($command);
        };

        $pathPrefix = 'PATH=/usr/local/bin:/usr/bin:/bin:$PATH ';

        return ['shellCommandRawContent' => array_merge(
            ShellCommandExecutor::executeWithSplitByLines($wrapCommand(
                $pathPrefix . 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new" git -C ' . $appRootArg . ' pull --ff-only 2>&1'
            )),
            ShellCommandExecutor::executeWithSplitByLines($wrapCommand(
                $pathPrefix . 'if command -v composer >/dev/null 2>&1; then composer --working-dir=' . $appRootArg . ' install 2>&1; elif [ -x /usr/local/bin/composer ]; then /usr/local/bin/composer --working-dir=' . $appRootArg . ' install 2>&1; elif [ -x /usr/bin/composer ]; then /usr/bin/composer --working-dir=' . $appRootArg . ' install 2>&1; else if [ -d ' . $appRootArg . '/vendor ]; then echo "composer not found; skipping (vendor/ exists)"; else echo "composer not found; install it (e.g. sudo apt-get install composer)"; fi; fi'
            ))
        )];
    }
}

<?php

namespace App\Controller;

use App\Router;
use App\ShellCommandExecutor;

class SystemController
{
    private string $appRoot = '';

    private static function sanitizeShellLines(array $lines): array
    {
        $clean = [];
        foreach ($lines as $line) {
            $line = (string)$line;
            if (preg_match('/^declare -x\s+/', $line) === 1) {
                continue;
            }
            $clean[] = $line;
        }

        return $clean;
    }

    public function registerRoutes(Router $router, string $appRoot): void
    {
        $this->appRoot = $appRoot;

        $router->addRoute('GET', '', [$this, 'index'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/top', [$this, 'top'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/update-code', [$this, 'updateCode'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('POST', '/update-code', [$this, 'updateCode'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/rebuild-containers', [$this, 'rebuildContainers'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('POST', '/rebuild-containers', [$this, 'rebuildContainers'], $appRoot . '/templates/shell_command_raw_content.html.php');
    }

    public function index(): array
    {
        $stripAnsi = static function (string $line): string {
            return (string)preg_replace('/\x1b\[[0-9;]*m/', '', $line);
        };

        $isRawUsbDfLine = static function (string $line) use ($stripAnsi): bool {
            $plain = trim($stripAnsi($line));
            // Raw df format example:
            // /dev/nvme0n1 3907029168 3701562200 202327592 95% /media/usb_ssd
            return preg_match('/^\S+\s+\d+\s+\d+\s+\d+\s+\d+%\s+\/media\/usb/i', $plain) === 1;
        };

        $humanizeKib = static function (float $kib): string {
            $bytes = $kib * 1024.0;
            $units = ['B', 'K', 'M', 'G', 'T', 'P'];
            $i = 0;
            while ($bytes >= 1024.0 && $i < count($units) - 1) {
                $bytes /= 1024.0;
                $i++;
            }

            if ($bytes >= 10 || $i === 0) {
                return (string)round($bytes) . $units[$i];
            }

            return number_format($bytes, 1, '.', '') . $units[$i];
        };

        $normalizeUsbDfLine = static function (string $line) use ($stripAnsi, $isRawUsbDfLine, $humanizeKib): string {
            $plain = trim($stripAnsi($line));
            if (!$isRawUsbDfLine($plain)) {
                return $plain;
            }

            if (!preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+%)\s+(\/media\/usb\S*)$/i', $plain, $m)) {
                return $plain;
            }

            $device = $m[1];
            $size = $humanizeKib((float)$m[2]);
            $used = $humanizeKib((float)$m[3]);
            $avail = $humanizeKib((float)$m[4]);
            $usep = $m[5];
            $mount = $m[6];

            return $device . '  ' . $size . '  ' . $used . '  ' . $avail . '  ' . $usep . '  ' . $mount;
        };

        $sysInfoLines = self::sanitizeShellLines(
            ShellCommandExecutor::executeWithSplitByLines('landscape-sysinfo 2>&1')
        );

        // landscape-sysinfo may print raw 1K-block disk values for USB mounts.
        // Hide those lines and show explicit human-readable df output below.
        $sysInfoLines = array_values(array_filter($sysInfoLines, static fn(string $line): bool => !$isRawUsbDfLine($line)));

        // Use the same command user runs manually for consistency.
        $usbDiskLines = self::sanitizeShellLines(
            ShellCommandExecutor::executeWithSplitByLines("df -h | grep 'usb' 2>&1")
        );

        $usbDiskLines = array_values(array_filter($usbDiskLines, static fn(string $line): bool => trim($line) !== ''));
        $usbDiskLines = array_values(array_map(static fn(string $line): string => $normalizeUsbDfLine($line), $usbDiskLines));

        $allLines = $sysInfoLines;
        if (count($usbDiskLines) > 0) {
            $allLines[] = '';
            $allLines[] = 'USB mounts (df -h):';
            foreach ($usbDiskLines as $line) {
                $allLines[] = $line;
            }
        }

        return ['shellCommandRawContent' => $allLines];
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

        $wrapCommand = static function (string $command) use ($isShellOverSsh): string {
            // In Docker SSH mode we already connect as SSH_REMOTE_USER (default: ubuntu),
            // so extra sudo wrapping may fail and break pull/composer commands.
            if ($isShellOverSsh) {
                return $command;
            }

            // Legacy non-SSH mode: keep previous behavior.
            return 'sudo -n -u ubuntu -H bash -lc ' . escapeshellarg($command);
        };

        $pathPrefix = 'export PATH=/usr/local/bin:/usr/bin:/bin:$PATH; ';

        return ['shellCommandRawContent' => array_merge(
            self::sanitizeShellLines(ShellCommandExecutor::executeWithSplitByLines($wrapCommand(
                $pathPrefix . 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new" git -C ' . $appRootArg . ' pull --ff-only 2>&1'
            ))),
            self::sanitizeShellLines(ShellCommandExecutor::executeWithSplitByLines($wrapCommand(
                $pathPrefix . 'if command -v composer >/dev/null 2>&1; then composer --working-dir=' . $appRootArg . ' install 2>&1; elif [ -x /usr/local/bin/composer ]; then /usr/local/bin/composer --working-dir=' . $appRootArg . ' install 2>&1; elif [ -x /usr/bin/composer ]; then /usr/bin/composer --working-dir=' . $appRootArg . ' install 2>&1; else if [ -d ' . $appRootArg . '/vendor ]; then echo "composer not found; skipping (vendor/ exists)"; else echo "composer not found; install it (e.g. sudo apt-get install composer)"; fi; fi'
            )))
        )];
    }

    public function rebuildContainers(): array
    {
        $isShellOverSsh = getenv('SHELL_OVER_SSH') === '1';

        $localAppRoot = $this->appRoot !== '' ? $this->appRoot : getcwd();
        $remoteAppRoot = getenv('SSH_REMOTE_APP_DIR') ?: '/apps/rpi-mainpage';
        $targetAppRoot = $isShellOverSsh ? $remoteAppRoot : $localAppRoot;
        $appRootArg = escapeshellarg($targetAppRoot);

        $wrapCommand = static function (string $command) use ($isShellOverSsh): string {
            if ($isShellOverSsh) {
                return $command;
            }

            return 'sudo -n -u ubuntu -H bash -lc ' . escapeshellarg($command);
        };

        $pathPrefix = 'export PATH=/usr/local/bin:/usr/bin:/bin:$PATH; ';

        $command = $pathPrefix
            . 'if sudo -n docker compose version >/dev/null 2>&1; then '
            . 'sudo -n docker compose -f ' . $appRootArg . '/docker-compose.yml up --build -d 2>&1; '
            . 'elif sudo -n docker-compose version >/dev/null 2>&1; then '
            . 'sudo -n docker-compose -f ' . $appRootArg . '/docker-compose.yml up --build -d 2>&1; '
            . 'elif docker compose version >/dev/null 2>&1; then '
            . 'docker compose -f ' . $appRootArg . '/docker-compose.yml up --build -d 2>&1 || '
            . 'echo "docker compose failed (check docker group for this user)"; '
            . 'elif command -v docker-compose >/dev/null 2>&1; then '
            . 'docker-compose -f ' . $appRootArg . '/docker-compose.yml up --build -d 2>&1 || '
            . 'echo "docker-compose failed (check docker group for this user)"; '
            . 'else echo "docker compose not found or not permitted for current user"; fi';

        return ['shellCommandRawContent' => self::sanitizeShellLines(
            ShellCommandExecutor::executeWithSplitByLines($wrapCommand($command))
        )];
    }
}

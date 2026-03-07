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
        $command = '(TERM=dumb COLUMNS=512 top -b -n 1 2>&1 || '
            . 'ps -eo pid,ppid,user,%cpu,%mem,comm,args --sort=-%cpu 2>&1) | head -20';

        // Always prefer host-side execution for /top (via SSH wrapper when available)
        // so system metrics represent the actual host, not the PHP container.
        return ['shellCommandRawContent' => ShellCommandExecutor::executeWithSplitByLines($command, true)];
    }

    public function updateCode(): array
    {
        $localAppRoot = $this->appRoot !== '' ? $this->appRoot : getcwd();
        $targetAppRoot = ShellCommandExecutor::resolveTargetAppRoot($localAppRoot);
        $appRootArg = escapeshellarg($targetAppRoot);

        $pathPrefix = 'export PATH=/usr/local/bin:/usr/bin:/bin:$PATH; ';

        return ['shellCommandRawContent' => array_merge(
            self::sanitizeShellLines(ShellCommandExecutor::executeWithSplitByLines(ShellCommandExecutor::wrapForAppShell(
                $pathPrefix . 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new" git -C ' . $appRootArg . ' pull --ff-only 2>&1'
            ))),
            self::sanitizeShellLines(ShellCommandExecutor::executeWithSplitByLines(ShellCommandExecutor::wrapForAppShell(
                $pathPrefix . 'if command -v composer >/dev/null 2>&1; then composer --working-dir=' . $appRootArg . ' install 2>&1; elif [ -x /usr/local/bin/composer ]; then /usr/local/bin/composer --working-dir=' . $appRootArg . ' install 2>&1; elif [ -x /usr/bin/composer ]; then /usr/bin/composer --working-dir=' . $appRootArg . ' install 2>&1; else if [ -d ' . $appRootArg . '/vendor ]; then echo "composer not found; skipping (vendor/ exists)"; else echo "composer not found; install it (e.g. sudo apt-get install composer)"; fi; fi'
            )))
        )];
    }

    public function rebuildContainers(): array
    {
        $localAppRoot = $this->appRoot !== '' ? $this->appRoot : getcwd();
        $targetAppRoot = ShellCommandExecutor::resolveTargetAppRoot($localAppRoot);
        $appRootArg = escapeshellarg($targetAppRoot);

        $pathPrefix = 'export PATH=/usr/local/bin:/usr/bin:/bin:$PATH; ';

        $logFile = '/tmp/rpi-mainpage-rebuild.log';
        $composeRun = 'cd ' . $appRootArg
            . ' && if sudo -n docker compose version >/dev/null 2>&1; then '
            . 'sudo -n docker compose up --build -d; '
            . 'elif sudo -n docker-compose version >/dev/null 2>&1; then '
            . 'sudo -n docker-compose up --build -d; '
            . 'elif docker compose version >/dev/null 2>&1; then '
            . 'docker compose up --build -d; '
            . 'elif command -v docker-compose >/dev/null 2>&1; then '
            . 'docker-compose up --build -d; '
            . 'else echo "docker compose not found or not permitted for current user"; exit 1; fi';

        $bg = 'nohup sh -lc ' . escapeshellarg($composeRun . ' >> ' . escapeshellarg($logFile) . ' 2>&1')
            . ' >/dev/null 2>&1 < /dev/null & echo "Rebuild started in background. Log: ' . $logFile . '"';

        $lines = self::sanitizeShellLines(
            ShellCommandExecutor::executeWithSplitByLines(ShellCommandExecutor::wrapForAppShell($pathPrefix . $bg))
        );

        $lines[] = 'Tip: open server shell and run: tail -f ' . $logFile;

        return ['shellCommandRawContent' => $lines];
    }
}

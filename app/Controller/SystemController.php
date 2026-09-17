<?php

namespace App\Controller;

use App\Router;
use App\ShellCommandExecutor;

class SystemController
{
    private const TOP_REFRESH_SECONDS = 2;

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
        $router->addRoute('GET', '/system-info', [$this, 'systemInfo']);
        $router->addRoute('GET', '/top', [$this, 'top'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/top/live', [$this, 'topLive']);
        $router->addRoute('GET', '/update-code', [$this, 'updateCode'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('POST', '/update-code', [$this, 'updateCode'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('GET', '/rebuild-containers', [$this, 'rebuildContainers'], $appRoot . '/templates/shell_command_raw_content.html.php');
        $router->addRoute('POST', '/rebuild-containers', [$this, 'rebuildContainers'], $appRoot . '/templates/shell_command_raw_content.html.php');
    }

    public function index(): array
    {
        return ['shellCommandRawContent' => ['Loading system information…']];
    }

    public function systemInfo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode([
            'ok' => true,
            'lines' => $this->getSystemInfoLines(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function getSystemInfoLines(): array
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

        $allLines[] = '';
        $allLines[] = 'SSD temperature:';
        foreach ($this->getSsdTemperatureLines() as $line) {
            $allLines[] = $line;
        }

        return $allLines;
    }

    private function getSsdTemperatureLines(): array
    {
        try {
            $command = self::ssdTemperatureCommand();
        } catch (\InvalidArgumentException $e) {
            return ['configuration error: ' . $e->getMessage()];
        }

        $raw = self::sanitizeShellLines(
            ShellCommandExecutor::executeWithSplitByLines($command)
        );
        $raw = array_values(array_filter($raw, static fn(string $line): bool => trim($line) !== ''));

        return self::parseSsdTemperatureLines($raw);
    }

    private static function ssdTemperatureCommand(): string
    {
        $deviceTypeCases = '';
        foreach (self::ssdSmartctlDeviceTypes() as $device => $type) {
            $deviceTypeCases .= '    ' . escapeshellarg($device) . ') smart_type='
                . escapeshellarg($type) . ' ;;' . "\n";
        }

        $command = <<<'SH'
            export PATH="$PATH:/usr/sbin:/sbin"

            drive_temperature_lines() {
              grep -i -E '^[[:space:]]*(190|194)[[:space:]]|(^|[[:space:]])(current drive |composite )?temperature[[:space:]]*:|temperature_celsius|airflow_temperature'
            }

            for hwmon in /sys/class/hwmon/hwmon*; do
              [ -f "$hwmon/name" ] || continue
              name=$(cat "$hwmon/name" 2>/dev/null) || continue
              devlink=''
              if [ -e "$hwmon/device" ]; then
                devlink=$(readlink -f "$hwmon/device" 2>/dev/null)
              fi
              for input in "$hwmon"/temp*_input; do
                [ -f "$input" ] || continue
                label=$(cat "${input%_input}_label" 2>/dev/null)
                value=$(cat "$input" 2>/dev/null)
                echo "HWMON|${name}|${label}|${value}|${input}|${devlink}"
              done
            done
            if command -v nvme >/dev/null 2>&1; then
              for dev in /dev/nvme*n*; do
                [ -b "$dev" ] || continue
                printf '%s\n' "${dev##*/}" | grep -Eq '^nvme[0-9]+n[0-9]+$' || continue
                line=$( { sudo -n nvme smart-log "$dev" 2>/dev/null || nvme smart-log "$dev" 2>/dev/null; } | grep -i -m1 -E '^[[:space:]]*temperature[[:space:]]*:')
                echo "NVME|${dev}|${line}"
              done
            fi
            if command -v smartctl >/dev/null 2>&1; then
              for dev in /dev/nvme*n* /dev/sd*; do
                [ -b "$dev" ] || continue
                printf '%s\n' "${dev##*/}" | grep -Eq '^(nvme[0-9]+n[0-9]+|sd[a-z]+)$' || continue
                smart_type=''
                case "$dev" in
            __SSD_DEVICE_TYPE_CASES__
                esac
                if [ -n "$smart_type" ]; then
                  out=$(sudo -n smartctl -d "$smart_type" -A "$dev" 2>/dev/null || smartctl -d "$smart_type" -A "$dev" 2>/dev/null)
                else
                  out=$(sudo -n smartctl -A "$dev" 2>/dev/null || smartctl -A "$dev" 2>/dev/null)
                fi
                lines=$(printf '%s\n' "$out" | drive_temperature_lines)
                if [ -z "$lines" ] && [ -z "$smart_type" ]; then
                  case "$dev" in
                    /dev/sd*)
                      out=$(sudo -n smartctl -d sat -A "$dev" 2>/dev/null || smartctl -d sat -A "$dev" 2>/dev/null)
                      lines=$(printf '%s\n' "$out" | drive_temperature_lines)
                      ;;
                  esac
                fi
                printf '%s\n' "$lines" | while IFS= read -r line; do
                  printf 'SMART|%s|%s\n' "$dev" "$line"
                done
              done
            fi
            SH;

        return str_replace('__SSD_DEVICE_TYPE_CASES__', rtrim($deviceTypeCases), $command);
    }

    /** @return array<string, string> */
    private static function ssdSmartctlDeviceTypes(): array
    {
        $raw = trim((string)getenv('SSD_SMARTCTL_DEVICE_TYPES'));
        if ($raw === '') {
            return [];
        }

        try {
            $types = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('SSD_SMARTCTL_DEVICE_TYPES must be a JSON object mapping device paths to smartctl types.', 0, $e);
        }
        if (!$types instanceof \stdClass) {
            throw new \InvalidArgumentException('SSD_SMARTCTL_DEVICE_TYPES must be a JSON object mapping device paths to smartctl types.');
        }

        $allowed = ['sat', 'scsi', 'sntjmicron', 'sntrealtek', 'sntasmedia'];
        foreach ($types as $device => $type) {
            if (preg_match('~\A/dev/(?:sd[a-z]+|nvme[0-9]+n[0-9]+)\z~', (string)$device) !== 1
                || !is_string($type) || !in_array($type, $allowed, true)) {
                throw new \InvalidArgumentException('SSD_SMARTCTL_DEVICE_TYPES requires whole-disk paths such as /dev/sda or /dev/nvme0n1 and one of: ' . implode(', ', $allowed) . '.');
            }
        }

        return (array)$types;
    }

    /**
     * @param string[] $rawLines
     * @return string[]
     */
    private static function parseSsdTemperatureLines(array $rawLines): array
    {
        $display = [];

        foreach ($rawLines as $rawLine) {
            $line = trim((string)$rawLine);
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line);
            $kind = $parts[0] ?? '';
            if ($kind === 'HWMON' && count($parts) >= 6) {
                [, $name, $label, $milli, $input, $devlink] = $parts;
                if (preg_match('/nvme|drivetemp/i', $name . ' ' . $devlink) !== 1) {
                    continue;
                }
                if (preg_match('/^-?\d+$/', trim($milli)) !== 1) {
                    continue;
                }
                $celsius = ((float)$milli) / 1000.0;
                if ($celsius < -40.0 || $celsius > 125.0) {
                    continue;
                }
                $label = trim($label);
                if ($label === '') {
                    $label = preg_match('/([^\/]+)_input$/', $input, $m) === 1 ? $m[1] : 'temp';
                }
                $deviceId = trim($devlink) !== '' ? trim($devlink) : dirname($input);
                $sensorId = $deviceId . '|' . basename($input);
                $display['HWMON|' . $sensorId] = sprintf('%s [%s] (%s): %.1f°C', trim($name), basename($deviceId), $label, $celsius);
            } elseif (($kind === 'NVME' || $kind === 'SMART') && count($parts) >= 2) {
                $dev = $parts[1];
                $match = implode('|', array_slice($parts, 2));
                $temp = self::extractDriveTemperature($match);
                if ($temp === null) {
                    continue;
                }
                $source = $kind === 'NVME' ? 'nvme smart-log' : 'smartctl';
                // Keep the first valid candidate for each source/device. A later
                // ATA attribute can still supply a reading when an earlier one is invalid.
                $key = $kind . '|' . $dev;
                if (!isset($display[$key])) {
                    $display[$key] = sprintf('%s: %s°C (%s)', $dev, $temp, $source);
                }
            }
        }

        $display = array_values($display);

        if (count($display) === 0) {
            return ['unavailable (no drive temperature sensor detected)'];
        }

        return $display;
    }

    private static function extractDriveTemperature(string $match): ?string
    {
        $match = trim($match);
        if ($match === '' || stripos($match, 'time') !== false) {
            return null;
        }

        // NVMe style: "Temperature: 40 Celsius" / "temperature : 42°C".
        if (preg_match('/temperature\s*:\s*([+-]?[0-9]+(?:\.[0-9]+)?)/i', $match, $m) === 1) {
            return self::sanitizeCelsius($m[1]);
        }

        // ATA columns: ID NAME FLAG VALUE WORST THRESH TYPE UPDATED WHEN_FAILED RAW_VALUE.
        // WHEN_FAILED can be '-', 'In_the_past' or 'FAILING_NOW'.
        $columns = preg_split('/\s+/', $match);
        if (count($columns) >= 10 && preg_match('/^[0-9]+$/', $columns[0]) === 1
            && (in_array($columns[0], ['190', '194'], true) || stripos($columns[1], 'temperature') !== false)
            && preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/', $columns[9]) === 1) {
            return self::sanitizeCelsius($columns[9]);
        }

        return null;
    }

    private static function sanitizeCelsius(string $value): ?string
    {
        $celsius = (float)$value;
        if ($celsius < -40.0 || $celsius > 125.0) {
            return null;
        }

        return $value;
    }

    public function top(): array
    {
        // Always prefer host-side execution for /top (via SSH wrapper when available)
        // so system metrics represent the actual host, not the PHP container.
        return ['shellCommandRawContent' => $this->getTopSnapshot()];
    }

    public function topLive(): string
    {
        ignore_user_abort(false);
        set_time_limit(0);

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        // Release every PHP output buffer so each event reaches nginx/browser
        // immediately instead of being held until the request ends.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        echo "retry: 3000\n";
        echo ": top live stream\n\n";
        flush();

        $sequence = 0;
        while (!connection_aborted()) {
            $lines = $this->getTopSnapshot();
            $output = rtrim(implode("\n", $lines), "\n");
            $payload = json_encode([
                'output' => $output,
                'sequence' => ++$sequence,
                'timestamp' => date(DATE_ATOM),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($payload === false) {
                $payload = '{"output":"Unable to encode top output"}';
            }

            echo 'id: ' . $sequence . "\n";
            echo "event: snapshot\n";
            echo 'data: ' . $payload . "\n\n";
            flush();

            if (connection_aborted()) {
                break;
            }

            sleep(self::TOP_REFRESH_SECONDS);
        }

        exit;
    }

    private function getTopSnapshot(): array
    {
        $command = '(TERM=dumb COLUMNS=512 top -b -n 1 2>&1 || '
            . 'ps -eo pid,ppid,user,%cpu,%mem,comm,args --sort=-%cpu 2>&1) | head -20';

        return ShellCommandExecutor::executeWithSplitByLines($command, true);
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

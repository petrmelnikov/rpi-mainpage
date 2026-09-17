#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Controller\SystemController;

require_once dirname(__DIR__) . '/app/Controller/SystemController.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$parser = new ReflectionMethod(SystemController::class, 'parseSsdTemperatureLines');
$collector = new ReflectionMethod(SystemController::class, 'ssdTemperatureCommand');
$reader = new ReflectionMethod(SystemController::class, 'getSsdTemperatureLines');
$parse = static fn(array $lines): array => $parser->invoke(null, $lines);
$unavailable = ['unavailable (no drive temperature sensor detected)'];
$originalTypes = getenv('SSD_SMARTCTL_DEVICE_TYPES');
$root = sys_get_temp_dir() . '/rpi-mainpage-ssd-test-' . bin2hex(random_bytes(6));

$removeTree = static function (string $path) use (&$removeTree): void {
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $removeTree($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
};

$run = static function (array $argv, string $input, array $env = []): array {
    $pipes = [];
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start test shell');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

mkdir($root . '/bin', 0700, true);
mkdir($root . '/dev', 0700);
mkdir($root . '/hwmon', 0700);

try {
    putenv('SSD_SMARTCTL_DEVICE_TYPES');
    $assert($parse([]) === $unavailable, 'Absent sensors must remain unavailable');
    $assert($parse(['SMART|/dev/sda|not a temperature']) === $unavailable, 'Malformed output must be ignored');
    $assert($parse(['NVME|/dev/nvme0n1|temperature : 42 C']) === ['/dev/nvme0n1: 42°C (nvme smart-log)'], 'NVMe Celsius must parse');
    $assert($parse(['SMART|/dev/sda|Current Drive Temperature: 37 C']) === ['/dev/sda: 37°C (smartctl)'], 'SCSI temperature must parse');
    $assert($parse(['SMART|/dev/sda|Temperature: -5 Celsius']) === ['/dev/sda: -5°C (smartctl)'], 'Signed Celsius must parse');
    $assert($parse(['NVME|/dev/nvme0n1|temperature : 999 C']) === $unavailable, 'Invalid Celsius must be rejected');
    $assert($parse(['SMART|/dev/sda|Warning Composite Temperature Time: 42']) === $unavailable, 'Elapsed time is not a temperature');
    foreach (['-', 'In_the_past', 'FAILING_NOW'] as $status) {
        $line = "SMART|/dev/sda|194 Temperature_Celsius 0x0022 064 052 000 Old_age Always $status -3 (Min/Max -5/48)";
        $assert($parse([$line]) === ['/dev/sda: -3°C (smartctl)'], "ATA raw value must parse with WHEN_FAILED=$status");
    }

    $first = 'HWMON|nvme|Composite|42000|/sys/class/hwmon/hwmon0/temp1_input|/sys/devices/nvme/nvme0';
    $second = 'HWMON|nvme|Composite|42000|/sys/class/hwmon/hwmon1/temp1_input|/sys/devices/nvme/nvme1';
    $sensor = 'HWMON|nvme|Sensor 1|42000|/sys/class/hwmon/hwmon0/temp2_input|/sys/devices/nvme/nvme0';
    $readings = $parse([$first, $second, $first, $sensor]);
    $assert(count($readings) === 3, 'Two equal-temperature devices and multiple sensors must survive deduplication');
    $assert(str_contains($readings[0], '[nvme0]') && str_contains($readings[1], '[nvme1]'), 'hwmon output must identify devices');
    $assert($parse(['HWMON|cpu|Package|42000|/sys/class/hwmon/hwmon2/temp1_input|/sys/devices/cpu']) === $unavailable, 'CPU sensors must not become SSD readings');
    $assert($parse(['HWMON|nvme|Composite|999999|/sys/class/hwmon/hwmon0/temp1_input|/sys/devices/nvme/nvme0']) === $unavailable, 'Invalid hwmon values must be rejected');
    $fallback = $parse([
        'HWMON|drivetemp||36000|/sys/class/hwmon/hwmon2/temp1_input|',
        'HWMON|drivetemp||36000|/sys/class/hwmon/hwmon3/temp1_input|',
    ]);
    $assert(count($fallback) === 2 && str_contains($fallback[0], '[hwmon2]'), 'Missing devlink must fall back to distinct hwmon identities');

    // Fail sudo locally; mock smartctl/nvme handle every invocation, never real disks.
    file_put_contents($root . '/bin/sudo', "#!/bin/sh\nexit 1\n");
    file_put_contents($root . '/bin/nvme', "#!/bin/sh\nprintf '%s\\n' 'temperature : 43 C'\n");
    file_put_contents($root . '/bin/smartctl', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$SSD_TEST_LOG"
case "$SSD_TEST_MODE" in
  scsi) printf '%s\n' 'Current Drive Temperature: 37 C' ;;
  past) printf '%s\n' '190 Airflow_Temperature_Cel 0x0022 065 044 045 Old_age Always In_the_past 35 (Min/Max 25/40)' '194 Temperature_Celsius 0x0022 064 052 000 Old_age Always - 35' ;;
  failing) printf '%s\n' '194 Temperature_Celsius 0x0022 001 001 045 Old_age Always FAILING_NOW 75' ;;
  invalid-first) printf '%s\n' '190 Airflow_Temperature_Cel 0x0022 065 044 045 Old_age Always - 999' '194 Temperature_Celsius 0x0022 064 052 000 Old_age Always - 36' ;;
  sat) if [ "$1" = '-d' ] && [ "$2" = 'sat' ]; then printf '%s\n' '194 Temperature_Celsius 0x0022 064 052 000 Old_age Always - 36'; else exit 1; fi ;;
  bridge) if [ "$1" = '-d' ] && [ "$2" = "$SSD_TEST_TYPE" ]; then printf '%s\n' 'Temperature: 39 Celsius'; else exit 1; fi ;;
  none) exit 1 ;;
  *) printf '%s\n' '194 Temperature_Celsius 0x0022 064 052 000 Old_age Always - 36' ;;
esac
SH
    );
    foreach (['sudo', 'nvme', 'smartctl'] as $tool) {
        chmod($root . '/bin/' . $tool, 0700);
    }
    touch($root . '/dev/sda');

    $collect = static function (string $mode, string $type = '') use ($root, $collector, $run, $parse, $assert): array {
        $script = $collector->invoke(null);
        // The production command uses block devices. Redirect only its discovery
        // paths to temporary regular files; keep filtering, probes and parsing intact.
        $script = strtr($script, [
            '/sys/class/hwmon' => $root . '/hwmon',
            '/dev/nvme' => $root . '/dev/nvme',
            '/dev/sd' => $root . '/dev/sd',
            '[ -b "$dev" ]' => '[ -f "$dev" ]',
        ]);
        $assert(!str_contains($script, '/sys/class/hwmon') && !str_contains($script, 'in /dev/'), 'Fixture collection must not enumerate real hardware');
        file_put_contents($root . '/calls.log', '');
        $result = $run(['/bin/bash', '-s'], $script, [
            'PATH' => $root . '/bin:/usr/bin:/bin',
            'SSD_TEST_LOG' => $root . '/calls.log',
            'SSD_TEST_MODE' => $mode,
            'SSD_TEST_TYPE' => $type,
        ]);
        $assert($result['exit'] === 0 && $result['stderr'] === '', 'Collector must run without shell errors: ' . $result['stderr']);
        // Normalize fixture paths back to production device names for assertions.
        return $parse(explode("\n", str_replace($root . '/dev/', '/dev/', $result['stdout'])));
    };

    foreach (['scsi' => 37, 'past' => 35, 'failing' => 75, 'invalid-first' => 36, 'sat' => 36] as $mode => $expected) {
        $assert($collect($mode) === ["/dev/sda: {$expected}°C (smartctl)"], "Collection regression: $mode");
    }
    $assert($collect('none') === $unavailable, 'Unsupported hardware must be unavailable');
    $assert(!str_contains((string)file_get_contents($root . '/calls.log'), 'snt'), 'Unknown bridge protocols must not be guessed');

    foreach (['sat', 'scsi', 'sntjmicron', 'sntrealtek', 'sntasmedia'] as $type) {
        putenv('SSD_SMARTCTL_DEVICE_TYPES=' . json_encode(['/dev/sda' => $type]));
        $assert($collect('bridge', $type) === ['/dev/sda: 39°C (smartctl)'], "Explicit type $type must reach smartctl");
        $calls = trim((string)file_get_contents($root . '/calls.log'));
        $assert($calls === "-d $type -A $root/dev/sda", 'An explicit type must bypass automatic and SAT probes');
    }

    putenv('SSD_SMARTCTL_DEVICE_TYPES={}');
    touch($root . '/dev/sdaa');
    touch($root . '/dev/sda1');
    touch($root . '/dev/nvme10n12');
    touch($root . '/dev/nvme10n12p1');
    $readings = $collect('normal');
    $assert(count($readings) === 4, 'Whole-disk discovery must support multi-digit NVMe and multi-letter sd names, excluding partitions');
    $assert(in_array('/dev/nvme10n12: 43°C (nvme smart-log)', $readings, true), 'NVMe tool output must reach parser');
    $assert(in_array('/dev/sdaa: 36°C (smartctl)', $readings, true), 'Multi-letter sd name must be collected');

    // Exercise the real sysfs collection loop, including a sensor with no device link.
    foreach (new FilesystemIterator($root . '/dev', FilesystemIterator::SKIP_DOTS) as $device) {
        unlink($device->getPathname());
    }
    foreach ([0, 1, 2] as $index) {
        $hwmon = $root . '/hwmon/hwmon' . $index;
        mkdir($hwmon, 0700);
        file_put_contents($hwmon . '/name', $index === 2 ? 'drivetemp' : 'nvme');
        file_put_contents($hwmon . '/temp1_input', '42000');
        file_put_contents($hwmon . '/temp1_label', 'Composite');
        if ($index < 2) {
            $controller = $root . '/controllers/nvme' . $index;
            mkdir($controller, 0700, true);
            symlink($controller, $hwmon . '/device');
        }
    }
    file_put_contents($root . '/hwmon/hwmon0/temp2_input', '42500');
    file_put_contents($root . '/hwmon/hwmon0/temp2_label', 'Sensor 1');
    $readings = $collect('none');
    $assert(count($readings) === 4, 'Collection must preserve equal readings from separate devices and multiple sensors');
    $assert(in_array('nvme [nvme0] (Composite): 42.0°C', $readings, true), 'Collector must resolve a real hwmon device link');
    $assert(in_array('nvme [nvme1] (Composite): 42.0°C', $readings, true), 'Collector must distinguish the second device');
    $assert(in_array('drivetemp [hwmon2] (Composite): 42.0°C', $readings, true), 'Absent device link must use hwmon identity');

    foreach (['', '{}', '{"/dev/sda":"sntjmicron"}'] as $config) {
        putenv('SSD_SMARTCTL_DEVICE_TYPES=' . $config);
        foreach (['/bin/sh', '/bin/bash'] as $shell) {
            $result = $run([$shell, '-n'], $collector->invoke(null));
            $assert($result['exit'] === 0, "$shell must accept generated script: " . $result['stderr']);
        }
    }
    foreach (['not json', '[]', 'null', '"sat"', '{"/dev/sda":"auto"}', '{"/dev/sda":4}', '{"/dev/sda1":"sat"}', '{"/dev/sda;touch /tmp/injected":"sat"}', '{"/dev/sda":"sat;touch /tmp/injected"}'] as $config) {
        putenv('SSD_SMARTCTL_DEVICE_TYPES=' . $config);
        $rejected = false;
        try {
            $collector->invoke(null);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected, 'Invalid override must be rejected before shell execution: ' . $config);
        $diagnostic = $reader->invoke(new SystemController());
        $assert(str_contains($diagnostic[0], 'configuration error: SSD_SMARTCTL_DEVICE_TYPES'), 'Invalid override must produce a visible diagnostic');
    }

    echo "SSD temperature regression tests: $checks checks passed\n";
} finally {
    $originalTypes === false ? putenv('SSD_SMARTCTL_DEVICE_TYPES') : putenv('SSD_SMARTCTL_DEVICE_TYPES=' . $originalTypes);
    $removeTree($root);
}

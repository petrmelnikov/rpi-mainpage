<?php

namespace App;

class ShellCommandExecutor
{
    public static function execute(string $command, bool $forceSsh = false): string
    {
        $effectiveCommand = self::buildEffectiveCommand($command, $forceSsh);
        $output = [];
        $exitCode = 0;
        exec($effectiveCommand, $output, $exitCode);

        $result = implode("\n", $output);

        if ($result !== '') {
            $result .= "\n";
        }

        if ($exitCode !== 0 && $result === '') {
            throw new \RuntimeException('Command execution failed');
        }

        return $result;
    }

    public static function executeWithSplitByLines(string $command, bool $forceSsh = false): array
    {
        try {
            $result = self::execute($command, $forceSsh);
        } catch (\RuntimeException $e) {
            return [
                $e->getMessage(),
            ];
        }
        return explode("\n", $result);
    }

    private static function buildEffectiveCommand(string $command, bool $forceSsh = false): string
    {
        $shouldUseSsh = $forceSsh || getenv('SHELL_OVER_SSH') === '1';

        if (!$shouldUseSsh) {
            return $command;
        }

        $wrapper = '/usr/local/bin/run-over-ssh.sh';
        if (!is_executable($wrapper)) {
            return $command;
        }

        return escapeshellcmd($wrapper) . ' ' . escapeshellarg($command) . ' 2>&1';
    }
}

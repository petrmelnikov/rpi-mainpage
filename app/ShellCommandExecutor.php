<?php

namespace App;

class ShellCommandExecutor
{
    public static function execute(string $command): string
    {
        $effectiveCommand = self::buildEffectiveCommand($command);
        $result = shell_exec($effectiveCommand);

        if ($result === null) {
            throw new \RuntimeException('Command execution failed');
        }

        return $result;
    }

    public static function executeWithSplitByLines(string $command): array
    {
        try {
            $result = self::execute($command);
        } catch (\RuntimeException $e) {
            return [
                $e->getMessage(),
            ];
        }
        return explode("\n", $result);
    }

    private static function buildEffectiveCommand(string $command): string
    {
        if (getenv('SHELL_OVER_SSH') !== '1') {
            return $command;
        }

        $wrapper = '/usr/local/bin/run-over-ssh.sh';
        if (!is_executable($wrapper)) {
            return $command;
        }

        return escapeshellcmd($wrapper) . ' ' . escapeshellarg($command) . ' 2>&1';
    }
}

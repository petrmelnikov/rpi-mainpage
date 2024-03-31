<?php

namespace App;

class ShellCommandExecutor
{
    public static function execute(string $command): string
    {
        $result = shell_exec($command);

        if ($result === null) {
            throw new \RuntimeException('Command execution failed');
        }

        return $result;
    }

    public static function executeWithSplitByLines(string $command): array
    {
        $result = self::execute($command);
        return explode("\n", $result);
    }
}
<?php

namespace App;

class ShellCommandExecutor
{
    public static function execute(string $command): string
    {
        $result = shell_exec($command);

        if (!is_string($result)) {
            $result = '';
        }

        return $result;
    }

    public static function executeWithSplitByLines(string $command): array
    {
        $result = self::execute($command);
        return explode("\n", $result);
    }

    public static function multipleExecute(string ...$commands): array
    {
        $result = [];
        foreach ($commands as $command) {
            $result[] = self::execute($command);
        }
        return $result;
    }
}
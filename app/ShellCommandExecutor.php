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
        try {
            $result = self::execute($command);
        } catch (\RuntimeException $e) {
            return [
                $e->getMessage(),
            ];
        }
        return explode("\n", $result);
    }
}
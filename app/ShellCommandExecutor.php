<?php

namespace App;

class ShellCommandExecutor
{
    private const LEGACY_LOCAL_RUN_USER = 'ubuntu';
    private const DEFAULT_REMOTE_APP_ROOT = '/apps/rpi-mainpage';

    public static function isSshEnabled(bool $forceSsh = false): bool
    {
        return $forceSsh || getenv('SHELL_OVER_SSH') === '1';
    }

    public static function resolveTargetAppRoot(string $localAppRoot, bool $forceSsh = false): string
    {
        if (!self::isSshEnabled($forceSsh)) {
            return $localAppRoot;
        }

        return getenv('SSH_REMOTE_APP_DIR') ?: self::DEFAULT_REMOTE_APP_ROOT;
    }

    public static function wrapForAppShell(string $command, bool $forceSsh = false): string
    {
        if (self::isSshEnabled($forceSsh)) {
            return $command;
        }

        return 'sudo -n -u ' . self::LEGACY_LOCAL_RUN_USER . ' -H bash -lc ' . escapeshellarg($command);
    }

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
        $shouldUseSsh = self::isSshEnabled($forceSsh);

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

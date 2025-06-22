<?php

namespace App;

class FileIndexManager
{
    private string $configPath;

    public function __construct()
    {
        $appRoot = App::getInstance()->appRoot;
        $this->configPath = rtrim($appRoot, '/') . '/config/file_index.json';
    }

    public function getCatalogPath(): string
    {
        $config = $this->getConfig();
        return $config['catalogPath'] ?? '/home/pi/Documents';
    }

    public function setCatalogPath(string $path): bool
    {
        $config = $this->getConfig();
        $config['catalogPath'] = $path;
        
        return $this->saveConfig($config);
    }

    public function validatePath(string $path): array
    {
        $errors = [];
        
        if (empty($path)) {
            $errors[] = 'Path cannot be empty';
            return $errors;
        }
        
        if (!file_exists($path)) {
            $errors[] = 'Path does not exist';
        } elseif (!is_dir($path)) {
            $errors[] = 'Path is not a directory';
        } elseif (!is_readable($path)) {
            $errors[] = 'Path is not readable';
        }
        
        return $errors;
    }

    private function getConfig(): array
    {
        if (!file_exists($this->configPath)) {
            return ['catalogPath' => '/home/pi/Documents'];
        }

        $json = file_get_contents($this->configPath);
        $config = json_decode($json, true);
        
        return $config ?: ['catalogPath' => '/home/pi/Documents'];
    }

    private function saveConfig(array $config): bool
    {
        $json = json_encode($config, JSON_PRETTY_PRINT);
        return file_put_contents($this->configPath, $json) !== false;
    }
}

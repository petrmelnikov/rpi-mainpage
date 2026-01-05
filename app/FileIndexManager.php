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

    public function getPinnedDirectories(): array
    {
        $config = $this->getConfig();
        return $config['pinnedDirectories'] ?? [];
    }

    public function addPinnedDirectory(string $path, string $name): bool
    {
        $config = $this->getConfig();
        $pinnedDirs = $config['pinnedDirectories'] ?? [];
        
        // Check if already pinned
        foreach ($pinnedDirs as $dir) {
            if ($dir['path'] === $path) {
                return false; // Already pinned
            }
        }
        
        $pinnedDirs[] = [
            'path' => $path,
            'name' => $name
        ];
        
        $config['pinnedDirectories'] = $pinnedDirs;
        return $this->saveConfig($config);
    }

    public function removePinnedDirectory(string $path): bool
    {
        $config = $this->getConfig();
        $pinnedDirs = $config['pinnedDirectories'] ?? [];
        
        $config['pinnedDirectories'] = array_values(array_filter($pinnedDirs, function($dir) use ($path) {
            return $dir['path'] !== $path;
        }));
        
        return $this->saveConfig($config);
    }

    public function updatePinnedDirectory(string $oldPath, string $newPath, string $newName): bool
    {
        $oldPath = trim($oldPath);
        $newPath = trim($newPath);
        $newName = trim($newName);

        if ($oldPath === '' || $newPath === '' || $newName === '') {
            return false;
        }

        $config = $this->getConfig();
        $pinnedDirs = $config['pinnedDirectories'] ?? [];

        $changed = false;
        $newPathAlreadyPinnedIndex = null;
        foreach ($pinnedDirs as $idx => $dir) {
            if (($dir['path'] ?? '') === $newPath) {
                $newPathAlreadyPinnedIndex = $idx;
                break;
            }
        }

        foreach ($pinnedDirs as $idx => $dir) {
            if (($dir['path'] ?? '') !== $oldPath) {
                continue;
            }

            // If target already pinned, just drop old pin to avoid duplicates.
            if ($newPathAlreadyPinnedIndex !== null && $newPathAlreadyPinnedIndex !== $idx) {
                unset($pinnedDirs[$idx]);
                $changed = true;
                continue;
            }

            $pinnedDirs[$idx]['path'] = $newPath;
            $pinnedDirs[$idx]['name'] = $newName;
            $changed = true;
        }

        if (!$changed) {
            return false;
        }

        $config['pinnedDirectories'] = array_values($pinnedDirs);
        return $this->saveConfig($config);
    }

    public function isDirectoryPinned(string $path): bool
    {
        $pinnedDirs = $this->getPinnedDirectories();
        foreach ($pinnedDirs as $dir) {
            if ($dir['path'] === $path) {
                return true;
            }
        }
        return false;
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
        return file_put_contents($this->configPath, $json, LOCK_EX) !== false;
    }
}

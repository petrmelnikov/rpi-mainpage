<?php

namespace App;

class MenuManager
{
    private string $configPath;

    public function __construct()
    {
        $appRoot = App::getInstance()->appRoot;
        $this->configPath = $appRoot . '/config/top_menu.json';
    }

    public function getMenuItems(): array
    {
        if (!file_exists($this->configPath)) {
            return [];
        }

        $content = file_get_contents($this->configPath);
        $items = json_decode($content, true);
        
        return $items ?: [];
    }

    public function saveMenuItems(array $items): bool
    {
        $json = json_encode($items, JSON_PRETTY_PRINT);
        
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->configPath, $json) !== false;
    }

    public function addMenuItem(string $name, string $url): bool
    {
        $items = $this->getMenuItems();
        
        // Check if name already exists
        foreach ($items as $item) {
            if ($item['name'] === $name) {
                return false; // Name already exists
            }
        }

        $items[] = [
            'name' => $name,
            'url' => $url
        ];

        return $this->saveMenuItems($items);
    }

    public function editMenuItem(int $index, string $name, string $url): bool
    {
        $items = $this->getMenuItems();
        
        if (!isset($items[$index])) {
            return false; // Index doesn't exist
        }

        // Check if name already exists (excluding current item)
        foreach ($items as $i => $item) {
            if ($i !== $index && $item['name'] === $name) {
                return false; // Name already exists
            }
        }

        $items[$index] = [
            'name' => $name,
            'url' => $url
        ];

        return $this->saveMenuItems($items);
    }

    public function deleteMenuItem(int $index): bool
    {
        $items = $this->getMenuItems();
        
        if (!isset($items[$index])) {
            return false; // Index doesn't exist
        }

        array_splice($items, $index, 1);

        return $this->saveMenuItems($items);
    }

    public function validateMenuItem(string $name, string $url): array
    {
        $errors = [];

        if (empty(trim($name))) {
            $errors[] = 'Name is required';
        }

        if (empty(trim($url))) {
            $errors[] = 'URL is required';
        } else {
            // More flexible URL validation for local development and internal networks
            $isValidUrl = filter_var($url, FILTER_VALIDATE_URL) || 
                         preg_match('/^https?:\/\/(localhost|127\.0\.0\.1|\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})(:\d+)?/', $url);
            
            if (!$isValidUrl) {
                $errors[] = 'Invalid URL format';
            }
        }

        return $errors;
    }
}

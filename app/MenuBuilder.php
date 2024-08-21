<?php

namespace App;

use App\Dto\MenuItemDto;

class MenuBuilder
{
    private string $configPath = __DIR__ . '/config/top_menu.json';
    private ?array $config = null;

    public function __construct(string $configPath = null)
    {
        if ($configPath) {
            $this->configPath = $configPath;
        }

        if (file_exists($this->configPath)) {
            $this->config = json_decode(file_get_contents($this->configPath), true);
        }
    }

    public function buildMenuArray(): array
    {
        if ($this->config === null) {
            return [];
        }

        $menuItems = [];
        foreach ($this->config as $item) {
            $menuItem = new MenuItemDto();
            $menuItem->url = $item['url'];
            $menuItem->name = $item['name'];
            $menuItems[] = $menuItem;
        }

        return $menuItems;
    }

}
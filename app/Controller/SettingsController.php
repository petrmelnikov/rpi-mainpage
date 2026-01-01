<?php

namespace App\Controller;

use App\FileIndexManager;
use App\MenuManager;
use App\Router;

class SettingsController
{
    public function registerRoutes(Router $router, string $appRoot): void
    {
        $router->addRoute('GET', '/settings', [$this, 'settings'], $appRoot . '/templates/settings.html.php');
        $router->addRoute('POST', '/settings/top-menu/create', [$this, 'createTopMenuItem']);
        $router->addRoute('POST', '/settings/top-menu/edit', [$this, 'editTopMenuItem']);
        $router->addRoute('POST', '/settings/top-menu/delete', [$this, 'deleteTopMenuItem']);
        $router->addRoute('POST', '/settings/file-index/update', [$this, 'updateFileIndexPath']);
    }

    public function settings(): array
    {
        $menuManager = new MenuManager();
        $fileIndexManager = new FileIndexManager();
        $menuItems = $menuManager->getMenuItems();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $result = [
            'menuItems' => $menuItems,
            'catalogPath' => $catalogPath
        ];

        if (isset($_GET['message'])) {
            $result['message'] = $_GET['message'];
            $result['messageType'] = $_GET['type'] ?? 'info';
        }

        return $result;
    }

    public function createTopMenuItem(): string
    {
        $menuManager = new MenuManager();
        $name = $_POST['name'] ?? '';
        $url = $_POST['url'] ?? '';

        $errors = $menuManager->validateMenuItem($name, $url);

        if (empty($errors)) {
            $success = $menuManager->addMenuItem($name, $url);
            if ($success) {
                header('Location: /settings?message=' . urlencode('Menu item added successfully') . '&type=success');
            } else {
                header('Location: /settings?message=' . urlencode('Failed to add menu item. Name might already exist.') . '&type=danger');
            }
        } else {
            header('Location: /settings?message=' . urlencode(implode(', ', $errors)) . '&type=danger');
        }
        exit;
    }

    public function editTopMenuItem(): string
    {
        $menuManager = new MenuManager();
        $index = intval($_POST['index'] ?? -1);
        $name = $_POST['name'] ?? '';
        $url = $_POST['url'] ?? '';

        $errors = $menuManager->validateMenuItem($name, $url);

        if (empty($errors)) {
            $success = $menuManager->editMenuItem($index, $name, $url);
            if ($success) {
                header('Location: /settings?message=' . urlencode('Menu item updated successfully') . '&type=success');
            } else {
                header('Location: /settings?message=' . urlencode('Failed to update menu item. Name might already exist or index invalid.') . '&type=danger');
            }
        } else {
            header('Location: /settings?message=' . urlencode(implode(', ', $errors)) . '&type=danger');
        }
        exit;
    }

    public function deleteTopMenuItem(): string
    {
        $menuManager = new MenuManager();
        $index = intval($_POST['index'] ?? -1);

        $success = $menuManager->deleteMenuItem($index);
        if ($success) {
            header('Location: /settings?message=' . urlencode('Menu item deleted successfully') . '&type=success');
        } else {
            header('Location: /settings?message=' . urlencode('Failed to delete menu item. Invalid index.') . '&type=danger');
        }
        exit;
    }

    public function updateFileIndexPath(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $_POST['catalogPath'] ?? '';

        $errors = $fileIndexManager->validatePath($catalogPath);

        if (empty($errors)) {
            $success = $fileIndexManager->setCatalogPath($catalogPath);
            if ($success) {
                header('Location: /settings?message=' . urlencode('File index path updated successfully') . '&type=success');
            } else {
                header('Location: /settings?message=' . urlencode('Failed to update file index path.') . '&type=danger');
            }
        } else {
            header('Location: /settings?message=' . urlencode(implode(', ', $errors)) . '&type=danger');
        }
        exit;
    }
}

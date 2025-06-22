<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Router;
use App\ShellCommandExecutor;
use App\TemplateRenderer;
use App\MenuBuilder;
use App\MenuManager;
use App\App;

$app = App::getInstance();
$app->appRoot = __DIR__;

// Configuration - File Index Catalog Path
// Change this constant to point to the directory you want to index
define('FILE_INDEX_CATALOG_PATH', '/Users/user/Documents');

$topMainMenu = (new MenuBuilder())->buildMenuArray();

$router = new Router();

$router->addRoute('GET', '', function () {
    return ['shellCommandRawContent' => array_merge(
        ShellCommandExecutor::executeWithSplitByLines('landscape-sysinfo'),
        ShellCommandExecutor::executeWithSplitByLines("df -h | grep 'usb' 2>&1")
    )];
}, $app->appRoot . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/top', function () {
    $command = 'top -b -n 1 2>&1 | head -20 2>&1';
    return ['shellCommandRawContent' => ShellCommandExecutor::executeWithSplitByLines($command)];
}, $app->appRoot . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/update-code', function () {
    return ['shellCommandRawContent' => array_merge(
        ShellCommandExecutor::executeWithSplitByLines('ssh -T git@github.com 2>&1'),
        ShellCommandExecutor::executeWithSplitByLines('git pull 2>&1'),
        ShellCommandExecutor::executeWithSplitByLines('composer install 2>&1')
    )];
}, $app->appRoot . '/templates/shell_command_raw_content.html.php');

$router->addRoute('GET', '/file-index', function () {
    // Use the hardcoded catalog path constant
    $catalogPath = FILE_INDEX_CATALOG_PATH;
    
    $files = [];
    $errors = [];
    
    if (is_dir($catalogPath)) {
        try {
            // Check if directory is readable
            if (!is_readable($catalogPath)) {
                $errors[] = "Directory is not readable: " . $catalogPath;
            } else {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($catalogPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                
                $fileCount = 0;
                $maxFiles = 1000; // Limit to prevent overwhelming the page
                
                foreach ($iterator as $file) {
                    try {
                        if ($fileCount >= $maxFiles) {
                            $errors[] = "Display limited to first {$maxFiles} items for performance";
                            break;
                        }
                        
                        $relativePath = str_replace($catalogPath . '/', '', $file->getPathname());
                        $files[] = [
                            'name' => $file->getFilename(),
                            'path' => $relativePath,
                            'fullPath' => $file->getPathname(),
                            'isDir' => $file->isDir(),
                            'size' => $file->isFile() ? $file->getSize() : 0,
                            'modified' => $file->getMTime(),
                            'depth' => $iterator->getDepth()
                        ];
                        $fileCount++;
                    } catch (Exception $e) {
                        // Skip files that can't be read (permission issues, etc.)
                        continue;
                    }
                }
                
                // Sort files: directories first, then by name
                usort($files, function($a, $b) {
                    if ($a['isDir'] && !$b['isDir']) return -1;
                    if (!$a['isDir'] && $b['isDir']) return 1;
                    return strcasecmp($a['name'], $b['name']);
                });
            }
            
        } catch (Exception $e) {
            $errors[] = "Error reading directory: " . $e->getMessage();
        }
    } else {
        $errors[] = "Catalog path does not exist or is not accessible: " . $catalogPath;
    }
    
    return [
        'catalogPath' => $catalogPath,
        'files' => $files,
        'errors' => $errors,
        'totalFiles' => count(array_filter($files, fn($f) => !$f['isDir'])),
        'totalDirs' => count(array_filter($files, fn($f) => $f['isDir']))
    ];
}, $app->appRoot . '/templates/file_index.html.php');

// Settings routes
$router->addRoute('GET', '/settings', function () {
    $menuManager = new MenuManager();
    $menuItems = $menuManager->getMenuItems();
    
    $result = [
        'menuItems' => $menuItems
    ];
    
    // Handle messages from redirects
    if (isset($_GET['message'])) {
        $result['message'] = $_GET['message'];
        $result['messageType'] = $_GET['type'] ?? 'info';
    }
    
    return $result;
}, $app->appRoot . '/templates/settings.html.php');

$router->addRoute('POST', '/settings/top-menu/create', function () {
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
});

$router->addRoute('POST', '/settings/top-menu/edit', function () {
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
});

$router->addRoute('POST', '/settings/top-menu/delete', function () {
    $menuManager = new MenuManager();
    $index = intval($_POST['index'] ?? -1);
    
    $success = $menuManager->deleteMenuItem($index);
    if ($success) {
        header('Location: /settings?message=' . urlencode('Menu item deleted successfully') . '&type=success');
    } else {
        header('Location: /settings?message=' . urlencode('Failed to delete menu item. Invalid index.') . '&type=danger');
    }
    exit;
});

$routeDataDto = $router->parse($_SERVER);
$handler = $routeDataDto->handler;

//$content = file_get_contents(__DIR__ . '/templates/top_main_menu.html.php');
$content = TemplateRenderer::render($app->appRoot . '/templates/top_main_menu.html.php', ['topMainMenu' => $topMainMenu]);
if (!empty($routeDataDto->templatePath)){
    $content .= TemplateRenderer::render($routeDataDto->templatePath, $handler());
} else {
    $content .= $handler();
}
$content .= file_get_contents($app->appRoot . '/templates/bottom_main_menu.html.php');

echo TemplateRenderer::render($app->appRoot . '/web/index.html.php', ['body' => $content]);
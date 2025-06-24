<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Router;
use App\ShellCommandExecutor;
use App\TemplateRenderer;
use App\MenuBuilder;
use App\MenuManager;
use App\FileIndexManager;
use App\App;

$app = App::getInstance();
$app->appRoot = __DIR__;

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

$router->addRoute('GET', '/tools', function () {
    return [];
}, $app->appRoot . '/templates/tools.html.php');

$router->addRoute('GET', '/tools/example1', function () {
    return [];
}, $app->appRoot . '/templates/example1.html.php');

$router->addRoute('GET', '/tools/example2', function () {
    return [];
}, $app->appRoot . '/templates/example2.html.php');

$router->addRoute('GET', '/file-index', function () {
    // Use the configurable catalog path from settings
    $fileIndexManager = new FileIndexManager();
    $catalogPath = $fileIndexManager->getCatalogPath();
    
    // Get the current directory path from query parameter
    $currentPath = $_GET['path'] ?? '';
    $currentPath = trim($currentPath, '/');
    
    // Build the full current directory path
    $currentFullPath = $catalogPath;
    if (!empty($currentPath)) {
        // Prevent directory traversal attacks
        $currentPath = str_replace(['../', '.\\', '..\\'], '', $currentPath);
        $currentFullPath = rtrim($catalogPath, '/') . '/' . $currentPath;
    }
    
    $files = [];
    $errors = [];
    $breadcrumbs = [];
    
    // Build breadcrumbs
    $breadcrumbs[] = ['name' => 'Root', 'path' => ''];
    if (!empty($currentPath)) {
        $pathParts = explode('/', $currentPath);
        $buildPath = '';
        foreach ($pathParts as $part) {
            $buildPath = $buildPath ? $buildPath . '/' . $part : $part;
            $breadcrumbs[] = ['name' => $part, 'path' => $buildPath];
        }
    }
    
    if (is_dir($currentFullPath)) {
        try {
            // Check if directory is readable
            if (!is_readable($currentFullPath)) {
                $errors[] = "Directory is not readable: " . $currentFullPath;
            } else {
                // Read only the current directory (non-recursive)
                $iterator = new DirectoryIterator($currentFullPath);
                
                foreach ($iterator as $file) {
                    if ($file->isDot()) continue;
                    
                    try {
                        $relativePath = $currentPath ? $currentPath . '/' . $file->getFilename() : $file->getFilename();
                        $files[] = [
                            'name' => $file->getFilename(),
                            'path' => $relativePath,
                            'fullPath' => $file->getPathname(),
                            'isDir' => $file->isDir(),
                            'size' => $file->isFile() ? $file->getSize() : 0,
                            'modified' => $file->getMTime(),
                            'isNavigable' => $file->isDir() && $file->isReadable()
                        ];
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
        $errors[] = "Directory does not exist or is not accessible: " . $currentFullPath;
    }
    
    return [
        'catalogPath' => $catalogPath,
        'currentPath' => $currentPath,
        'currentFullPath' => $currentFullPath,
        'breadcrumbs' => $breadcrumbs,
        'files' => $files,
        'errors' => $errors,
        'totalFiles' => count(array_filter($files, fn($f) => !$f['isDir'])),
        'totalDirs' => count(array_filter($files, fn($f) => $f['isDir']))
    ];
}, $app->appRoot . '/templates/file_index.html.php');

$router->addRoute('GET', '/file-index/download', function () {
    $fileIndexManager = new FileIndexManager();
    $catalogPath = $fileIndexManager->getCatalogPath();
    $relativePath = $_GET['path'] ?? '';
    
    // Sanitize and validate the requested path
    $relativePath = trim($relativePath, '/');
    $fullPath = $catalogPath;
    
    if (!empty($relativePath)) {
        // Prevent directory traversal attacks
        $relativePath = str_replace(['../', '.\\', '..\\'], '', $relativePath);
        $fullPath = rtrim($catalogPath, '/') . '/' . $relativePath;
    }
    
    // Validate the path
    if (!file_exists($fullPath)) {
        http_response_code(404);
        echo "Directory not found";
        exit;
    }
    
    if (!is_dir($fullPath)) {
        http_response_code(400);
        echo "Path is not a directory";
        exit;
    }
    
    if (!is_readable($fullPath)) {
        http_response_code(403);
        echo "Directory not readable";
        exit;
    }
    
    // Generate archive name
    $dirName = basename($fullPath);
    if (empty($dirName)) {
        $dirName = 'catalog';
    }
    $archiveName = $dirName . '.tar.gz';
    
    // Set headers for download
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $archiveName . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('X-Archive-Name: ' . $archiveName);
    header('X-Source-Path: ' . basename($fullPath));
    
    // Stream the tar.gz archive directly to output
    $descriptorspec = [
        0 => ["pipe", "r"],  // stdin
        1 => ["pipe", "w"],  // stdout
        2 => ["pipe", "w"]   // stderr
    ];
    
    // Use tar with gzip compression, streaming to stdout
    $cmd = "cd " . escapeshellarg(dirname($fullPath)) . " && tar -czf - " . escapeshellarg(basename($fullPath)) . " 2>/dev/null";
    $process = proc_open($cmd, $descriptorspec, $pipes);
    
    if (is_resource($process)) {
        // Close stdin as we're not writing to it
        fclose($pipes[0]);
        
        // Stream the output directly to the client
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                echo $chunk;
                ob_flush();
                flush();
            }
        }
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    } else {
        http_response_code(500);
        echo "Failed to create archive";
    }
    
    exit;
});

// File download route (individual files, not compressed)
$router->addRoute('GET', '/file-index/download/file', function () {
    $fileIndexManager = new FileIndexManager();
    $catalogPath = $fileIndexManager->getCatalogPath();
    $relativePath = $_GET['path'] ?? '';
    
    // Sanitize and validate the requested path
    $relativePath = trim($relativePath, '/');
    $fullPath = $catalogPath;
    
    if (!empty($relativePath)) {
        // Prevent directory traversal attacks
        $relativePath = str_replace(['../', '.\\', '..\\'], '', $relativePath);
        $fullPath = rtrim($catalogPath, '/') . '/' . $relativePath;
    }
    
    // Validate the path
    if (!file_exists($fullPath)) {
        http_response_code(404);
        echo "File not found";
        exit;
    }
    
    if (!is_file($fullPath)) {
        http_response_code(400);
        echo "Path is not a file";
        exit;
    }
    
    if (!is_readable($fullPath)) {
        http_response_code(403);
        echo "File not readable";
        exit;
    }
    
    // Get file information
    $fileName = basename($fullPath);
    $fileSize = filesize($fullPath);
    $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
    
    // Set headers for direct file download
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('X-File-Name: ' . $fileName);
    header('X-File-Size: ' . $fileSize);
    
    // Stream the file directly to output
    $handle = fopen($fullPath, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk !== false) {
                echo $chunk;
                ob_flush();
                flush();
            }
        }
        fclose($handle);
    } else {
        http_response_code(500);
        echo "Failed to read file";
    }
    
    exit;
});

// Settings routes
$router->addRoute('GET', '/settings', function () {
    $menuManager = new MenuManager();
    $fileIndexManager = new FileIndexManager();
    $menuItems = $menuManager->getMenuItems();
    $catalogPath = $fileIndexManager->getCatalogPath();
    
    $result = [
        'menuItems' => $menuItems,
        'catalogPath' => $catalogPath
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

$router->addRoute('POST', '/settings/file-index/update', function () {
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
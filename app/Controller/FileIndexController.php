<?php

namespace App\Controller;

use App\FileIndexManager;
use App\Router;
use App\Support\PathGuard;

class FileIndexController
{
    private static function buildRedirectUrl(string $returnPath): string
    {
        $redirectUrl = '/file-index';
        $returnPath = trim((string)$returnPath);
        if ($returnPath !== '') {
            $redirectUrl .= '?path=' . urlencode(trim($returnPath, '/'));
        }
        return $redirectUrl;
    }

    private static function redirectWithError(string $redirectUrl, string $error): void
    {
        $glue = (str_contains($redirectUrl, '?')) ? '&' : '?';
        header('Location: ' . $redirectUrl . $glue . 'error=' . urlencode($error));
        exit;
    }

    private static function validateSinglePathSegment(string $name, string $label = 'Name'): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException($label . ' is required');
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \InvalidArgumentException($label . ' must not contain slashes');
        }
        if (str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Invalid characters in ' . strtolower($label));
        }
        if ($name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid ' . strtolower($label));
        }
        return $name;
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function parseNfoField(string $content, string $fieldName): string
    {
        $pattern = '/<' . preg_quote($fieldName, '/') . '>\s*(.*?)\s*<\/' . preg_quote($fieldName, '/') . '>/si';
        if (!preg_match($pattern, $content, $m)) {
            return '';
        }
        $raw = (string)$m[1];
        return html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
    }

    public function registerRoutes(Router $router, string $appRoot): void
    {
        $router->addRoute('GET', '/file-index', [$this, 'index'], $appRoot . '/templates/file_index.html.php');
        $router->addRoute('POST', '/file-index/delete', [$this, 'delete']);
        $router->addRoute('POST', '/file-index/dir/create', [$this, 'createDirectory']);
        $router->addRoute('POST', '/file-index/dir/delete', [$this, 'deleteDirectory']);
        $router->addRoute('POST', '/file-index/dir/rename', [$this, 'renameDirectory']);
        $router->addRoute('POST', '/file-index/upload', [$this, 'uploadFile']);
        $router->addRoute('POST', '/file-index/pin', [$this, 'pin']);
        $router->addRoute('POST', '/file-index/unpin', [$this, 'unpin']);
        $router->addRoute('GET', '/file-index/download', [$this, 'downloadDirectory']);
        $router->addRoute('GET', '/file-index/stream', [$this, 'streamVideo']);
        $router->addRoute('GET', '/file-index/download/file', [$this, 'downloadFile']);
        $router->addRoute('GET', '/file-index/nfo', [$this, 'getNfo']);
        $router->addRoute('POST', '/file-index/nfo', [$this, 'saveNfo']);
    }

    public function index(): array
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $currentPath = (string)($_GET['path'] ?? '');
        $currentPath = trim($currentPath, '/');

        $currentFullPath = $catalogPath;
        if ($currentPath !== '') {
            $currentPath = str_replace(['../', '.\\', '..\\'], '', $currentPath);
            $currentFullPath = rtrim($catalogPath, '/') . '/' . $currentPath;
        }

        $files = [];
        $errors = [];
        if (!empty($_GET['error'])) {
            $errors[] = (string)$_GET['error'];
        }
        $breadcrumbs = [];

        $breadcrumbs[] = ['name' => 'Root', 'path' => ''];
        if ($currentPath !== '') {
            $pathParts = explode('/', $currentPath);
            $buildPath = '';
            foreach ($pathParts as $part) {
                $buildPath = $buildPath ? $buildPath . '/' . $part : $part;
                $breadcrumbs[] = ['name' => $part, 'path' => $buildPath];
            }
        }

        if (is_dir($currentFullPath)) {
            try {
                if (!is_readable($currentFullPath)) {
                    $errors[] = 'Directory is not readable: ' . $currentFullPath;
                } else {
                    $iterator = new \DirectoryIterator($currentFullPath);

                    foreach ($iterator as $file) {
                        if ($file->isDot()) continue;

                        try {
                            $relativePath = $currentPath ? $currentPath . '/' . $file->getFilename() : $file->getFilename();
                            $files[] = [
                                'name' => $file->getFilename(),
                                'path' => $relativePath,
                                'fullPath' => $file->getPathname(),
                                'isDir' => $file->isDir(),
                                'isWritable' => @is_writable($file->getPathname()),
                                'size' => $file->isFile() ? $file->getSize() : 0,
                                'modified' => $file->getMTime(),
                                'isNavigable' => $file->isDir() && $file->isReadable()
                            ];
                        } catch (\Exception $e) {
                            continue;
                        }
                    }

                    usort($files, function($a, $b) {
                        if ($a['isDir'] && !$b['isDir']) return -1;
                        if (!$a['isDir'] && $b['isDir']) return 1;
                        return strcasecmp($a['name'], $b['name']);
                    });
                }
            } catch (\Exception $e) {
                $errors[] = 'Error reading directory: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Directory does not exist or is not accessible: ' . $currentFullPath;
        }

        $pinnedDirectories = $fileIndexManager->getPinnedDirectories();

        return [
            'catalogPath' => $catalogPath,
            'currentPath' => $currentPath,
            'currentFullPath' => $currentFullPath,
            'breadcrumbs' => $breadcrumbs,
            'files' => $files,
            'errors' => $errors,
            'totalFiles' => count(array_filter($files, fn($f) => !$f['isDir'])),
            'totalDirs' => count(array_filter($files, fn($f) => $f['isDir'])),
            'pinnedDirectories' => $pinnedDirectories,
            'pinnedPaths' => array_column($pinnedDirectories, null, 'path')
        ];
    }

    public function delete(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $path = (string)($_POST['path'] ?? '');
        $returnPath = (string)($_POST['returnPath'] ?? '');

        $redirectUrl = self::buildRedirectUrl($returnPath);

        $path = trim($path);
        if ($path === '') {
            self::redirectWithError($redirectUrl, 'File path is required');
        }

        try {
            $segments = PathGuard::toSegments($path);
        } catch (\InvalidArgumentException $e) {
            self::redirectWithError($redirectUrl, $e->getMessage());
        }

        $fullPath = PathGuard::joinCatalog($catalogPath, $segments);

        if (!file_exists($fullPath) && !is_link($fullPath)) {
            self::redirectWithError($redirectUrl, 'File not found');
        }

        if (is_dir($fullPath)) {
            self::redirectWithError($redirectUrl, 'Cannot delete directories');
        }

        if (!@unlink($fullPath)) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unknown error';
            self::redirectWithError($redirectUrl, 'Failed to delete file: ' . $reason);
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    public function createDirectory(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $returnPath = (string)($_POST['returnPath'] ?? '');
        $redirectUrl = self::buildRedirectUrl($returnPath);

        $parentPath = (string)($_POST['parentPath'] ?? '');
        $dirName = (string)($_POST['dirName'] ?? '');

        try {
            $dirName = self::validateSinglePathSegment($dirName, 'Directory name');
            $parentSegments = PathGuard::toSegmentsAllowEmpty($parentPath);
        } catch (\InvalidArgumentException $e) {
            self::redirectWithError($redirectUrl, $e->getMessage());
        }

        $targetSegments = array_merge($parentSegments, [$dirName]);
        $targetFullPath = PathGuard::joinCatalog($catalogPath, $targetSegments);
        $parentFullPath = PathGuard::joinCatalog($catalogPath, $parentSegments);

        if (!is_dir($parentFullPath)) {
            self::redirectWithError($redirectUrl, 'Parent directory not found');
        }
        if (!is_writable($parentFullPath)) {
            self::redirectWithError($redirectUrl, 'Parent directory is not writable');
        }
        if (file_exists($targetFullPath)) {
            self::redirectWithError($redirectUrl, 'Directory already exists');
        }

        if (!@mkdir($targetFullPath, 0775, false)) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unknown error';
            self::redirectWithError($redirectUrl, 'Failed to create directory: ' . $reason);
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    public function deleteDirectory(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $path = (string)($_POST['path'] ?? '');
        $returnPath = (string)($_POST['returnPath'] ?? '');
        $redirectUrl = self::buildRedirectUrl($returnPath);

        $path = trim($path);
        if ($path === '') {
            self::redirectWithError($redirectUrl, 'Directory path is required');
        }

        try {
            $segments = PathGuard::toSegments($path);
        } catch (\InvalidArgumentException $e) {
            self::redirectWithError($redirectUrl, $e->getMessage());
        }

        if (count($segments) === 0) {
            self::redirectWithError($redirectUrl, 'Cannot delete catalog root');
        }

        $fullPath = PathGuard::joinCatalog($catalogPath, $segments);
        if (!file_exists($fullPath)) {
            self::redirectWithError($redirectUrl, 'Directory not found');
        }
        if (!is_dir($fullPath)) {
            self::redirectWithError($redirectUrl, 'Path is not a directory');
        }

        if (!@rmdir($fullPath)) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unknown error';
            self::redirectWithError($redirectUrl, 'Failed to delete directory (must be empty): ' . $reason);
        }

        $cleanPath = PathGuard::segmentsToRelativePath($segments);
        $fileIndexManager->removePinnedDirectory($cleanPath);

        header('Location: ' . $redirectUrl);
        exit;
    }

    public function renameDirectory(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $path = (string)($_POST['path'] ?? '');
        $newName = (string)($_POST['newName'] ?? '');
        $returnPath = (string)($_POST['returnPath'] ?? '');
        $redirectUrl = self::buildRedirectUrl($returnPath);

        $path = trim($path);
        if ($path === '') {
            self::redirectWithError($redirectUrl, 'Directory path is required');
        }

        try {
            $segments = PathGuard::toSegments($path);
            $newName = self::validateSinglePathSegment($newName, 'New directory name');
        } catch (\InvalidArgumentException $e) {
            self::redirectWithError($redirectUrl, $e->getMessage());
        }

        if (count($segments) === 0) {
            self::redirectWithError($redirectUrl, 'Cannot rename catalog root');
        }

        $oldRelativePath = PathGuard::segmentsToRelativePath($segments);

        $parentSegments = $segments;
        array_pop($parentSegments);
        $newSegments = array_merge($parentSegments, [$newName]);

        $oldFullPath = PathGuard::joinCatalog($catalogPath, $segments);
        $newFullPath = PathGuard::joinCatalog($catalogPath, $newSegments);

        if (!file_exists($oldFullPath)) {
            self::redirectWithError($redirectUrl, 'Directory not found');
        }
        if (!is_dir($oldFullPath)) {
            self::redirectWithError($redirectUrl, 'Path is not a directory');
        }
        if (file_exists($newFullPath)) {
            self::redirectWithError($redirectUrl, 'Target name already exists');
        }

        if (!@rename($oldFullPath, $newFullPath)) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unknown error';
            self::redirectWithError($redirectUrl, 'Failed to rename directory: ' . $reason);
        }

        $newRelativePath = PathGuard::segmentsToRelativePath($newSegments);
        $fileIndexManager->updatePinnedDirectory($oldRelativePath, $newRelativePath, $newName);

        header('Location: ' . $redirectUrl);
        exit;
    }

    public function uploadFile(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $targetPath = (string)($_POST['targetPath'] ?? '');
        $returnPath = (string)($_POST['returnPath'] ?? '');
        $redirectUrl = self::buildRedirectUrl($returnPath);

        try {
            $dirSegments = PathGuard::toSegmentsAllowEmpty($targetPath);
        } catch (\InvalidArgumentException $e) {
            self::redirectWithError($redirectUrl, $e->getMessage());
        }

        $targetDir = PathGuard::joinCatalog($catalogPath, $dirSegments);
        if (!is_dir($targetDir)) {
            self::redirectWithError($redirectUrl, 'Target directory not found');
        }
        if (!is_writable($targetDir)) {
            self::redirectWithError($redirectUrl, 'Target directory is not writable');
        }

        if (!isset($_FILES['file'])) {
            self::redirectWithError($redirectUrl, 'No file uploaded');
        }

        $upload = $_FILES['file'];
        $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file is too large',
                UPLOAD_ERR_PARTIAL => 'File upload was incomplete',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension',
                default => 'File upload failed',
            };
            self::redirectWithError($redirectUrl, $msg);
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        $origName = (string)($upload['name'] ?? '');
        $safeName = basename($origName);

        try {
            $safeName = self::validateSinglePathSegment($safeName, 'File name');
        } catch (\InvalidArgumentException $e) {
            self::redirectWithError($redirectUrl, $e->getMessage());
        }

        $destFullPath = PathGuard::joinCatalog($catalogPath, array_merge($dirSegments, [$safeName]));
        if (file_exists($destFullPath)) {
            self::redirectWithError($redirectUrl, 'File already exists');
        }

        if (!is_uploaded_file($tmpName)) {
            self::redirectWithError($redirectUrl, 'Invalid upload');
        }

        if (!@move_uploaded_file($tmpName, $destFullPath)) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unknown error';
            self::redirectWithError($redirectUrl, 'Failed to save uploaded file: ' . $reason);
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    public function pin(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $path = (string)($_POST['path'] ?? '');
        $name = (string)($_POST['name'] ?? '');
        $returnPath = (string)($_POST['returnPath'] ?? '');

        if ($path !== '' && $name !== '') {
            try {
                $segments = PathGuard::toSegments($path);
                $cleanPath = PathGuard::segmentsToRelativePath($segments);
                $fullPath = PathGuard::joinCatalog($catalogPath, $segments);

                if (is_dir($fullPath)) {
                    $fileIndexManager->addPinnedDirectory($cleanPath, $name);
                }
            } catch (\InvalidArgumentException $e) {
                // ignore invalid path
            }
        }

        $redirectUrl = '/file-index';
        if ($returnPath !== '') {
            $redirectUrl .= '?path=' . urlencode($returnPath);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    public function unpin(): string
    {
        $fileIndexManager = new FileIndexManager();
        $path = (string)($_POST['path'] ?? '');
        $returnPath = (string)($_POST['returnPath'] ?? '');

        if ($path !== '') {
            $fileIndexManager->removePinnedDirectory($path);
        }

        $redirectUrl = '/file-index';
        if ($returnPath !== '') {
            $redirectUrl .= '?path=' . urlencode($returnPath);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    public function downloadDirectory(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();
        $relativePath = (string)($_GET['path'] ?? '');

        try {
            $segments = PathGuard::toSegmentsAllowEmpty($relativePath);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo $e->getMessage();
            exit;
        }

        $fullPath = PathGuard::joinCatalog($catalogPath, $segments);

        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo 'Directory not found';
            exit;
        }

        if (!is_dir($fullPath)) {
            http_response_code(400);
            echo 'Path is not a directory';
            exit;
        }

        if (!is_readable($fullPath)) {
            http_response_code(403);
            echo 'Directory not readable';
            exit;
        }

        $dirName = basename($fullPath);
        if ($dirName === '') {
            $dirName = 'catalog';
        }
        $archiveName = $dirName . '.tar.gz';

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $archiveName . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        header('X-Archive-Name: ' . $archiveName);
        header('X-Source-Path: ' . basename($fullPath));

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $cmd = 'cd ' . escapeshellarg(dirname($fullPath)) . ' && tar -czf - ' . escapeshellarg(basename($fullPath)) . ' 2>/dev/null';
        $process = proc_open($cmd, $descriptorspec, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);

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
            echo 'Failed to create archive';
        }

        exit;
    }

    public function streamVideo(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();
        $relativePath = (string)($_GET['path'] ?? '');

        try {
            $segments = PathGuard::toSegments($relativePath);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo $e->getMessage();
            exit;
        }

        $fullPath = PathGuard::joinCatalog($catalogPath, $segments);

        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo 'File not found';
            exit;
        }

        if (!is_file($fullPath)) {
            http_response_code(400);
            echo 'Path is not a file';
            exit;
        }

        if (!is_readable($fullPath)) {
            http_response_code(403);
            echo 'File not readable';
            exit;
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $videoExtensions = ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi', 'm4v'];

        if (!in_array($extension, $videoExtensions)) {
            http_response_code(400);
            echo 'Not a supported video file';
            exit;
        }

        $fileSize = filesize($fullPath);

        $mimeTypes = [
            'mp4' => 'video/mp4',
            'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo'
        ];
        $mimeType = $mimeTypes[$extension] ?? 'video/mp4';

        $start = 0;
        $end = $fileSize - 1;

        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                $start = $matches[1] !== '' ? intval($matches[1]) : 0;
                $end = $matches[2] !== '' ? intval($matches[2]) : $fileSize - 1;

                if ($start > $end || $start >= $fileSize) {
                    http_response_code(416);
                    header("Content-Range: bytes */$fileSize");
                    exit;
                }

                $end = min($end, $fileSize - 1);

                http_response_code(206);
                header("Content-Range: bytes $start-$end/$fileSize");
            }
        }

        $length = $end - $start + 1;

        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Cache-Control: public, max-age=3600');
        header('Content-Disposition: inline');

        $handle = fopen($fullPath, 'rb');
        if ($handle) {
            if ($start > 0) {
                fseek($handle, $start);
            }

            $remaining = $length;
            while (!feof($handle) && $remaining > 0) {
                $chunkSize = min(8192, $remaining);
                $chunk = fread($handle, $chunkSize);
                if ($chunk !== false) {
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    ob_flush();
                    flush();
                }
            }
            fclose($handle);
        } else {
            http_response_code(500);
            echo 'Failed to read file';
        }

        exit;
    }

    public function downloadFile(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();
        $relativePath = (string)($_GET['path'] ?? '');

        try {
            $segments = PathGuard::toSegments($relativePath);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo $e->getMessage();
            exit;
        }

        $fullPath = PathGuard::joinCatalog($catalogPath, $segments);

        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo 'File not found';
            exit;
        }

        if (!is_file($fullPath)) {
            http_response_code(400);
            echo 'Path is not a file';
            exit;
        }

        if (!is_readable($fullPath)) {
            http_response_code(403);
            echo 'File not readable';
            exit;
        }

        $fileName = basename($fullPath);
        $fileSize = filesize($fullPath);
        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        header('X-File-Name: ' . $fileName);
        header('X-File-Size: ' . $fileSize);

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
            echo 'Failed to read file';
        }

        exit;
    }

    public function getNfo(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $path = (string)($_GET['path'] ?? '');
        $path = trim($path);

        header('Content-Type: application/json; charset=utf-8');

        if ($path === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Path is required']);
            exit;
        }

        try {
            $segments = PathGuard::toSegments($path);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }

        $fullPath = PathGuard::joinCatalog($catalogPath, $segments);

        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Target not found']);
            exit;
        }

        $isDir = is_dir($fullPath);
        if (!$isDir && is_file($fullPath)) {
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if ($extension === 'nfo') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'NFO files are not supported as a target']);
                exit;
            }
        }

        if ($isDir) {
            $nfoFullPath = rtrim($fullPath, '/') . '/tvshow.nfo';
            $nfoRelativePath = PathGuard::segmentsToRelativePath(array_merge($segments, ['tvshow.nfo']));
        } else {
            $dir = dirname($fullPath);
            $base = pathinfo($fullPath, PATHINFO_FILENAME);
            $nfoFullPath = rtrim($dir, '/') . '/' . $base . '.nfo';
            $nfoRelativePath = PathGuard::segmentsToRelativePath(array_merge(array_slice($segments, 0, -1), [$base . '.nfo']));
        }

        $exists = file_exists($nfoFullPath) && is_file($nfoFullPath);
        $title = '';
        $year = '';

        if ($exists && is_readable($nfoFullPath)) {
            $content = file_get_contents($nfoFullPath);
            if ($content !== false && trim($content) !== '') {
                if (function_exists('simplexml_load_string')) {
                    libxml_use_internal_errors(true);
                    $xml = simplexml_load_string($content);
                    if ($xml !== false) {
                        $title = isset($xml->title) ? (string)$xml->title : '';
                        $year = isset($xml->year) ? (string)$xml->year : '';
                    }
                    libxml_clear_errors();
                } else {
                    $title = self::parseNfoField($content, 'title');
                    $year = self::parseNfoField($content, 'year');
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'exists' => $exists,
            'title' => $title,
            'year' => $year,
            'nfoPath' => $nfoRelativePath,
            'targetIsDir' => $isDir,
        ]);
        exit;
    }

    public function saveNfo(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $path = (string)($_POST['path'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        $year = trim((string)($_POST['year'] ?? ''));

        header('Content-Type: application/json; charset=utf-8');

        if (trim($path) === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Path is required']);
            exit;
        }
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Title is required']);
            exit;
        }
        if ($year !== '' && (!ctype_digit($year) || strlen($year) !== 4)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Year must be a 4-digit number']);
            exit;
        }

        try {
            $segments = PathGuard::toSegments($path);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }

        $fullPath = PathGuard::joinCatalog($catalogPath, $segments);

        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Target not found']);
            exit;
        }

        $isDir = is_dir($fullPath);
        if (!$isDir && is_file($fullPath)) {
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if ($extension === 'nfo') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'NFO files are not supported as a target']);
                exit;
            }
        }

        if ($isDir) {
            $nfoFullPath = rtrim($fullPath, '/') . '/tvshow.nfo';
            $nfoRelativePath = PathGuard::segmentsToRelativePath(array_merge($segments, ['tvshow.nfo']));
        } else {
            $dir = dirname($fullPath);
            $base = pathinfo($fullPath, PATHINFO_FILENAME);
            $nfoFullPath = rtrim($dir, '/') . '/' . $base . '.nfo';
            $nfoRelativePath = PathGuard::segmentsToRelativePath(array_merge(array_slice($segments, 0, -1), [$base . '.nfo']));
        }

        $rootName = $isDir ? 'tvshow' : 'movie';
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<' . $rootName . ">\n";
        $xml .= '  <title>' . self::xmlEscape($title) . "</title>\n";
        if ($year !== '') {
            $xml .= '  <year>' . self::xmlEscape($year) . "</year>\n";
        }
        $xml .= '</' . $rootName . ">\n";

        $parentDir = dirname($nfoFullPath);
        if (!is_dir($parentDir) || !is_writable($parentDir)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Target directory is not writable']);
            exit;
        }

        $bytes = @file_put_contents($nfoFullPath, $xml, LOCK_EX);
        if ($bytes === false) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unknown error';
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to write NFO: ' . $reason]);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'nfoPath' => $nfoRelativePath,
        ]);
        exit;
    }
}

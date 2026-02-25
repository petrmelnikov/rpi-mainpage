<?php

namespace App\Controller;

use App\FileIndexManager;
use App\Router;
use App\ShellCommandExecutor;
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

    private static function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $last = strtolower(substr($value, -1));
        $num = $value;
        $mult = 1;
        if (in_array($last, ['k', 'm', 'g'], true)) {
            $num = substr($value, 0, -1);
            $mult = match ($last) {
                'k' => 1024,
                'm' => 1024 * 1024,
                'g' => 1024 * 1024 * 1024,
            };
        }
        if (!is_numeric($num)) {
            return 0;
        }
        return (int)round(((float)$num) * $mult);
    }

    private static function jsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }

    private static function prepareBinaryStreamResponse(): void
    {
        // Prevent any buffered/debug output from corrupting binary payloads.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    private static function commandExists(string $command): bool
    {
        if (getenv('SHELL_OVER_SSH') === '1') {
            try {
                $result = ShellCommandExecutor::execute('command -v ' . escapeshellarg($command) . ' 2>/dev/null || true');
            } catch (\RuntimeException) {
                return false;
            }

            return trim($result) !== '';
        }

        $result = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }

    private static function isDirectoryWritableForOperation(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        // Real capability check for the CURRENT PHP process.
        // Use direct create in target directory (tempnam may fallback to /tmp).
        $probe = rtrim($dir, '/') . '/.wprobe_' . bin2hex(random_bytes(8));
        $fh = @fopen($probe, 'wb');
        if ($fh === false) {
            return false;
        }

        @fclose($fh);

        @unlink($probe);
        return true;
    }

    private static function downloadByUrlToDirectory(string $url, string $targetDir): array
    {
        $escapedDir = escapeshellarg($targetDir);
        $escapedUrl = escapeshellarg($url);
        $isShellOverSsh = getenv('SHELL_OVER_SSH') === '1';

        $commands = [
            [
                'tool' => 'aria2c',
                'cmd' => 'aria2c --allow-overwrite=false --auto-file-renaming=false --dir=' . $escapedDir . ' -- ' . $escapedUrl . ' 2>&1',
            ],
            [
                'tool' => 'wget',
                'cmd' => 'wget -P ' . $escapedDir . ' ' . $escapedUrl . ' 2>&1',
            ],
        ];

        $modes = ['local'];
        if ($isShellOverSsh) {
            $modes[] = 'ssh';
        }

        $errors = [];
        $notFoundCount = 0;
        $attemptsCount = 0;

        foreach ($modes as $mode) {
            foreach ($commands as $item) {
                $output = [];
                $exitCode = 1;
                $attemptsCount++;

                if ($mode === 'ssh') {
                    $marker = '__CMD_EXIT_CODE__';
                    $wrapped = $item['cmd'] . '; printf "\\n' . $marker . '%s\\n" "$?"';
                    try {
                        $raw = ShellCommandExecutor::execute($wrapped);
                    } catch (\RuntimeException $e) {
                        $raw = $e->getMessage();
                    }

                    $output = explode("\n", (string)$raw);
                    for ($i = count($output) - 1; $i >= 0; $i--) {
                        $line = trim((string)$output[$i]);
                        if (str_starts_with($line, $marker)) {
                            $exitCode = (int)substr($line, strlen($marker));
                            unset($output[$i]);
                            break;
                        }
                    }
                    $output = array_values($output);
                } else {
                    @exec($item['cmd'], $output, $exitCode);
                }

                if ($exitCode === 0) {
                    return ['ok' => true, 'tool' => $item['tool'], 'mode' => $mode];
                }

                $tail = trim((string)implode("\n", array_slice($output, -5)));
                if ($exitCode === 127 || stripos($tail, 'not found') !== false) {
                    $notFoundCount++;
                }

                $errors[] = ($mode === 'ssh' ? 'remote ' : 'local ') . $item['tool']
                    . ($tail !== '' ? (': ' . $tail) : ': failed with exit code ' . $exitCode);
            }
        }

        if ($attemptsCount > 0 && $notFoundCount === $attemptsCount) {
            return ['ok' => false, 'error' => 'Neither aria2c nor wget is available in container/remote context'];
        }

        return ['ok' => false, 'error' => implode(' | ', $errors)];
    }

    private static function getUploadBaseDir(): string
    {
        $candidates = [];

        // Prefer RAM-backed tmpfs on Linux if available.
        $candidates[] = '/dev/shm/rpi-mainpage-upload';

        $tmp = rtrim((string)sys_get_temp_dir(), '/');
        if ($tmp !== '') {
            $candidates[] = $tmp . '/rpi-mainpage-upload';
        }

        foreach ($candidates as $dir) {
            if (@is_dir($dir) || @mkdir($dir, 0775, true)) {
                if (@is_writable($dir)) {
                    return $dir;
                }
            }
        }

        // Fallback to current working directory (should be writable in dev).
        $cwd = getcwd();
        $fallback = rtrim((string)$cwd, '/') . '/tmp/rpi-mainpage-upload';
        @mkdir($fallback, 0775, true);
        return $fallback;
    }

    private static function validateUploadId(string $uploadId): string
    {
        $uploadId = trim($uploadId);
        if (!preg_match('/^[a-f0-9]{40}$/', $uploadId)) {
            throw new \InvalidArgumentException('Invalid uploadId');
        }
        return $uploadId;
    }

    private static function getUploadDir(string $uploadId): string
    {
        $base = self::getUploadBaseDir();
        return rtrim($base, '/') . '/' . $uploadId;
    }

    private static function writeUploadMeta(string $uploadDir, array $meta): void
    {
        @mkdir($uploadDir, 0775, true);
        file_put_contents($uploadDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private static function readUploadMeta(string $uploadDir): array
    {
        $path = $uploadDir . '/meta.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Upload not initialized');
        }
        $json = file_get_contents($path);
        $meta = json_decode((string)$json, true);
        if (!is_array($meta)) {
            throw new \RuntimeException('Upload metadata is corrupted');
        }
        return $meta;
    }

    private static function getVideoProgressStoragePath(): string
    {
        $baseDir = self::getUploadBaseDir();
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }
        return rtrim($baseDir, '/') . '/video_progress.json';
    }

    private static function loadVideoProgressMap(): array
    {
        $storagePath = self::getVideoProgressStoragePath();
        if (!is_file($storagePath)) {
            return [];
        }

        $json = @file_get_contents($storagePath);
        if ($json === false || trim($json) === '') {
            return [];
        }

        $map = json_decode($json, true);
        if (!is_array($map)) {
            return [];
        }

        return $map;
    }

    private static function saveVideoProgressMap(array $map): void
    {
        $storagePath = self::getVideoProgressStoragePath();
        $encoded = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode video progress map');
        }

        $ok = @file_put_contents($storagePath, $encoded, LOCK_EX);
        if ($ok === false) {
            throw new \RuntimeException('Failed to write video progress map');
        }
    }

    private static function listReceivedChunks(string $uploadDir, int $totalChunks): array
    {
        $received = [];
        if (!is_dir($uploadDir)) {
            return $received;
        }
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $uploadDir . '/chunk_' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
            if (is_file($chunkPath)) {
                $received[] = $i;
            }
        }
        return $received;
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
        $router->addRoute('POST', '/file-index/download-url', [$this, 'downloadByUrl']);
        $router->addRoute('POST', '/file-index/upload/init', [$this, 'uploadInit']);
        $router->addRoute('POST', '/file-index/upload/status', [$this, 'uploadStatus']);
        $router->addRoute('POST', '/file-index/upload/chunk', [$this, 'uploadChunk']);
        $router->addRoute('POST', '/file-index/upload/finish', [$this, 'uploadFinish']);
        $router->addRoute('POST', '/file-index/pin', [$this, 'pin']);
        $router->addRoute('POST', '/file-index/unpin', [$this, 'unpin']);
        $router->addRoute('GET', '/file-index/download', [$this, 'downloadDirectory']);
        $router->addRoute('GET', '/file-index/stream', [$this, 'streamVideo']);
        $router->addRoute('GET', '/file-index/video-progress', [$this, 'getVideoProgress']);
        $router->addRoute('GET', '/file-index/video-progress/list', [$this, 'getVideoProgressList']);
        $router->addRoute('POST', '/file-index/video-progress', [$this, 'saveVideoProgress']);
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
        if (!self::isDirectoryWritableForOperation($parentFullPath)) {
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
        if (!self::isDirectoryWritableForOperation($targetDir)) {
            self::redirectWithError($redirectUrl, 'Target directory is not writable');
        }

        $fileUploadsEnabled = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($fileUploadsEnabled === false) {
            self::redirectWithError($redirectUrl, 'File uploads are disabled on the server (file_uploads=Off)');
        }

        if (!isset($_FILES['file'])) {
            $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMaxBytes = self::iniSizeToBytes((string)ini_get('post_max_size'));

            if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
                self::redirectWithError(
                    $redirectUrl,
                    'Upload failed: request body too large for server (post_max_size=' . ini_get('post_max_size') . ')'
                );
            }

            if ($contentType !== '' && !str_starts_with(strtolower($contentType), 'multipart/form-data')) {
                self::redirectWithError($redirectUrl, 'Upload failed: form must use multipart/form-data');
            }

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

    public function downloadByUrl(): string
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $returnPath = (string)($_POST['returnPath'] ?? '');
        $targetPath = (string)($_POST['targetPath'] ?? '');
        $url = trim((string)($_POST['url'] ?? ''));

        $redirectUrl = self::buildRedirectUrl($returnPath);

        if ($url === '') {
            self::redirectWithError($redirectUrl, 'Download URL is required');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            self::redirectWithError($redirectUrl, 'Invalid download URL');
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            self::redirectWithError($redirectUrl, 'Only HTTP/HTTPS URLs are supported');
        }

        try {
            $targetSegments = PathGuard::toSegmentsAllowEmpty($targetPath);
        } catch (\InvalidArgumentException $e) {
            self::redirectWithError($redirectUrl, $e->getMessage());
        }

        $targetDir = PathGuard::joinCatalog($catalogPath, $targetSegments);
        if (!is_dir($targetDir)) {
            self::redirectWithError($redirectUrl, 'Target directory not found');
        }
        if (!self::isDirectoryWritableForOperation($targetDir)) {
            self::redirectWithError($redirectUrl, 'Target directory is not writable');
        }

        $result = self::downloadByUrlToDirectory($url, $targetDir);
        if (!($result['ok'] ?? false)) {
            self::redirectWithError($redirectUrl, 'Download failed. ' . ($result['error'] ?? 'Unknown error'));
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    /**
     * Chunked upload init: returns uploadId + received chunks for resume.
     * Expects: targetPath, fileName, fileSize, lastModified (optional), chunkSize (optional).
     */
    public function uploadInit(): void
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $targetPath = (string)($_POST['targetPath'] ?? '');
        $fileName = (string)($_POST['fileName'] ?? '');
        $fileSize = (string)($_POST['fileSize'] ?? '0');
        $lastModified = (string)($_POST['lastModified'] ?? '0');
        $chunkSize = (string)($_POST['chunkSize'] ?? '');

        try {
            $dirSegments = PathGuard::toSegmentsAllowEmpty($targetPath);
            $safeName = self::validateSinglePathSegment(basename($fileName), 'File name');
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $sizeInt = (int)$fileSize;
        if ($sizeInt <= 0) {
            self::jsonResponse(['ok' => false, 'error' => 'Invalid file size'], 400);
        }

        $chunkSizeInt = (int)$chunkSize;
        if ($chunkSizeInt <= 0) {
            $chunkSizeInt = 4 * 1024 * 1024; // 4MB default
        }
        $chunkSizeInt = max(256 * 1024, min($chunkSizeInt, 50 * 1024 * 1024));
        $totalChunks = (int)ceil($sizeInt / $chunkSizeInt);

        $targetDir = PathGuard::joinCatalog($catalogPath, $dirSegments);
        if (!is_dir($targetDir)) {
            self::jsonResponse(['ok' => false, 'error' => 'Target directory not found'], 404);
        }
        if (!self::isDirectoryWritableForOperation($targetDir)) {
            self::jsonResponse(['ok' => false, 'error' => 'Target directory is not writable'], 403);
        }

        $uploadId = sha1(
            PathGuard::segmentsToRelativePath($dirSegments) . "\n" .
            $safeName . "\n" .
            (string)$sizeInt . "\n" .
            (string)((int)$lastModified) . "\n" .
            (string)$chunkSizeInt
        );

        $uploadDir = self::getUploadDir($uploadId);
        @mkdir($uploadDir, 0775, true);

        $metaPath = $uploadDir . '/meta.json';
        if (!is_file($metaPath)) {
            $meta = [
                'uploadId' => $uploadId,
                'targetPath' => PathGuard::segmentsToRelativePath($dirSegments),
                'fileName' => $safeName,
                'fileSize' => $sizeInt,
                'lastModified' => (int)$lastModified,
                'chunkSize' => $chunkSizeInt,
                'totalChunks' => $totalChunks,
                'createdAt' => time(),
            ];
            self::writeUploadMeta($uploadDir, $meta);
        } else {
            // Validate that existing meta matches; otherwise refuse to resume.
            try {
                $meta = self::readUploadMeta($uploadDir);
            } catch (\RuntimeException $e) {
                self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
            }
            $expected = [
                'targetPath' => PathGuard::segmentsToRelativePath($dirSegments),
                'fileName' => $safeName,
                'fileSize' => $sizeInt,
                'chunkSize' => $chunkSizeInt,
                'totalChunks' => $totalChunks,
            ];
            foreach ($expected as $k => $v) {
                if (!isset($meta[$k]) || $meta[$k] !== $v) {
                    self::jsonResponse(['ok' => false, 'error' => 'Existing uploadId metadata does not match file'], 409);
                }
            }
        }

        $received = self::listReceivedChunks($uploadDir, $totalChunks);
        self::jsonResponse([
            'ok' => true,
            'uploadId' => $uploadId,
            'chunkSize' => $chunkSizeInt,
            'totalChunks' => $totalChunks,
            'received' => $received,
        ]);
    }

    public function uploadStatus(): void
    {
        $uploadId = (string)($_POST['uploadId'] ?? '');
        try {
            $uploadId = self::validateUploadId($uploadId);
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $uploadDir = self::getUploadDir($uploadId);
        try {
            $meta = self::readUploadMeta($uploadDir);
        } catch (\RuntimeException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        }

        $totalChunks = (int)($meta['totalChunks'] ?? 0);
        if ($totalChunks <= 0) {
            self::jsonResponse(['ok' => false, 'error' => 'Upload metadata invalid'], 500);
        }

        $received = self::listReceivedChunks($uploadDir, $totalChunks);
        $bytesReceived = 0;
        foreach ($received as $i) {
            $chunkPath = $uploadDir . '/chunk_' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
            $bytesReceived += (int)@filesize($chunkPath);
        }

        self::jsonResponse([
            'ok' => true,
            'uploadId' => $uploadId,
            'meta' => $meta,
            'received' => $received,
            'bytesReceived' => $bytesReceived,
        ]);
    }

    /**
     * Receives one chunk via multipart: field name "chunk".
     * Expects: uploadId, chunkIndex.
     */
    public function uploadChunk(): void
    {
        $uploadId = (string)($_POST['uploadId'] ?? '');
        $chunkIndex = (string)($_POST['chunkIndex'] ?? '');

        try {
            $uploadId = self::validateUploadId($uploadId);
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        if (!is_numeric($chunkIndex)) {
            self::jsonResponse(['ok' => false, 'error' => 'Invalid chunkIndex'], 400);
        }
        $chunkIndexInt = (int)$chunkIndex;

        $uploadDir = self::getUploadDir($uploadId);
        try {
            $meta = self::readUploadMeta($uploadDir);
        } catch (\RuntimeException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        }

        $totalChunks = (int)($meta['totalChunks'] ?? 0);
        $chunkSize = (int)($meta['chunkSize'] ?? 0);
        $fileSize = (int)($meta['fileSize'] ?? 0);

        if ($totalChunks <= 0 || $chunkSize <= 0 || $fileSize <= 0) {
            self::jsonResponse(['ok' => false, 'error' => 'Upload metadata invalid'], 500);
        }
        if ($chunkIndexInt < 0 || $chunkIndexInt >= $totalChunks) {
            self::jsonResponse(['ok' => false, 'error' => 'chunkIndex out of range'], 400);
        }

        if (!isset($_FILES['chunk'])) {
            self::jsonResponse(['ok' => false, 'error' => 'Missing chunk file'], 400);
        }
        $upload = $_FILES['chunk'];
        $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded chunk is too large',
                UPLOAD_ERR_PARTIAL => 'Chunk upload was incomplete',
                UPLOAD_ERR_NO_FILE => 'Missing chunk file',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded chunk',
                UPLOAD_ERR_EXTENSION => 'Chunk upload blocked by server extension',
                default => 'Chunk upload failed',
            };
            self::jsonResponse(['ok' => false, 'error' => $msg], 400);
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpName)) {
            self::jsonResponse(['ok' => false, 'error' => 'Invalid uploaded chunk'], 400);
        }

        $chunkPath = $uploadDir . '/chunk_' . str_pad((string)$chunkIndexInt, 6, '0', STR_PAD_LEFT) . '.part';

        // Validate chunk size (allow last chunk to be smaller).
        $expectedMax = $chunkSize;
        $expectedMin = 1;
        if ($chunkIndexInt === $totalChunks - 1) {
            $lastExpected = $fileSize - ($chunkSize * ($totalChunks - 1));
            $expectedMax = max(1, $lastExpected);
        }

        $actualSize = (int)@filesize($tmpName);
        if ($actualSize < $expectedMin || $actualSize > $expectedMax) {
            self::jsonResponse([
                'ok' => false,
                'error' => 'Unexpected chunk size',
                'expectedMax' => $expectedMax,
                'actual' => $actualSize,
            ], 400);
        }

        @mkdir($uploadDir, 0775, true);
        // Overwrite is allowed for retries.
        if (is_file($chunkPath)) {
            @unlink($chunkPath);
        }

        if (!@move_uploaded_file($tmpName, $chunkPath)) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unknown error';
            self::jsonResponse(['ok' => false, 'error' => 'Failed to store chunk: ' . $reason], 500);
        }

        self::jsonResponse(['ok' => true, 'uploadId' => $uploadId, 'chunkIndex' => $chunkIndexInt]);
    }

    /**
     * Finish upload: validates all chunks exist and assembles final file.
     */
    public function uploadFinish(): void
    {
        $fileIndexManager = new FileIndexManager();
        $catalogPath = $fileIndexManager->getCatalogPath();

        $uploadId = (string)($_POST['uploadId'] ?? '');
        try {
            $uploadId = self::validateUploadId($uploadId);
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $uploadDir = self::getUploadDir($uploadId);
        try {
            $meta = self::readUploadMeta($uploadDir);
        } catch (\RuntimeException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        }

        $targetPath = (string)($meta['targetPath'] ?? '');
        $fileName = (string)($meta['fileName'] ?? '');
        $fileSize = (int)($meta['fileSize'] ?? 0);
        $chunkSize = (int)($meta['chunkSize'] ?? 0);
        $totalChunks = (int)($meta['totalChunks'] ?? 0);

        if ($fileName === '' || $fileSize <= 0 || $chunkSize <= 0 || $totalChunks <= 0) {
            self::jsonResponse(['ok' => false, 'error' => 'Upload metadata invalid'], 500);
        }

        try {
            $dirSegments = PathGuard::toSegmentsAllowEmpty($targetPath);
            $safeName = self::validateSinglePathSegment($fileName, 'File name');
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $targetDir = PathGuard::joinCatalog($catalogPath, $dirSegments);
        if (!is_dir($targetDir)) {
            self::jsonResponse(['ok' => false, 'error' => 'Target directory not found'], 404);
        }
        if (!self::isDirectoryWritableForOperation($targetDir)) {
            self::jsonResponse(['ok' => false, 'error' => 'Target directory is not writable'], 403);
        }

        $destPath = PathGuard::joinCatalog($catalogPath, array_merge($dirSegments, [$safeName]));
        if (file_exists($destPath)) {
            self::jsonResponse(['ok' => false, 'error' => 'File already exists'], 409);
        }

        // Validate all chunks exist.
        $missing = [];
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $uploadDir . '/chunk_' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
            if (!is_file($chunkPath)) {
                $missing[] = $i;
            }
        }
        if (count($missing) > 0) {
            self::jsonResponse(['ok' => false, 'error' => 'Missing chunks', 'missing' => $missing], 409);
        }

        // Assemble.
        $out = @fopen($destPath, 'wb');
        if (!$out) {
            self::jsonResponse(['ok' => false, 'error' => 'Failed to create destination file'], 500);
        }

        $written = 0;
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $uploadDir . '/chunk_' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
            $in = @fopen($chunkPath, 'rb');
            if (!$in) {
                fclose($out);
                @unlink($destPath);
                self::jsonResponse(['ok' => false, 'error' => 'Failed to read chunk ' . $i], 500);
            }

            while (!feof($in)) {
                $buf = fread($in, 1024 * 1024);
                if ($buf === false) {
                    fclose($in);
                    fclose($out);
                    @unlink($destPath);
                    self::jsonResponse(['ok' => false, 'error' => 'Failed while reading chunk ' . $i], 500);
                }
                if ($buf === '') {
                    break;
                }
                $w = fwrite($out, $buf);
                if ($w === false) {
                    fclose($in);
                    fclose($out);
                    @unlink($destPath);
                    self::jsonResponse(['ok' => false, 'error' => 'Failed while writing destination file'], 500);
                }
                $written += $w;
            }
            fclose($in);
        }

        fclose($out);

        if ($written !== $fileSize) {
            @unlink($destPath);
            self::jsonResponse(['ok' => false, 'error' => 'Assembled file size mismatch', 'written' => $written, 'expected' => $fileSize], 500);
        }

        // Cleanup chunks + meta.
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $uploadDir . '/chunk_' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
            @unlink($chunkPath);
        }
        @unlink($uploadDir . '/meta.json');
        @rmdir($uploadDir);

        self::jsonResponse(['ok' => true, 'fileName' => $safeName, 'path' => PathGuard::segmentsToRelativePath(array_merge($dirSegments, [$safeName]))]);
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

        self::prepareBinaryStreamResponse();

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

        self::prepareBinaryStreamResponse();

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

    public function getVideoProgress(): string
    {
        $path = (string)($_GET['path'] ?? '');
        if (trim($path) === '') {
            self::jsonResponse(['ok' => false, 'error' => 'Path is required'], 400);
        }

        try {
            $normalizedPath = PathGuard::segmentsToRelativePath(PathGuard::toSegments($path));
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $progressMap = self::loadVideoProgressMap();
        $rawProgress = $progressMap[$normalizedPath] ?? 0;
        $time = is_array($rawProgress) ? (float)($rawProgress['time'] ?? 0) : (float)$rawProgress;
        self::jsonResponse(['ok' => true, 'time' => max(0, $time)]);
    }

    public function getVideoProgressList(): string
    {
        $paths = $_GET['paths'] ?? [];
        if (!is_array($paths)) {
            self::jsonResponse(['ok' => false, 'error' => 'paths must be an array'], 400);
        }

        $progressMap = self::loadVideoProgressMap();
        $result = [];

        foreach ($paths as $path) {
            $path = trim((string)$path);
            if ($path === '') {
                continue;
            }

            try {
                $normalizedPath = PathGuard::segmentsToRelativePath(PathGuard::toSegments($path));
            } catch (\InvalidArgumentException) {
                continue;
            }

            $rawProgress = $progressMap[$normalizedPath] ?? 0;
            $time = is_array($rawProgress) ? (float)($rawProgress['time'] ?? 0) : (float)$rawProgress;
            $duration = is_array($rawProgress) ? (float)($rawProgress['duration'] ?? 0) : 0.0;
            $percent = is_array($rawProgress) ? (int)($rawProgress['percent'] ?? 0) : 0;

            if ($percent <= 0 && $duration > 0) {
                $percent = (int)round((max(0, $time) / $duration) * 100);
            }

            $result[$normalizedPath] = [
                'time' => max(0, $time),
                'duration' => max(0, $duration),
                'percent' => min(100, max(0, $percent)),
            ];
        }

        self::jsonResponse(['ok' => true, 'progress' => $result]);
    }

    public function saveVideoProgress(): string
    {
        $payload = $_POST;
        if (empty($payload)) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $path = trim((string)($payload['path'] ?? ''));
        $time = (float)($payload['time'] ?? 0);
        $duration = (float)($payload['duration'] ?? 0);

        if ($path === '') {
            self::jsonResponse(['ok' => false, 'error' => 'Path is required'], 400);
        }

        try {
            $normalizedPath = PathGuard::segmentsToRelativePath(PathGuard::toSegments($path));
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $safeTime = max(0, $time);
        $safeDuration = max(0, $duration);
        $percent = 0;
        if ($safeDuration > 0) {
            $percent = (int)round(($safeTime / $safeDuration) * 100);
        }
        $safePercent = min(100, max(0, $percent));

        try {
            $progressMap = self::loadVideoProgressMap();
            $progressMap[$normalizedPath] = [
                'time' => $safeTime,
                'duration' => $safeDuration,
                'percent' => $safePercent,
            ];
            self::saveVideoProgressMap($progressMap);
        } catch (\RuntimeException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        self::jsonResponse([
            'ok' => true,
            'time' => $safeTime,
            'duration' => $safeDuration,
            'percent' => $safePercent,
        ]);
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

        self::prepareBinaryStreamResponse();

        $handle = fopen($fullPath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk !== false) {
                    echo $chunk;
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
        if (!is_dir($parentDir) || !self::isDirectoryWritableForOperation($parentDir)) {
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

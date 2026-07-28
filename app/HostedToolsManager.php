<?php

namespace App;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class HostedToolsManager
{
    public const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;
    public const MAX_EXTRACTED_BYTES = 512 * 1024 * 1024;
    public const MAX_ARCHIVE_ENTRIES = 5000;

    private string $storageDir;
    private string $appsDir;
    private string $manifestsDir;

    public function __construct(?string $storageDir = null)
    {
        $appRoot = rtrim(App::getInstance()->appRoot, '/');
        $configuredDir = trim((string)getenv('TOOLS_STORAGE_DIR'));
        $uploadBaseDir = trim((string)getenv('UPLOAD_BASE_DIR'));

        if ($storageDir !== null && trim($storageDir) !== '') {
            $this->storageDir = rtrim($storageDir, '/');
        } elseif ($configuredDir !== '') {
            $this->storageDir = rtrim($configuredDir, '/');
        } elseif ($uploadBaseDir !== '') {
            $this->storageDir = rtrim($uploadBaseDir, '/') . '/hosted-tools';
        } else {
            $this->storageDir = $appRoot . '/var/hosted-tools';
        }

        $this->appsDir = $this->storageDir . '/apps';
        $this->manifestsDir = $this->storageDir . '/manifests';
    }

    public function getAppsDir(): string
    {
        return $this->appsDir;
    }

    public function listTools(): array
    {
        if (!is_dir($this->manifestsDir)) {
            return [];
        }

        $tools = [];
        $manifestPaths = glob($this->manifestsDir . '/*.json') ?: [];

        foreach ($manifestPaths as $manifestPath) {
            $manifest = json_decode((string)@file_get_contents($manifestPath), true);
            if (!is_array($manifest) || !$this->isValidId((string)($manifest['id'] ?? ''))) {
                continue;
            }

            $toolDir = $this->appsDir . '/' . $manifest['id'];
            $entryPath = (string)($manifest['entryPath'] ?? '');
            if (!is_dir($toolDir) || !$this->isSafeRelativePath($entryPath) || !is_file($toolDir . '/' . $entryPath)) {
                continue;
            }

            $manifest['url'] = $this->buildPublicUrl((string)$manifest['id'], $entryPath);
            $tools[] = $manifest;
        }

        usort($tools, static function (array $left, array $right): int {
            return strcmp((string)($right['uploadedAt'] ?? ''), (string)($left['uploadedAt'] ?? ''));
        });

        return $tools;
    }

    public function install(string $sourcePath, string $originalName, string $displayName = ''): array
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('Загруженный файл недоступен.');
        }

        $uploadSize = filesize($sourcePath);
        if ($uploadSize === false || $uploadSize <= 0) {
            throw new RuntimeException('Загруженный файл пуст.');
        }
        if ($uploadSize > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Файл превышает лимит 100 МБ.');
        }

        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['html', 'htm', 'zip'], true)) {
            throw new RuntimeException('Поддерживаются только файлы HTML, HTM и ZIP.');
        }
        if ($extension === 'zip' && !class_exists(ZipArchive::class)) {
            throw new RuntimeException('На сервере не установлено PHP-расширение zip.');
        }

        $this->ensureStorageDirectories();

        $name = $this->normalizeDisplayName($displayName, $originalName);
        $id = $this->makeId($name);
        $stagingDir = $this->storageDir . '/.staging-' . bin2hex(random_bytes(10));

        if (!@mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            throw new RuntimeException('Не удалось подготовить каталог для загрузки.');
        }

        try {
            if ($extension === 'zip') {
                $package = $this->extractZip($sourcePath, $stagingDir);
            } else {
                $targetPath = $stagingDir . '/index.html';
                if (!@copy($sourcePath, $targetPath)) {
                    throw new RuntimeException('Не удалось сохранить HTML-файл.');
                }
                $package = [
                    'entryPath' => 'index.html',
                    'filesCount' => 1,
                    'sizeBytes' => (int)$uploadSize,
                ];
            }

            $targetDir = $this->appsDir . '/' . $id;
            if (!@rename($stagingDir, $targetDir)) {
                throw new RuntimeException('Не удалось опубликовать инструмент.');
            }

            $manifest = [
                'id' => $id,
                'name' => $name,
                'sourceName' => $this->safeOriginalName($originalName),
                'entryPath' => $package['entryPath'],
                'filesCount' => $package['filesCount'],
                'sizeBytes' => $package['sizeBytes'],
                'uploadedAt' => gmdate(DATE_ATOM),
            ];

            try {
                $this->writeManifest($manifest);
            } catch (\Throwable $error) {
                $this->removeDirectory($targetDir);
                throw $error;
            }

            $manifest['url'] = $this->buildPublicUrl($id, (string)$package['entryPath']);
            return $manifest;
        } catch (\Throwable $error) {
            if (is_dir($stagingDir)) {
                $this->removeDirectory($stagingDir);
            }
            throw $error;
        }
    }

    public function delete(string $id): void
    {
        if (!$this->isValidId($id)) {
            throw new RuntimeException('Некорректный идентификатор инструмента.');
        }

        $toolDir = $this->appsDir . '/' . $id;
        $manifestPath = $this->manifestsDir . '/' . $id . '.json';
        if (!is_dir($toolDir) && !is_file($manifestPath)) {
            throw new RuntimeException('Инструмент не найден.');
        }

        if (is_dir($toolDir)) {
            $this->removeDirectory($toolDir);
        }
        if (is_file($manifestPath) && !@unlink($manifestPath)) {
            throw new RuntimeException('Файлы удалены, но не удалось удалить метаданные инструмента.');
        }
    }

    public function resolvePublicFile(string $relativeRequestPath): ?string
    {
        $relativeRequestPath = ltrim($relativeRequestPath, '/');
        if (!$this->isSafeRelativePath($relativeRequestPath)) {
            return null;
        }

        $candidate = $this->appsDir . '/' . $relativeRequestPath;
        $realAppsDir = realpath($this->appsDir);
        $realCandidate = realpath($candidate);

        if ($realAppsDir === false || $realCandidate === false || !is_file($realCandidate)) {
            return null;
        }
        if (!str_starts_with($realCandidate, $realAppsDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realCandidate;
    }

    private function extractZip(string $sourcePath, string $targetDir): array
    {
        $zip = new ZipArchive();
        $openResult = $zip->open($sourcePath);
        if ($openResult !== true) {
            throw new RuntimeException('Не удалось открыть ZIP-архив.');
        }

        try {
            if ($zip->numFiles <= 0) {
                throw new RuntimeException('ZIP-архив пуст.');
            }
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new RuntimeException('В архиве слишком много файлов (максимум 5000).');
            }

            $filesCount = 0;
            $totalBytes = 0;
            $htmlPaths = [];
            $seenPaths = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new RuntimeException('Не удалось прочитать структуру ZIP-архива.');
                }

                $archivePath = (string)($stat['name'] ?? '');
                if ($this->shouldIgnoreArchiveEntry($archivePath)) {
                    continue;
                }

                $isDirectory = str_ends_with($archivePath, '/');
                $normalizedPath = $this->normalizeArchivePath($archivePath, $isDirectory);
                if ($normalizedPath === '') {
                    continue;
                }
                if (isset($seenPaths[$normalizedPath])) {
                    throw new RuntimeException('В архиве есть дублирующийся путь: ' . $normalizedPath);
                }
                $seenPaths[$normalizedPath] = true;

                if ($this->archiveEntryIsSymlink($zip, $index)) {
                    throw new RuntimeException('ZIP-архив содержит символическую ссылку: ' . $normalizedPath);
                }

                $destination = $targetDir . '/' . $normalizedPath;
                if ($isDirectory) {
                    if (!@mkdir($destination, 0775, true) && !is_dir($destination)) {
                        throw new RuntimeException('Не удалось создать каталог из архива.');
                    }
                    continue;
                }

                $parentDir = dirname($destination);
                if (!@mkdir($parentDir, 0775, true) && !is_dir($parentDir)) {
                    throw new RuntimeException('Не удалось создать каталог из архива.');
                }

                $input = $zip->getStream($archivePath);
                if ($input === false) {
                    throw new RuntimeException('Не удалось прочитать файл из архива: ' . $normalizedPath);
                }
                $output = @fopen($destination, 'wb');
                if ($output === false) {
                    fclose($input);
                    throw new RuntimeException('Не удалось записать файл из архива.');
                }

                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException('Ошибка чтения ZIP-архива.');
                        }
                        if ($chunk === '') {
                            continue;
                        }
                        $totalBytes += strlen($chunk);
                        if ($totalBytes > self::MAX_EXTRACTED_BYTES) {
                            throw new RuntimeException('Распакованный архив превышает лимит 512 МБ.');
                        }
                        $offset = 0;
                        $chunkLength = strlen($chunk);
                        while ($offset < $chunkLength) {
                            $written = fwrite($output, substr($chunk, $offset));
                            if ($written === false || $written === 0) {
                                throw new RuntimeException('Ошибка записи распакованного файла.');
                            }
                            $offset += $written;
                        }
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }

                $filesCount++;
                $extension = strtolower((string)pathinfo($normalizedPath, PATHINFO_EXTENSION));
                if (in_array($extension, ['html', 'htm'], true)) {
                    $htmlPaths[] = $normalizedPath;
                }
            }

            if ($filesCount === 0) {
                throw new RuntimeException('ZIP-архив не содержит файлов.');
            }
            if ($htmlPaths === []) {
                throw new RuntimeException('В ZIP-архиве не найден HTML-файл.');
            }

            return [
                'entryPath' => $this->selectEntryPath($htmlPaths),
                'filesCount' => $filesCount,
                'sizeBytes' => $totalBytes,
            ];
        } finally {
            $zip->close();
        }
    }

    private function selectEntryPath(array $htmlPaths): string
    {
        usort($htmlPaths, static function (string $left, string $right): int {
            $leftName = strtolower(basename($left));
            $rightName = strtolower(basename($right));
            $leftIndex = in_array($leftName, ['index.html', 'index.htm'], true) ? 0 : 1;
            $rightIndex = in_array($rightName, ['index.html', 'index.htm'], true) ? 0 : 1;

            return [$leftIndex, substr_count($left, '/'), strlen($left), $left]
                <=> [$rightIndex, substr_count($right, '/'), strlen($right), $right];
        });

        return $htmlPaths[0];
    }

    private function normalizeArchivePath(string $path, bool $isDirectory): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new RuntimeException('ZIP-архив содержит некорректный путь.');
        }
        if (preg_match('//u', $path) !== 1) {
            throw new RuntimeException('ZIP-архив содержит путь в неподдерживаемой кодировке.');
        }
        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path) === 1) {
            throw new RuntimeException('ZIP-архив содержит абсолютный путь.');
        }

        $path = $isDirectory ? rtrim($path, '/') : $path;
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('ZIP-архив содержит небезопасный путь.');
            }
        }

        return implode('/', $segments);
    }

    private function shouldIgnoreArchiveEntry(string $path): bool
    {
        $trimmedPath = trim(str_replace('\\', '/', $path), '/');
        if ($trimmedPath === '') {
            return true;
        }

        $segments = explode('/', $trimmedPath);
        return $segments[0] === '__MACOSX' || end($segments) === '.DS_Store';
    }

    private function archiveEntryIsSymlink(ZipArchive $zip, int $index): bool
    {
        $operationsSystem = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $operationsSystem, $attributes)) {
            return false;
        }

        $unixFileType = ($attributes >> 16) & 0xF000;
        return $unixFileType === 0xA000;
    }

    private function normalizeDisplayName(string $displayName, string $originalName): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $displayName) ?? '');
        if ($name === '') {
            $name = trim((string)pathinfo($originalName, PATHINFO_FILENAME));
        }
        if ($name === '') {
            $name = 'HTML tool';
        }

        return $this->truncate($name, 100);
    }

    private function safeOriginalName(string $originalName): string
    {
        $name = basename(str_replace('\\', '/', $originalName));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        return $this->truncate($name !== '' ? $name : 'upload', 200);
    }

    private function makeId(string $name): string
    {
        $asciiName = function_exists('iconv')
            ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)
            : $name;
        $slug = strtolower($asciiName);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = substr($slug !== '' ? $slug : 'tool', 0, 50);

        return $slug . '-' . bin2hex(random_bytes(4));
    }

    private function writeManifest(array $manifest): void
    {
        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Не удалось сформировать метаданные инструмента.');
        }

        $manifestPath = $this->manifestsDir . '/' . $manifest['id'] . '.json';
        $temporaryPath = $manifestPath . '.tmp-' . bin2hex(random_bytes(5));
        if (@file_put_contents($temporaryPath, $encoded, LOCK_EX) === false || !@rename($temporaryPath, $manifestPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Не удалось сохранить метаданные инструмента.');
        }
    }

    private function ensureStorageDirectories(): void
    {
        foreach ([$this->storageDir, $this->appsDir, $this->manifestsDir] as $directory) {
            if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Каталог инструментов недоступен для записи: ' . $directory);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $removed = $item->isDir() && !$item->isLink() ? @rmdir($path) : @unlink($path);
            if (!$removed) {
                throw new RuntimeException('Не удалось удалить файл инструмента: ' . $path);
            }
        }

        if (!@rmdir($directory)) {
            throw new RuntimeException('Не удалось удалить каталог инструмента.');
        }
    }

    private function buildPublicUrl(string $id, string $entryPath): string
    {
        $segments = array_merge([$id], explode('/', $entryPath));
        return '/tools/hosted/' . implode('/', array_map('rawurlencode', $segments));
    }

    private function isValidId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $id) === 1;
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        if (preg_match('//u', $value) !== 1) {
            $value = function_exists('iconv')
                ? (string)@iconv('UTF-8', 'UTF-8//IGNORE', $value)
                : (preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');
        }

        preg_match_all('/./us', $value, $characters);
        return implode('', array_slice($characters[0] ?? [], 0, $length));
    }
}

<?php

namespace App\Support;

class PathGuard
{
    /**
     * Normalizes a relative path (from user input) into safe segments.
     * Keeps behavior compatible with existing code by stripping common traversal substrings.
     */
    public static function toSegmentsAllowEmpty(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [];
        }

        $cleanPath = trim($path, '/');
        $cleanPath = str_replace(["../", ".\\", "..\\"], '', $cleanPath);
        $cleanPath = str_replace("\0", '', $cleanPath);

        $segments = array_values(array_filter(
            explode('/', str_replace('\\', '/', $cleanPath)),
            static fn($s) => $s !== ''
        ));

        foreach ($segments as $seg) {
            if ($seg === '.' || $seg === '..') {
                throw new \InvalidArgumentException('Invalid path');
            }
        }

        return $segments;
    }

    public static function toSegments(string $path): array
    {
        $segments = self::toSegmentsAllowEmpty($path);
        if (count($segments) === 0) {
            throw new \InvalidArgumentException('Path is required');
        }
        return $segments;
    }

    public static function joinCatalog(string $catalogPath, array $segments): string
    {
        $catalogPath = rtrim($catalogPath, '/');
        if (count($segments) === 0) {
            return $catalogPath;
        }
        return $catalogPath . '/' . implode('/', $segments);
    }

    public static function segmentsToRelativePath(array $segments): string
    {
        return implode('/', $segments);
    }
}

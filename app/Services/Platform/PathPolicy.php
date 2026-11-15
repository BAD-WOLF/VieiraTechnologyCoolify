<?php

namespace App\Services\Platform;

use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * Host path confinement: tenant bind mounts and files written on the shared nodes
 * must stay inside the configuration directory of the resource that owns them.
 */
class PathPolicy
{
    /**
     * Resolve "." and ".." segments of an absolute path without touching the filesystem.
     * Returns null for relative paths, paths with interpolation, control characters or quotes.
     */
    public static function normalize(?string $path): ?string
    {
        if (is_null($path) || $path === '' || ! str_starts_with($path, '/')) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F$`\'"\\\\;|&<>*?{}\[\]]/', $path)) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($parts) === 0) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    /**
     * Resolve a path that may be relative to a base directory (e.g. "./data" in a compose file).
     */
    public static function resolve(?string $path, string $baseDirectory): ?string
    {
        if (is_null($path) || $path === '') {
            return null;
        }
        if (str_starts_with($path, '~')) {
            return null;
        }
        if (! str_starts_with($path, '/')) {
            $path = rtrim($baseDirectory, '/').'/'.$path;
        }

        return self::normalize($path);
    }

    /**
     * @param  array<int, string>  $roots
     */
    public static function isWithin(?string $path, array $roots): bool
    {
        $normalized = self::normalize($path);
        if (is_null($normalized)) {
            return false;
        }
        foreach ($roots as $root) {
            $root = self::normalize($root);
            if (is_null($root) || $root === '/') {
                continue;
            }
            if ($normalized === $root || str_starts_with($normalized, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Host directories a resource may read/write on the node.
     *
     * @return array<int, string>
     */
    public static function rootsFor(?Model $resource): array
    {
        if (is_null($resource)) {
            return [];
        }
        if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            $resource = $resource->service ?? Service::find($resource->service_id);
            if (is_null($resource)) {
                return [];
            }
        }
        $uuid = data_get($resource, 'uuid');
        if (blank($uuid)) {
            return [];
        }
        $directory = match (true) {
            $resource instanceof Application => application_configuration_dir(),
            $resource instanceof Service => service_configuration_dir(),
            default => database_configuration_dir(),
        };
        $roots = ["{$directory}/{$uuid}"];
        if (isDev()) {
            $devDirectory = str($directory)->replace(base_configuration_dir(), '/var/lib/docker/volumes/coolify_dev_coolify_data/_data')->value();
            $roots[] = "{$devDirectory}/{$uuid}";
        }

        return $roots;
    }

    /**
     * Safe file name (no directories, no shell metacharacters).
     */
    public static function isSafeFilename(?string $filename): bool
    {
        if (blank($filename) || $filename === '.' || $filename === '..') {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,200}$/', $filename);
    }

    /**
     * Safe path inside a container or a repository (relative or absolute, no traversal, no shell metacharacters).
     */
    public static function isSafeInnerPath(?string $path, bool $allowEmpty = true): bool
    {
        if (blank($path)) {
            return $allowEmpty;
        }
        if (! preg_match('#^[A-Za-z0-9._\-/@+]+$#', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return ! str_starts_with($path, '-');
    }
}

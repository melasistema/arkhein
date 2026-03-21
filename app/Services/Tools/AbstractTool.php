<?php

namespace App\Services\Tools;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;

abstract class AbstractTool implements ToolInterface
{
    /**
     * Resolve and sandbox a path within the managed folder.
     */
    protected function resolvePath(string $path, ?ManagedFolder $folder): string
    {
        $basePath = $folder ? $folder->path : null;

        if (!$basePath) {
            return $path;
        }

        // If the path is already absolute and contains the base path, just return it.
        if (str_starts_with($path, $basePath)) {
            return $path;
        }

        // Otherwise, strip leading slashes and append to base path.
        $path = ltrim($path, DIRECTORY_SEPARATOR . ' ');
        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Clean a path for human-friendly display (relative to sandbox).
     */
    protected function cleanPath(string $path): string
    {
        return ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Last-resort recursive search for a filename within the sandbox.
     */
    protected function findActualSource(string $filename, ?ManagedFolder $folder): ?string
    {
        if (!$folder) return null;
        
        $target = basename($filename);
        $files = File::allFiles($folder->path);
        foreach ($files as $file) {
            if ($file->getFilename() === $target) {
                return $file->getRealPath();
            }
        }
        return null;
    }
}

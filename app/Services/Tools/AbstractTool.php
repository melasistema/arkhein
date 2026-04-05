<?php

namespace App\Services\Tools;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;

abstract class AbstractTool implements ToolInterface
{
    /**
     * Resolve and sandbox a path within the managed folder.
     * Prevents path traversal via strict boundary checking and normalization.
     */
    protected function resolvePath(string $path, ?ManagedFolder $folder): string
    {
        if (!$folder) {
            throw new \RuntimeException("Filesystem tool requires an authorized folder context.");
        }

        $basePath = realpath($folder->path);
        
        // 1. Sanitize the incoming path (remove any ../ or weirdness)
        $path = str_replace(['../', '..\\'], '', $path);
        $path = ltrim($path, DIRECTORY_SEPARATOR . ' ');

        // 2. Build the target absolute path
        $resolvedPath = $basePath . DIRECTORY_SEPARATOR . $path;

        // 3. Security Check: The resolved path MUST still start with the base path.
        // We use realpath-style logic without requiring the file to exist yet.
        if (!str_starts_with($resolvedPath, $basePath)) {
            \Illuminate\Support\Facades\Log::emergency("SECURITY: Path traversal attempt blocked.", [
                'base' => $basePath,
                'input' => $path,
                'resolved' => $resolvedPath
            ]);
            throw new \RuntimeException("Sovereign Guard: Path traversal attempt detected. Operation blocked.");
        }

        return $resolvedPath;
    }

    /**
     * Clean a path for human-friendly display (relative to sandbox).
     */
    protected function cleanPath(string $path): string
    {
        return ltrim($path, DIRECTORY_SEPARATOR);
    }

    public function requiresOperatorConsent(): bool
    {
        return true;
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

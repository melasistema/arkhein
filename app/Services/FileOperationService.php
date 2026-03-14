<?php

namespace App\Services;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileOperationService
{
    /**
     * Resolve mentions like @folder-name into actual absolute paths.
     */
    public function resolvePath(string $path): string
    {
        if (str_starts_with($path, '@')) {
            $parts = explode(DIRECTORY_SEPARATOR, $path, 2);
            $folderName = ltrim($parts[0], '@');
            $subPath = $parts[1] ?? '';

            $folder = ManagedFolder::where('name', $folderName)->first();
            if ($folder) {
                return rtrim($folder->path, DIRECTORY_SEPARATOR) . ($subPath ? DIRECTORY_SEPARATOR . $subPath : '');
            }
        }

        return $path;
    }

    /**
     * Verify if a path is within authorized folders.
     */
    protected function isAuthorized(string $path): bool
    {
        $resolvedPath = $this->resolvePath($path);
        $realPath = realpath($resolvedPath) ?: $resolvedPath;
        $authorizedFolders = ManagedFolder::all();

        foreach ($authorizedFolders as $folder) {
            if (str_starts_with($realPath, $folder->path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new file with content.
     */
    public function createFile(string $path, string $content): array
    {
        $resolvedPath = $this->resolvePath($path);
        if (!$this->isAuthorized(dirname($resolvedPath))) {
            return ['success' => false, 'error' => 'Path not authorized.'];
        }

        try {
            File::put($resolvedPath, $content);
            return ['success' => true, 'path' => $resolvedPath];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Organize a folder by file extensions.
     */
    public function organizeFolder(string $folderPath): array
    {
        $resolvedPath = $this->resolvePath($folderPath);
        if (!$this->isAuthorized($resolvedPath)) {
            return ['success' => false, 'error' => 'Folder not authorized.'];
        }

        try {
            $files = File::files($resolvedPath);
            $movedCount = 0;

            foreach ($files as $file) {
                $extension = strtolower($file->getExtension());
                if (empty($extension)) continue;

                $targetDir = $resolvedPath . DIRECTORY_SEPARATOR . $extension . 's';

                if (!File::isDirectory($targetDir)) {
                    File::makeDirectory($targetDir, 0755, true);
                }

                $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file->getFilename();
                File::move($file->getRealPath(), $targetPath);
                $movedCount++;
            }

            return ['success' => true, 'moved_count' => $movedCount];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Move files from one location to another.
     */
    public function moveFiles(string $from, string $to): array
    {
        $resolvedFrom = $this->resolvePath($from);
        $resolvedTo = $this->resolvePath($to);

        if (!$this->isAuthorized($resolvedFrom) || !$this->isAuthorized($resolvedTo)) {
            return ['success' => false, 'error' => 'Path not authorized.'];
        }

        try {
            // Ensure target directory exists
            if (!File::isDirectory($resolvedTo)) {
                File::makeDirectory($resolvedTo, 0755, true);
            }

            if (File::isDirectory($resolvedFrom)) {
                // If moving a directory into another, we actually mean "move contents"
                // to avoid recursive loops or unexpected nesting.
                $files = File::allFiles($resolvedFrom, true); // true for hidden files
                foreach ($files as $file) {
                    $targetPath = $resolvedTo . DIRECTORY_SEPARATOR . $file->getFilename();
                    
                    // Don't move if source is same as target (prevent infinite loops)
                    if ($file->getRealPath() === realpath($targetPath)) continue;
                    
                    File::move($file->getRealPath(), $targetPath);
                }
            } else {
                // Single file move
                $targetPath = File::isDirectory($resolvedTo) 
                    ? $resolvedTo . DIRECTORY_SEPARATOR . basename($resolvedFrom)
                    : $resolvedTo;
                
                File::move($resolvedFrom, $targetPath);
            }
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a folder and all its contents.
     */
    public function deleteFolder(string $path): array
    {
        $resolvedPath = $this->resolvePath($path);
        if (!$this->isAuthorized($resolvedPath)) {
            return ['success' => false, 'error' => 'Path not authorized.'];
        }

        try {
            File::deleteDirectory($resolvedPath);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a specific file.
     */
    public function deleteFile(string $path): array
    {
        $resolvedPath = $this->resolvePath($path);
        if (!$this->isAuthorized($resolvedPath)) {
            return ['success' => false, 'error' => 'Path not authorized.'];
        }

        try {
            File::delete($resolvedPath);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List files in authorized folders for LLM context.
     * Non-recursive for performance.
     */
    public function listAuthorizedFiles(): array
    {
        $folders = ManagedFolder::all();
        $fileList = [];
        $ignore = ['.git', 'node_modules', 'vendor', 'storage', 'build', 'dist'];

        foreach ($folders as $folder) {
            if (File::isDirectory($folder->path)) {
                // Only list top-level files for suggestions to keep it snappy
                $files = File::files($folder->path);
                foreach ($files as $file) {
                    $fileList[] = [
                        'name' => $file->getFilename(),
                        'path' => $file->getRealPath(),
                        'size' => $file->getSize(),
                    ];

                    if (count($fileList) > 500) break 2;
                }
            }
        }

        return $fileList;
    }
}

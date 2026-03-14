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
        
        // Normalize: use realpath if it exists, otherwise use the raw resolved path
        $checkPath = realpath($resolvedPath) ?: $resolvedPath;
        $checkPath = rtrim($checkPath, DIRECTORY_SEPARATOR);
        
        $authorizedFolders = ManagedFolder::all();

        foreach ($authorizedFolders as $folder) {
            $authorizedRoot = realpath($folder->path) ?: $folder->path;
            $authorizedRoot = rtrim($authorizedRoot, DIRECTORY_SEPARATOR);
            
            // Success if exact match OR if checkPath is a subpath (starts with root + slash)
            if ($checkPath === $authorizedRoot || 
                str_starts_with($checkPath, $authorizedRoot . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new directory.
     */
    public function createFolder(string $path): array
    {
        $resolvedPath = $this->resolvePath($path);
        if (!$this->isAuthorized($resolvedPath)) {
            return ['success' => false, 'error' => 'Path not authorized.'];
        }

        try {
            if (!File::isDirectory($resolvedPath)) {
                File::makeDirectory($resolvedPath, 0755, true);
            }
            return ['success' => true, 'path' => $resolvedPath];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
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

    public function getRegistrySummary(): string
    {
        $folders = ManagedFolder::all();
        if ($folders->isEmpty()) return "No authorized folders.";

        $summary = "AUTHORIZED ROOT FOLDERS:\n";
        foreach ($folders as $folder) {
            $summary .= "- @{$folder->name} -> {$folder->path}\n";
        }
        return $summary;
    }

    public function listAuthorizedFiles(): array
    {
        $folders = ManagedFolder::all();
        $fileList = [];
        $ignore = ['.git', 'node_modules', 'vendor', 'storage', 'build', 'dist'];

        foreach ($folders as $folder) {
            if (!File::isDirectory($folder->path)) continue;

            $fileList[] = [
                'name' => "@{$folder->name}",
                'path' => $folder->path,
                'type' => 'directory'
            ];

            // 2-Level deep scan for context
            $this->scanDirectory($folder->path, "@{$folder->name}", $fileList, $ignore, 1);
        }

        return $fileList;
    }

    protected function scanDirectory(string $fullPath, string $mentionPath, array &$fileList, array $ignore, int $depth): void
    {
        // Limit depth and total items to prevent context explosion and timeout
        if ($depth > 2 || count($fileList) > 200) return;

        try {
            $items = File::directories($fullPath);
            foreach ($items as $dir) {
                $name = basename($dir);
                if (in_array($name, $ignore)) continue;

                $newMention = $mentionPath . '/' . $name;
                $fileList[] = [
                    'name' => $newMention,
                    'path' => $dir,
                    'type' => 'directory'
                ];
                
                $this->scanDirectory($dir, $newMention, $fileList, $ignore, $depth + 1);
            }

            $files = File::files($fullPath);
            foreach ($files as $file) {
                if (count($fileList) > 200) break;

                $fileList[] = [
                    'name' => $mentionPath . '/' . $file->getFilename(),
                    'path' => $file->getRealPath(),
                    'type' => 'file'
                ];
            }
        } catch (\Exception $e) {
            Log::error("Failed to scan directory $fullPath: " . $e->getMessage());
        }
    }
}

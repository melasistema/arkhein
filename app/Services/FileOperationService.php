<?php

namespace App\Services;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileOperationService
{
    /**
     * Verify if a path is within authorized folders.
     */
    protected function isAuthorized(string $path): bool
    {
        $realPath = realpath($path) ?: $path;
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
        if (!$this->isAuthorized(dirname($path))) {
            return ['success' => false, 'error' => 'Path not authorized.'];
        }

        try {
            File::put($path, $content);
            return ['success' => true, 'path' => $path];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Organize a folder by file extensions.
     */
    public function organizeFolder(string $folderPath): array
    {
        if (!$this->isAuthorized($folderPath)) {
            return ['success' => false, 'error' => 'Folder not authorized.'];
        }

        try {
            $files = File::files($folderPath);
            $movedCount = 0;

            foreach ($files as $file) {
                $extension = strtolower($file->getExtension());
                if (empty($extension)) continue;

                $targetDir = $folderPath . DIRECTORY_SEPARATOR . $extension . 's';
                
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
     * List files in authorized folders for LLM context.
     */
    public function listAuthorizedFiles(): array
    {
        $folders = ManagedFolder::all();
        $fileList = [];

        foreach ($folders as $folder) {
            if (File::isDirectory($folder->path)) {
                $files = File::allFiles($folder->path);
                foreach ($files as $file) {
                    $fileList[] = [
                        'name' => $file->getFilename(),
                        'path' => $file->getRealPath(),
                        'size' => $file->getSize(),
                    ];
                }
            }
        }

        return $fileList;
    }
}

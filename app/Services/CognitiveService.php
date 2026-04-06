<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CognitiveService
{
    /**
     * Unified logging for the "Chain of Thought" (CoT) system.
     * 
     * @param string $type 'workflow' (ingestion) or 'workspace' (reasoning/chat)
     * @param string $id Folder ID or a unique identifier
     * @param string $filename The name of the file (e.g., "patients.md.ingestion.md")
     * @param string $content The Markdown content to persist
     */
    public function persistCoT(string $type, string $id, string $filename, string $content): void
    {
        $subDir = ($type === 'workflow') ? 'workflows' : 'workspaces';
        $dir = storage_path("app/arkhein/{$subDir}/{$id}");
        
        File::ensureDirectoryExists($dir, 0777);
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        // Apply safety limits
        $content = $this->enforceSizeLimit($content);

        File::put($path, $content);
        
        Log::debug("Arkhein Laboratory: CoT Persisted -> {$path}");
    }

    /**
     * Read an existing scratchpad from the laboratory.
     */
    public function readCoT(string $type, string $id, string $filename): ?string
    {
        $subDir = ($type === 'workflow') ? 'workflows' : 'workspaces';
        $path = storage_path("app/arkhein/{$subDir}/{$id}/{$filename}");

        if (File::exists($path)) {
            return File::get($path);
        }

        return null;
    }

    /**
     * Enforce a cleanup of old CoT files to prevent disk bloat.
     */
    public function cleanupLaboratory(int $maxAgeDays = 7): int
    {
        $purged = 0;
        $baseDir = storage_path('app/arkhein');

        if (!File::isDirectory($baseDir)) return 0;

        $types = ['workflows', 'workspaces'];
        foreach ($types as $type) {
            $dir = $baseDir . DIRECTORY_SEPARATOR . $type;
            if (!File::isDirectory($dir)) continue;

            $folders = File::directories($dir);
            foreach ($folders as $folder) {
                $files = File::files($folder);
                foreach ($files as $file) {
                    if ($file->getMTime() < (time() - ($maxAgeDays * 86400))) {
                        File::delete($file->getPathname());
                        $purged++;
                    }
                }

                // If folder is empty, remove it too
                if (empty(File::files($folder)) && empty(File::directories($folder))) {
                    File::deleteDirectory($folder);
                }
            }
        }

        Log::info("Arkhein Laboratory: Purged {$purged} stale Chain of Thought files.");
        return $purged;
    }

    /**
     * Truncate content if it exceeds safety limits for a single scratchpad.
     */
    protected function enforceSizeLimit(string $content): string
    {
        $limit = config('arkhein.protocols.max_scratchpad_size', 524288); // 512KB default
        if (strlen($content) > $limit) {
            return mb_substr($content, 0, $limit) . "\n\n... [TRUNCATED DUE TO SIZE LIMIT] ...";
        }
        return $content;
    }
}

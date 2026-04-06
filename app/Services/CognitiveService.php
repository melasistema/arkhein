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

        // If file exists, we append or prepend based on logic? 
        // For now, overwrite is standard for single-pass, but for scratchpads we might want to evolve.
        // We'll stick to a "Snapshot" approach for now.
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
}

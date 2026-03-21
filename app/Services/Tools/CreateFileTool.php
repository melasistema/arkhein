<?php

namespace App\Services\Tools;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CreateFileTool extends AbstractTool
{
    public function getName(): string { return 'create_file'; }

    public function getDescription(): string 
    { 
        return 'Create a new file with specific content. Use relative paths.'; 
    }

    public function getSchema(): array 
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative path including filename'],
                'content' => ['type' => 'string', 'description' => 'Full text content of the file']
            ],
            'required' => ['path', 'content']
        ];
    }

    public function execute(array $params, ?ManagedFolder $folder = null): array
    {
        $path = $params['path'];

        // Extension Safeguard
        $info = pathinfo($path);
        if (empty($info['extension'])) {
            $path .= '.md';
        } elseif (strtolower($info['extension']) === 'pdf') {
            // Prevent hallucinated PDF creation from text content
            $path = preg_replace('/\.pdf$/i', '.md', $path);
        }

        $resolvedPath = $this->resolvePath($path, $folder);
        
        Log::info("Tool: create_file -> {$resolvedPath}");
        
        try {
            File::ensureDirectoryExists(dirname($resolvedPath), 0777);
            $success = File::put($resolvedPath, $params['content']) !== false;
            
            if ($success) {
                @chmod($resolvedPath, 0666);

                // Immediate Indexing for RAG availability
                if ($folder) {
                    app(\App\Services\ArchiveService::class)->indexFile($folder, $resolvedPath);
                }

                return ['success' => true];
            }
            
            return ['success' => false, 'error' => 'Disk write failed. Check permissions.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function describeAction(array $params): string
    {
        $path = $params['path'] ?? 'unknown';
        $info = pathinfo($path);
        if (empty($info['extension'])) {
            $path .= '.md';
        } elseif (strtolower($info['extension']) === 'pdf') {
            $path = preg_replace('/\.pdf$/i', '.md', $path);
        }

        return "Create file: " . $this->cleanPath($path);
    }
}

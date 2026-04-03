<?php

namespace App\Services;

namespace App\Services\Tools;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class MoveFileTool extends AbstractTool
{
    public function getName(): string { return 'move_file'; }

    public function getDescription(): string 
    { 
        return 'Move or rename a file. Handles nested directories.'; 
    }

    public function getSchema(): array 
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Current relative path'],
                'to' => ['type' => 'string', 'description' => 'Target relative path including filename']
            ],
            'required' => ['from', 'to']
        ];
    }

    public function execute(array $params, ?ManagedFolder $folder = null): array
    {
        $from = $this->resolvePath($params['from'], $folder);
        $to = $this->resolvePath($params['to'], $folder);
        
        Log::info("Tool: move_file -> {$from} to {$to}");

        if (!File::exists($from)) {
            return ['success' => false, 'error' => "Source file not found."];
        }

        if ($from === $to) return ['success' => true];

        try {
            File::ensureDirectoryExists(dirname($to), 0777);
            
            // Try atomic move
            if (File::move($from, $to)) {
                if ($folder) {
                    // 1. Remove old document and fragments from index
                    $oldRelativePath = str_replace($folder->path . DIRECTORY_SEPARATOR, '', $from);
                    \App\Models\Document::where('folder_id', $folder->id)
                        ->where('path', $oldRelativePath)
                        ->delete();
                    
                    // 2. Index new path (re-syncs fragments)
                    app(\App\Services\ArchiveService::class)->indexFile($folder, $to);
                }

                return ['success' => true];
            }

            return ['success' => false, 'error' => "Access denied during move. Check macOS permissions."];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function describeAction(array $params): string
    {
        return "Move: " . basename($params['from'] ?? 'file') . " -> " . $this->cleanPath($params['to'] ?? 'dest');
    }
}

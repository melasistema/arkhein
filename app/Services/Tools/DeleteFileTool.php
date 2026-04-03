<?php

namespace App\Services\Tools;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DeleteFileTool extends AbstractTool
{
    public function getName(): string { return 'delete_file'; }

    public function getDescription(): string 
    { 
        return 'Remove a file from the sandbox. Use with caution.'; 
    }

    public function getSchema(): array 
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative path of the file to delete']
            ],
            'required' => ['path']
        ];
    }

    public function execute(array $params, ?ManagedFolder $folder = null): array
    {
        $path = $this->resolvePath($params['path'] ?? $params['from'] ?? '', $folder);
        
        Log::info("Tool: delete_file -> {$path}");

        if (!File::exists($path)) {
            $this->purgeKnowledge($path, $folder);
            return ['success' => true];
        }

        try {
            $success = File::delete($path);
            if ($success) {
                $this->purgeKnowledge($path, $folder);
            }
            return ['success' => $success];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function purgeKnowledge(string $absolutePath, ?ManagedFolder $folder)
    {
        if (!$folder) return;

        $relativePath = str_replace($folder->path . DIRECTORY_SEPARATOR, '', $absolutePath);
        \App\Models\Document::where('folder_id', $folder->id)
            ->where('path', $relativePath)
            ->delete();
    }

    public function describeAction(array $params): string
    {
        return "Delete: " . $this->cleanPath($params['path'] ?? $params['from'] ?? 'file');
    }
}

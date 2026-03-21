<?php

namespace App\Services\Tools;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CreateFolderTool extends AbstractTool
{
    public function getName(): string { return 'create_folder'; }

    public function getDescription(): string 
    { 
        return 'Create a new directory. Recursively creates parents.'; 
    }

    public function getSchema(): array 
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative path of the folder to create']
            ],
            'required' => ['path']
        ];
    }

    public function execute(array $params, ?ManagedFolder $folder = null): array
    {
        $path = $this->resolvePath($params['path'], $folder);
        
        Log::info("Tool: create_folder -> {$path}");

        if (File::isDirectory($path)) return ['success' => true];

        try {
            $success = File::makeDirectory($path, 0777, true, true);
            return ['success' => $success];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function describeAction(array $params): string
    {
        return "Create directory: " . $this->cleanPath($params['path'] ?? 'unknown');
    }
}

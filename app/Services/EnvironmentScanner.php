<?php

namespace App\Services;

use App\Models\ManagedFolder;
use App\Models\Document;
use Illuminate\Support\Facades\Log;

class EnvironmentScanner
{
    public function __construct(protected OllamaService $ollama) {}

    /**
     * Scan a silo to generate an 'Environmental Schema' (Level 0 Grounding).
     */
    public function scan(ManagedFolder $folder): void
    {
        Log::info("Arkhein Scanner: Analyzing environment for @{$folder->name}");

        $docs = Document::where('folder_id', $folder->id)->pluck('path')->all();
        if (empty($docs)) return;

        // 1. Build a Structural Folder Map (Flat and explicit)
        $folderMap = $this->generateFolderMap($docs);

        // 2. Sample filenames for pattern detection
        $filesList = "- " . implode("\n- ", array_slice($docs, 0, 50));

        $prompt = "You are the Arkhein Environmental Scanner. 
        TASK: Analyze the organizational pattern of this silo.
        
        FOLDER HIERARCHY:
        " . implode("\n", $folderMap) . "

        SAMPLE FILENAMES:
        {$filesList}

        Respond ONLY with a JSON object:
        {
          \"purpose\": \"string\",
          \"pattern\": \"string\",
          \"entities\": [\"string\"],
          \"structure_summary\": \"brief description of the folder hierarchy\"
        }";

        try {
            $response = $this->ollama->generate($prompt, null, [
                'format' => 'json',
                'options' => ['temperature' => 0]
            ]);

            $schema = json_decode($response, true);
            if ($schema) {
                $schema['folder_map'] = $folderMap;
                $schema['total_files'] = count($docs);
                
                $folder->update(['environmental_schema' => $schema]);
                Log::info("Arkhein Scanner: Environment grounded for @{$folder->name}", $schema);
            }
        } catch (\Exception $e) {
            Log::error("Arkhein Scanner: Failed to scan folder @{$folder->name}: " . $e->getMessage());
        }
    }

    protected function generateFolderMap(array $paths): array
    {
        $map = [];
        foreach ($paths as $path) {
            $dir = dirname($path);
            if ($dir === '.') $dir = '/ (root)';
            
            $map[$dir] = ($map[$dir] ?? 0) + 1;
        }

        $result = [];
        foreach ($map as $dir => $count) {
            $result[] = "Folder: [{$dir}] contains {$count} files";
        }
        
        sort($result);
        return $result;
    }
}

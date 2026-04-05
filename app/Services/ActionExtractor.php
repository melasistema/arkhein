<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ActionExtractor
{
    public function __construct(
        protected OllamaService $ollama,
        protected ActionService $actionService
    ) {}
/**
 * The "High-Resolution Tool Worker" pass.
 */
public function extract(string $query, ?string $folderPath, array $currentFiles, ?string $context = null, array $perception = [], array $schema = []): array
{
    // 1. Specialized Case: Strategic Organization
    if (str_contains(strtolower($query), '/organize')) {
        return $this->extractOrganizationPlan($folderPath, $currentFiles);
    }

    // 2. WINDOWED HINTING: Only send relevant files to the SLM
    $relevantFiles = $this->getRelevantHints($query, $currentFiles, $perception, $schema);
    $filesList = "- " . implode("\n- ", $relevantFiles);
...
protected function getRelevantHints(string $query, array $files, array $perception, array $schema): array
{
    // If there are few files, send them all
    if (count($files) <= 20) return $files;

    $hints = collect();
    $queryLower = strtolower($query);

    // 1. Lexical matching (Filenames)
    foreach ($files as $file) {
        $base = strtolower(basename($file));
        if (str_contains($queryLower, $base) || str_contains($base, $queryLower)) {
            $hints->push($file);
        }
    }

    // 2. Schema-aware matching (Entities)
    foreach ($perception['entities'] ?? [] as $entity) {
        $e = strtolower($entity);
        foreach ($files as $file) {
            if (str_contains(strtolower($file), $e)) {
                $hints->push($file);
            }
        }
    }

    // 3. Fallback: Structural context (Top level)
    if ($hints->count() < 10) {
        foreach ($files as $file) {
            if (!str_contains($file, DIRECTORY_SEPARATOR)) {
                $hints->push($file);
            }
        }
    }

    return $hints->unique()->take(30)->values()->all();
}
        $tools = json_encode($this->actionService->getToolDefinitions(), JSON_PRETTY_PRINT);
        
        $system = "You are a File System Tool Worker.
        Your task is to map User Requests to a strict JSON action list based on the available TOOLS.
        
        AVAILABLE TOOLS:
        {$tools}

        OUTPUT FORMAT:
        {
          \"reasoning\": \"brief explanation of why these tools are chosen\",
          \"actions\": [
            { \"type\": \"tool_name\", \"params\": { ... } }
          ]
        }

        CRITICAL SECURITY RULES:
        1. NEVER use wildcards (*) in 'move_file' or 'delete_file' parameters.
        2. NEVER move or delete source files when the user asks to '/create' a summary, list, or report.
        3. For '/create' commands, use ONLY the 'create_file' tool.
        4. Use RELATIVE PATHS ONLY.
        5. Deep Creation: If the user provides a specific prompt for the file content (e.g., '/create file.md with a list of features'), include that prompt in a parameter called 'instruction'.
        6. Use 'PLACEHOLDER' for any content parameters if you are creating files.
        7. Output ONLY the JSON object. No other text.";

        $example = "Example Mapping:\nRequest: '/create report.md with all interview findings'\nResponse: {\"reasoning\": \"Generating a detailed report based on interview knowledge\", \"actions\": [{\"type\":\"create_file\", \"params\":{\"path\":\"report.md\", \"content\":\"PLACEHOLDER\", \"instruction\": \"all interview findings\"}}]}";

        $user = "CURRENT FILES IN FOLDER:\n{$filesList}\n\n";
        if ($context) {
            $user .= "CONTEXT OF CONVERSATION:\n{$context}\n\n";
        }
        $user .= "USER REQUEST: \"{$query}\"\n\n{$example}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user]
        ];

        try {
            $response = $this->ollama->chat($messages, null, [
                'format' => 'json',
                'options' => [
                    'temperature' => 0,
                    'num_predict' => 1500
                ]
            ]);

            Log::info("Arkhein ToolWorker: Raw JSON Response", ['response' => $response]);

            $data = json_decode($response, true);
            
            if (!isset($data['actions']) || !is_array($data['actions'])) {
                Log::warning("Arkhein ToolWorker: No actions generated.");
                return ['actions' => [], 'reasoning' => 'No actions identified.'];
            }

            return [
                'actions' => $this->normalizeActions($data['actions'], $folderPath, $currentFiles),
                'reasoning' => $data['reasoning'] ?? 'Applying file system operations based on your request.'
            ];
        } catch (\Throwable $e) {
            Log::error("Arkhein ToolWorker: Exception", ['msg' => $e->getMessage()]);
            return ['actions' => [], 'reasoning' => 'An error occurred during extraction.'];
        }
    }

    protected function extractOrganizationPlan(?string $folderPath, array $currentFiles): array
    {
        Log::info("Arkhein Librarian: Generating strategic organization plan.");

        // 1. Taxonomy Generation pass
        $folder = \App\Models\ManagedFolder::where('path', $folderPath)->first();
        $schema = $folder?->environmental_schema ? json_encode($folder->environmental_schema) : "Generic files";

        $taxPrompt = "You are the Arkhein Librarian. Analyze the Silo Schema and suggest 4-6 professional subfolder names to organize these files.
        SCHEMA: {$schema}
        Output ONLY a JSON array of folder names.";
        
        $taxRes = $this->ollama->generate($taxPrompt, null, ['format' => 'json']);
        $taxonomy = json_decode($taxRes, true) ?? ['Documents', 'Data', 'Archive'];

        // 2. Build the plan by classification
        $filesList = "- " . implode("\n- ", array_slice($currentFiles, 0, 50));
        
        $system = "You are a File System Librarian.
        TASK: Map files to the provided TAXONOMY.
        
        TAXONOMY: " . implode(', ', $taxonomy) . "

        RULES:
        1. Output ONLY JSON.
        2. Use 'move_file' tool.
        3. 'to' path must be 'FOLDER/FILENAME'.";

        $user = "FILES TO ORGANIZE:\n{$filesList}";

        $response = $this->ollama->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user]
        ], null, ['format' => 'json']);

        $data = json_decode($response, true);
        $actions = $data['actions'] ?? $data ?? [];

        return [
            'actions' => $this->normalizeActions($actions, $folderPath, $currentFiles),
            'reasoning' => "I've designed a strategic taxonomy (" . implode(', ', $taxonomy) . ") and mapped your files to their logical homes."
        ];
    }

    protected function normalizeActions(array $actions, ?string $folderPath, array $currentFiles = []): array
    {
        $normalized = [];
        $actionService = app(ActionService::class);
        $createdFolders = [];

        foreach ($actions as $action) {
            if (!isset($action['type'], $action['params'])) continue;

            $type = $action['type'];
            $params = $action['params'];

            // Sandbox Normalization: Keep paths relative
            foreach (['path', 'from', 'to'] as $key) {
                if (isset($params[$key]) && !empty($params[$key])) {
                    $p = $params[$key];
                    if ($folderPath && str_contains($p, $folderPath)) {
                        $p = str_replace($folderPath, '', $p);
                    }
                    $params[$key] = ltrim($p, DIRECTORY_SEPARATOR . " ");
                }
            }

            // Disk-Truth Matching for 'from' (Source files MUST exist)
            if (in_array($type, ['move_file', 'delete_file']) && isset($params['from']) && !empty($currentFiles)) {
                $bestMatch = $this->findBestMatch($params['from'], $currentFiles);
                if ($bestMatch) {
                    $params['from'] = $bestMatch;
                }
            }
            // Sometimes the LLM uses 'path' for the source of a delete
            if ($type === 'delete_file' && isset($params['path']) && !empty($currentFiles)) {
                $bestMatch = $this->findBestMatch($params['path'], $currentFiles);
                if ($bestMatch) {
                    $params['path'] = $bestMatch;
                }
            }

            // Deduplicate Folder Creation
            if ($type === 'create_folder') {
                $folderName = $params['path'] ?? null;
                if (!$folderName || in_array($folderName, $createdFolders)) continue;
                $createdFolders[] = $folderName;
            }

            // Skip Identity Moves (e.g., file.pdf -> file.pdf)
            if ($type === 'move_file') {
                $from = $params['from'] ?? '';
                $to = $params['to'] ?? '';
                if ($from === $to || ltrim($to, DIRECTORY_SEPARATOR) === ltrim($from, DIRECTORY_SEPARATOR)) {
                    continue;
                }
            }

            $normalized[] = $actionService->propose($type, $params);
        }

        return $normalized;
    }

    /**
     * Fuzzy match a filename against the list of files actually on disk.
     * Handles normalization issues (NFC/NFD), hidden spaces, and dash variations.
     */
    protected function findBestMatch(string $target, array $files): ?string
    {
        $targetClean = $this->normalizeString($target);
        if (empty($targetClean)) return null;
        
        // 1. Try exact normalized match (Best case)
        foreach ($files as $file) {
            if ($this->normalizeString($file) === $targetClean) {
                return $file;
            }
        }

        // 2. Try basename match (If file is already in a subfolder but LLM only saw the name)
        foreach ($files as $file) {
            if ($this->normalizeString(basename($file)) === $targetClean) {
                return $file;
            }
        }

        // 3. Try "Contains" match (Last resort for very messy/hallucinated filenames)
        foreach ($files as $file) {
            $fileClean = $this->normalizeString(basename($file));
            if (str_contains($fileClean, $targetClean) || str_contains($targetClean, $fileClean)) {
                return $file;
            }
        }

        return null;
    }

    protected function normalizeString(string $str): string
    {
        // 1. Strip extension for base matching (optional but helpful)
        // 2. Lowercase
        // 3. Remove all non-alphanumeric characters (including spaces, dashes, dots)
        // This is a "heavy" normalization to catch variations in LLM hallucinated spaces/dashes.
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $str));
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileArchitectService
{
    public function __construct(protected OllamaService $ollama) {}

    /**
     * Generate explicit actions based on the current file tree and user request.
     */
    public function plan(string $folderPath, string $userRequest, ?string $lastProposal = null): array
    {
        $currentTree = $this->getTree($folderPath);
        $filesList = collect($currentTree)->pluck('path')->implode("\n- ");
        
        $prompt = "### ROLE
        You are a File System Action Planner for a Sovereign macOS Agent.
        
        ### SANDBOX
        Root Folder: {$folderPath}
        Current Files in Sandbox:
        - {$filesList}

        ### CONTEXT
        PREVIOUS PLAN: \"{$lastProposal}\"
        USER REQUEST: \"{$userRequest}\"

        ### TASK
        Identify the specific actions needed to satisfy the request within the sandbox.
        If the user says 'Yes' or 'Proceed', refer to the PREVIOUS PLAN.

        ### OUTPUT FORMAT (Strict JSON)
        {
          \"actions\": [
            { \"type\": \"create_folder\", \"params\": { \"path\": \"docs\" } },
            { \"type\": \"move_file\", \"params\": { \"from\": \"file.pdf\", \"to\": \"docs/file.pdf\" } },
            { \"type\": \"create_file\", \"params\": { \"path\": \"Manifesto.md\", \"content\": \"...\" } }
          ]
        }

        ### RULES
        1. Use RELATIVE PATHS ONLY.
        2. Use the exact filenames from the 'Current Files' list.
        3. If no action is needed, return {\"actions\": []}.
        4. Return ONLY JSON. No conversation.";

        try {
            $response = $this->ollama->generate($prompt, null, [
                'format' => 'json',
                'options' => [
                    'temperature' => 0,
                    'num_predict' => 2000
                ]
            ]);

            Log::debug("FileArchitect: Raw JSON: " . $response);

            $data = json_decode($response, true);
            if (!isset($data['actions']) || !is_array($data['actions'])) return [];

            return $this->normalizeActions($data['actions'], $folderPath);
        } catch (\Throwable $e) {
            Log::error("FileArchitect: Planning failed: " . $e->getMessage());
            return [];
        }
    }

    protected function getTree(string $path): array
    {
        if (!File::isDirectory($path)) return [];
        return collect(File::allFiles($path))
            ->map(fn($f) => ['path' => $f->getRelativePathname()])
            ->toArray();
    }

    protected function normalizeActions(array $actions, ?string $folderPath): array
    {
        $normalized = [];
        $actionService = app(ActionService::class);

        foreach ($actions as $action) {
            if (!isset($action['type'], $action['params'])) continue;

            $type = $action['type'];
            $params = $action['params'];

            // Handle Aliases
            if (isset($params['name']) && !isset($params['path'])) $params['path'] = $params['name'];
            if (isset($params['source']) && !isset($params['from'])) $params['from'] = $params['source'];
            if (isset($params['destination']) && !isset($params['to'])) $params['to'] = $params['destination'];

            // Sandbox Normalization: Ensure paths are relative to the folder
            foreach (['path', 'from', 'to'] as $key) {
                if (isset($params[$key]) && !empty($params[$key])) {
                    $p = $params[$key];
                    // Strip absolute path hallucinations if folderPath is known
                    if ($folderPath && str_contains($p, $folderPath)) {
                        $p = str_replace($folderPath, '', $p);
                    }
                    $params[$key] = ltrim($p, DIRECTORY_SEPARATOR . " ");
                }
            }

            $normalized[] = $actionService->propose($type, $params);
        }

        return $normalized;
    }
}

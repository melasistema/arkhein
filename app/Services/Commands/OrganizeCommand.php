<?php

namespace App\Services\Commands;

use App\Models\Vertical;
use App\Models\SystemTask;
use App\Services\OllamaService;
use App\Services\ActionExtractor;
use Illuminate\Support\Facades\Log;

class OrganizeCommand implements MagicCommandInterface
{
    public function __construct(
        protected OllamaService $ollama,
        protected ActionExtractor $worker
    ) {}

    public static function getHandlesIntent(): string
    {
        return 'COMMAND_ORGANIZE';
    }

    public function execute(Vertical $vertical, string $query, array $perception, array $currentFiles, SystemTask $task): array
    {
        $folder = $vertical->folder;
        if (!$folder) {
            $task->update(['status' => SystemTask::STATUS_FAILED]);
            return [
                'response' => "No folder associated with this vertical to organize.",
                'actions' => [],
                'reasoning' => null
            ];
        }

        $task->update(['description' => 'Librarian: Designing strategic organization plan']);
        Log::info("Arkhein Librarian: Generating strategic organization plan.");

        $schema = $folder->environmental_schema ? json_encode($folder->environmental_schema) : "Generic files";

        // 1. Taxonomy Generation pass
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
        $rawActions = $data['actions'] ?? $data ?? [];

        // We use the worker's normalization logic
        $actions = $this->worker->normalizeActions($rawActions, $folder->path, $currentFiles);
        $reasoning = "I've designed a strategic taxonomy (" . implode(', ', $taxonomy) . ") and mapped your files to their logical homes.";

        $task->update([
            'status' => SystemTask::STATUS_COMPLETED,
            'progress' => 100,
            'description' => "Librarian: Strategic plan generated"
        ]);

        return [
            'response' => "The **Strategic Librarian** has analyzed your folder patterns and designed a new taxonomy. I've prepared the organization plan below. Please confirm to execute.",
            'actions' => $actions,
            'reasoning' => $reasoning
        ];
    }
}
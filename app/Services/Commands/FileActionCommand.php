<?php

namespace App\Services\Commands;

use App\Models\Vertical;
use App\Models\SystemTask;
use App\Services\ActionExtractor;
use App\Services\OllamaService;
use App\Services\RagService;
use App\Services\ActionService;
use Illuminate\Support\Facades\Log;

class FileActionCommand implements MagicCommandInterface
{
    public function __construct(
        protected ActionExtractor $worker,
        protected OllamaService $ollama,
        protected RagService $rag,
        protected ActionService $actionService
    ) {}

    public static function getHandlesIntent(): string|array
    {
        return ['COMMAND_CREATE', 'COMMAND_MOVE', 'COMMAND_DELETE'];
    }

    public function execute(Vertical $vertical, string $query, array $perception, array $currentFiles, SystemTask $task): array
    {
        $folder = $vertical->folder;
        $folderPath = $folder ? $folder->path : null;
        $schema = $folder?->environmental_schema ?? [];
        
        $history = $vertical->interactions()->latest()->limit(3)->get()->reverse();
        $context = $history->map(fn($m) => "{$m->role}: {$m->content}")->implode("\n");
        
        // Use the high-resolution extractor with perception grounding
        $result = $this->worker->extract($query, $folderPath, $currentFiles, $context, $perception, $schema);
        $pendingActions = $result['actions'];
        $reasoning = $result['reasoning'];
        
        $this->fillPlaceholders($vertical, $pendingActions);

        $isDrafting = collect($pendingActions)->contains(fn($a) => ($a['params']['content'] ?? '') === 'AGENT_DRAFTING_IN_PROGRESS');
        $isBusy = collect($pendingActions)->contains(fn($a) => ($a['params']['content'] ?? '') === 'AGENT_BUSY_TASK_ALREADY_IN_PROGRESS');

        $response = !empty($pendingActions) 
            ? ($isBusy 
                ? "I am currently processing another complex task for this folder. Please wait for the current operation to finish before starting a new one."
                : ($isDrafting 
                    ? "I have started the **Document Architect** pipeline to assemble this file in the background. This is a complex task involving multiple documents; you can monitor my progress in the **System Heartbeat**."
                    : "Command parsed. I've prepared the plan. Please confirm below."))
            : "I couldn't identify the specific targets for that command. Try `/create [filename]`.";
        
        $task->update([
            'status' => SystemTask::STATUS_COMPLETED, 
            'progress' => 100, 
            'description' => 'Command plan ready'
        ]);

        return [
            'response' => $response,
            'actions' => $pendingActions,
            'reasoning' => $reasoning
        ];
    }

    protected function fillPlaceholders(Vertical $vertical, array &$actions, ?string $defaultContent = null)
    {
        foreach ($actions as &$action) {
            if ($action['type'] === 'create_file' && ($action['params']['content'] ?? '') === 'PLACEHOLDER') {
                $instruction = $action['params']['instruction'] ?? null;
                if ($instruction) {
                    // Check if this is an aggregate task (all, every, list of everything)
                    $isAggregate = preg_match('/\b(all|every|complete|list of|entire)\b/i', $instruction);

                    if ($isAggregate && $vertical->folder) {
                        // Create a specific task record for this drafting operation
                        $task = \App\Models\SystemTask::createInSilo(
                            $vertical->folder->id,
                            'drafting',
                            "Drafting {$action['params']['path']}"
                        );

                        \App\Jobs\DraftDocumentJob::dispatch($vertical->folder, $action['params']['path'], $instruction, $task->id)
                            ->onConnection('background');
                        
                        $action['params']['content'] = "AGENT_DRAFTING_IN_PROGRESS";
                    } else {
                        $knowledge = $this->rag->recall($instruction, 15, $vertical->folder_id);
                        
                        // The 'Document Architect' Protocol: Higher density, better structure
                        $prompt = "You are the Arkhein Document Architect.\n" .
                                 "TASK: Draft a professional markdown document based on the provided KNOWLEDGE and INSTRUCTION.\n\n" .
                                 "KNOWLEDGE FRAGMENTS:\n" . collect($knowledge)->map(fn($k) => "- " . $k['content'])->implode("\n\n") . "\n\n" .
                                 "INSTRUCTION: {$instruction}\n\n" .
                                 "RULES:\n" .
                                 "1. Use professional, clear language.\n" .
                                 "2. Structure with appropriate Markdown headers (##, ###).\n" .
                                 "3. Be concise but exhaustive regarding the provided facts.\n" .
                                 "4. Do NOT add preamble (e.g., 'Here is the file...'). Output ONLY the document content.\n\n" .
                                 "DOCUMENT CONTENT:";

                        $action['params']['content'] = $this->ollama->generate($prompt, null, [
                            'options' => ['temperature' => 0.2, 'num_ctx' => 8192]
                        ]);
                    }
                    
                    $action['description'] = $this->actionService->describe($action['type'], $action['params']);
                    continue;
                }

                if ($defaultContent) {
                    $action['params']['content'] = $defaultContent;
                } else {
                    // Fallback to recent history if no specific instruction was extracted
                    $candidates = $vertical->interactions()->where('role', 'assistant')->latest()->limit(10)->get();
                    foreach ($candidates as $msg) {
                        $meta = $msg->metadata;
                        if (is_string($meta)) $meta = json_decode($meta, true);
                        $intent = $meta['intent'] ?? 'CHAT';
                        if (in_array($intent, ['COMMAND_HELP', 'CONFIRMATION', 'COMMAND_SYNC'])) continue;
                        if (str_contains($msg->content, "Command parsed") || str_contains($msg->content, "prepared the plan")) continue;
                        
                        $cleanContent = preg_replace('/^### (PREVIEW|DRAFT|SUMMARY):[\s\n]*/i', '', $msg->content);
                        $action['params']['content'] = $cleanContent;
                        break;
                    }
                }
                
                if (($action['params']['content'] ?? '') === 'PLACEHOLDER') {
                    $action['params']['content'] = "Arkhein Archive: No recent conversation context was found to populate this file.";
                }
                $action['description'] = $this->actionService->describe($action['type'], $action['params']);
            }
        }
    }
}
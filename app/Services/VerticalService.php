<?php

namespace App\Services;

use App\Models\Vertical;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VerticalService
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag,
        protected ActionService $actionService,
        protected IntentService $bouncer,
        protected ActionExtractor $worker,
        protected FileArchitectService $architect,
        protected CognitiveArbiter $arbiter
    ) {}

    /**
     * Handle a query using the Multi-Stage Orchestration Pattern ("Agentic Loop").
     */
    public function ask(Vertical $vertical, string $query): array
    {
        Log::info("Arkhein Orchestrator: Start", ['query' => $query]);
        $vertical->interactions()->create(['role' => 'user', 'content' => $query]);

        $intent = $this->bouncer->classify($query, $vertical);
        $folderPath = $vertical->folder?->path;
        $currentFiles = $this->getFolderFiles($folderPath);

        // 1. AUTO-EXECUTION (Natural Language Confirmation)
        if ($intent === 'CONFIRMATION') {
            return $this->handleConfirmation($vertical, $intent);
        }

        // 2. MAGIC TOUCH COMMANDS
        if (str_starts_with($intent, 'COMMAND_')) {
            return $this->handleCommand($vertical, $intent, $query, $folderPath, $currentFiles);
        }

        // 3. NORMAL INTENTS (COGNITIVE CHAT)
        $assistantResponse = $this->arbiter->process($query, $vertical->folder_id);

        if (empty(trim($assistantResponse))) {
            $assistantResponse = "I have processed your request.";
        }

        return $this->finalize($vertical, $assistantResponse, [], [], $intent, null);
    }

    /**
     * Stream a response with intermediate event notifications.
     */
    public function stream(Vertical $vertical, string $query, callable $onEvent): void
    {
        Log::info("Arkhein Orchestrator: Stream Start", ['query' => $query]);
        $vertical->interactions()->create(['role' => 'user', 'content' => $query]);

        $intent = $this->bouncer->classify($query, $vertical);
        $folderPath = $vertical->folder?->path;
        $currentFiles = $this->getFolderFiles($folderPath);

        // 1. SYNC INTENTS (Confirmation/Commands)
        if ($intent === 'CONFIRMATION') {
            $result = $this->handleConfirmation($vertical, $intent);
            $onEvent('final', $result);
            return;
        }

        if (str_starts_with($intent, 'COMMAND_')) {
            $result = $this->handleCommand($vertical, $intent, $query, $folderPath, $currentFiles);
            $onEvent('final', $result);
            return;
        }

        // 2. ASYNC INTENTS (COGNITIVE STREAM)
        $onEvent('status', 'Initializing cognitive pipeline...');
        
        // We create a temporary task record for visibility in Earthbeat during the reasoning passes
        $task = \App\Models\SystemTask::createInSilo(
            $vertical->folder->id,
            'thinking',
            "Reasoning: {$query}"
        );

        $fullResponse = $this->arbiter->process($query, $vertical->folder_id, $task);

        if (empty(trim($fullResponse))) {
            $fullResponse = "I have processed your request.";
        }

        // For now, we output the result in one go to ensure Level 6 generation quality
        // Future refinement: stream Level 6 specifically.
        $onEvent('chunk', $fullResponse);

        $result = $this->finalize($vertical, $fullResponse, [], [], $intent, null);
        $onEvent('completed', $result);
    }

    protected function getFolderFiles(?string $path): array
    {
        if ($path && File::isDirectory($path)) {
            return collect(File::allFiles($path))->map(fn($f) => $f->getRelativePathname())->toArray();
        }
        return [];
    }

    protected function getRecentHistory(Vertical $vertical, int $limit = 10): \Illuminate\Support\Collection
    {
        return $vertical->interactions()->latest()->skip(1)->limit($limit)->get()->reverse();
    }

    protected function handleConfirmation(Vertical $vertical, string $intent): array
    {
        Log::info("Arkhein Orchestrator: Confirmation received. Searching for plan.");
        $recent = $vertical->interactions()->where('role', 'assistant')->latest()->limit(5)->get();
        $lastAssistant = null;
        $actionsToRun = [];

        foreach ($recent as $msg) {
            $meta = $msg->metadata;
            if (is_string($meta)) $meta = json_decode($meta, true);
            if (!empty($meta['pending_actions'])) {
                $lastAssistant = $msg;
                $actionsToRun = $meta['pending_actions'];
                break;
            }
        }

        if ($lastAssistant && !empty($actionsToRun)) {
            Log::info("Arkhein Orchestrator: Plan found. Executing.", ['count' => count($actionsToRun)]);
            $results = [];
            foreach ($actionsToRun as $action) {
                $res = $this->actionService->execute($action['type'], $action['params'], $vertical->folder);
                $results[] = ($res['success'] ? "✅" : "❌") . " " . $action['description'];
            }
            
            $assistantResponse = "Execution Complete:\n\n" . implode("\n", $results) . "\n\nI have verified the folder state.";
            $origMeta = $lastAssistant->metadata;
            if (is_string($origMeta)) $origMeta = json_decode($origMeta, true);
            foreach ($actionsToRun as &$a) { $a['status'] = 'executed'; }
            $lastAssistant->update(['metadata' => array_merge($origMeta, ['pending_actions' => $actionsToRun])]);

            return $this->finalize($vertical, $assistantResponse, [], [], $intent);
        }

        Log::warning("Arkhein Orchestrator: No plan found for confirmation.");
        $assistantResponse = "I'm ready to proceed, but I've lost track of the specific file plan. Could you re-state what you'd like me to save or move?";
        return $this->finalize($vertical, $assistantResponse, [], [], $intent);
    }

    protected function handleCommand($vertical, $intent, $query, $folderPath, $currentFiles): array
    {
        $assistantResponse = "";
        $pendingActions = [];
        $reasoning = null;

        // 1. Initial Perception Pass (Level 1 Grounding)
        $folder = $vertical->folder;
        $schema = $folder?->environmental_schema ?? [];
        $task = \App\Models\SystemTask::createInSilo($folder->id, 'thinking', "Magic Command: Analyzing \"{$intent}\"");
        
        $perception = $this->arbiter->processPerception($query, $schema);

        switch ($intent) {
            case 'COMMAND_HELP':
                $assistantResponse = "### 🪄 Arkhein Magic Commands\n\n" .
                    "- `/create [filename]` : Create a new file (uses recent context for content).\n" .
                    "- `/move [file] [folder]` : Move a file to a subfolder.\n" .
                    "- `/organize` : Automatically group files by their extension.\n" .
                    "- `/delete [filename]` : Remove a file from the authorized folder.\n\n" .
                    "You can follow commands with natural language, e.g., `/create a summary file called news.md`.";
                $task->update(['status' => \App\Models\SystemTask::STATUS_COMPLETED, 'progress' => 100, 'description' => 'Help displayed']);
                break;

            case 'COMMAND_CREATE':
            case 'COMMAND_MOVE':
            case 'COMMAND_DELETE':
                $history = $vertical->interactions()->latest()->limit(3)->get()->reverse();
                $context = $history->map(fn($m) => "{$m->role}: {$m->content}")->implode("\n");
                
                // Use the high-resolution extractor with perception grounding
                $result = $this->worker->extract($query, $folderPath, $currentFiles, $context, $perception, $schema);
                $pendingActions = $result['actions'];
                $reasoning = $result['reasoning'];
                $this->fillPlaceholders($vertical, $pendingActions);

                $isDrafting = collect($pendingActions)->contains(fn($a) => ($a['params']['content'] ?? '') === 'AGENT_DRAFTING_IN_PROGRESS');
                $isBusy = collect($pendingActions)->contains(fn($a) => ($a['params']['content'] ?? '') === 'AGENT_BUSY_TASK_ALREADY_IN_PROGRESS');

                $assistantResponse = !empty($pendingActions) 
                    ? ($isBusy 
                        ? "I am currently processing another complex task for this folder. Please wait for the current operation to finish before starting a new one."
                        : ($isDrafting 
                            ? "I have started the **Document Architect** pipeline to assemble this file in the background. This is a complex task involving multiple documents; you can monitor my progress in the **System Heartbeat**."
                            : "Command parsed. I've prepared the plan. Please confirm below."))
                    : "I couldn't identify the specific targets for that command. Try `/create [filename]`.";
                
                $task->update(['status' => \App\Models\SystemTask::STATUS_COMPLETED, 'progress' => 100, 'description' => 'Command plan ready']);
                break;

            case 'COMMAND_ORGANIZE':
                if (!$vertical->folder) {
                    $assistantResponse = "No folder associated with this vertical to organize.";
                    $task->update(['status' => \App\Models\SystemTask::STATUS_FAILED]);
                    break;
                }

                $task->update(['description' => 'Librarian: Designing strategic organization plan']);

                $result = $this->worker->extract($query, $folderPath, $currentFiles, null, $perception, $schema);
                $pendingActions = $result['actions'];
                $reasoning = $result['reasoning'];

                $task->update([
                    'status' => \App\Models\SystemTask::STATUS_COMPLETED,
                    'progress' => 100,
                    'description' => "Librarian: Strategic plan generated"
                ]);

                $assistantResponse = "The **Strategic Librarian** has analyzed your folder patterns and designed a new taxonomy. I've prepared the organization plan below. Please confirm to execute.";
                break;

            case 'COMMAND_SYNC':
                if ($vertical->folder) {
                    $task->update(['type' => 'sync', 'description' => "Re-indexing silo @{$vertical->folder->name}"]);
                    \App\Jobs\IndexFolderJob::dispatch($vertical->folder, $task->id)->onConnection('background');
                    $assistantResponse = "Re-indexing started for **{$vertical->folder->name}**. You can monitor the progress in the system monitor.";
                } else {
                    $assistantResponse = "No folder associated with this vertical to sync.";
                    $task->update(['status' => \App\Models\SystemTask::STATUS_FAILED]);
                }
                break;

            default:
                $assistantResponse = "Command not recognized.";
                $task->update(['status' => \App\Models\SystemTask::STATUS_FAILED]);
        }

        return $this->finalize($vertical, $assistantResponse, $pendingActions, [], $intent, $reasoning);
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

    protected function finalize($vertical, $content, $actions, $knowledge, $intent, ?string $reasoning = null): array
    {
        $interaction = $vertical->interactions()->create([
            'role' => 'assistant',
            'content' => $content,
            'metadata' => [
                'pending_actions' => $actions, 
                'intent' => $intent,
                'reasoning' => $reasoning
            ]
        ]);

        return [
            'interaction' => $interaction->fresh(),
            'response' => $content,
            'pending_actions' => $actions,
            'reasoning' => $reasoning,
            'sources' => collect($knowledge)->map(fn($k) => ['filename' => $k['metadata']['filename'] ?? 'unknown'])->unique()->values()->all()
        ];
    }

    protected function buildChatMessages($vertical, $query, $knowledge, $history): array
    {
        $systemPrompt = "You are Arkhein Vantage, an advanced analytical assistant for a specific folder of documents.\nYour primary role is to converse, synthesize, and analyze the documents provided in your knowledge context.\n\nCRITICAL RULES:\n1. Answer questions based on the provided SILO MANIFEST and RELEVANT FRAGMENTS.\n2. USE THE SILO MANIFEST to understand what files exist. This is your 'Ground Truth' for the folder structure.\n3. If the user asks for a list, a count, or 'what is in here', use the SILO MANIFEST exclusively.\n4. Use RELEVANT FRAGMENTS for deep details inside those files.\n5. If the user asks you to write, draft, or summarize something, DO IT directly in your response.\n6. You CANNOT create or move files directly.\n7. Be PROACTIVE: If you just provided a long summary or drafted a document, politely suggest to the user: 'If you want me to save this to a file, just use the command `/create [filename]`'.";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        // 1. Build the Silo Manifest (Structural Ground Truth)
        $allDocs = \App\Models\Document::where('folder_id', $vertical->folder_id)->get(['path', 'filename', 'summary']);
        $manifest = "### SILO MANIFEST (AUTHORIZED FILES):\n";
        if ($allDocs->isEmpty()) {
            $manifest .= "No documents found in this silo.\n";
        } else {
            foreach ($allDocs as $doc) {
                $manifest .= "- File: {$doc->path} | Summary: " . ($doc->summary ?: 'No summary available') . "\n";
            }
        }
        
        // Inject Manifest as a SYSTEM-level message so it stays anchored
        $messages[] = ['role' => 'system', 'content' => $manifest];

        foreach ($history as $msg) { $messages[] = ['role' => $msg->role, 'content' => $msg->content]; }
        
        // 2. Build the Deep Knowledge Context (RAG)
        $ctx = "### RELEVANT FRAGMENTS (DEEP DETAILS):\n";
        $currentSource = '';
        foreach ($knowledge as $item) { 
            $sourceName = $item['metadata']['filename'] ?? 'unknown';
            $subfolder = $item['vessel']['subfolder'] ?? '';
            $summary = $item['vessel']['summary'] ?? '';

            if ($currentSource !== $sourceName) {
                $ctx .= "--- SOURCE: " . ($subfolder ? "{$subfolder} > " : "") . "{$sourceName} ---\n";
                if ($summary) $ctx .= "DOCUMENT SUMMARY: {$summary}\n";
                $currentSource = $sourceName;
            }
            
            $ctx .= "FRAGMENT: " . $item['content'] . "\n\n";
        }
        
        $messages[] = ['role' => 'user', 'content' => "{$ctx}\n\nQuery: {$query}"];
        return $messages;
    }
}

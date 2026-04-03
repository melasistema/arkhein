<?php

namespace App\Services;

use App\Models\Vertical;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class VerticalService
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag,
        protected ActionService $actionService,
        protected IntentService $bouncer,
        protected ActionExtractor $worker
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

        // 3. NORMAL INTENTS (CHAT)
        $relevantKnowledge = $this->rag->recall($query, 10, $vertical->folder_id);
        $history = $this->getRecentHistory($vertical);
        $assistantResponse = $this->ollama->chat($this->buildChatMessages($vertical, $query, $relevantKnowledge, $history));

        if (empty(trim($assistantResponse))) {
            $assistantResponse = "I have processed your request.";
        }

        return $this->finalize($vertical, $assistantResponse, [], $relevantKnowledge, $intent, null);
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

        // 2. ASYNC INTENTS (RAG CHAT)
        $onEvent('status', 'Recalling knowledge...');
        $relevantKnowledge = $this->rag->recall($query, 10, $vertical->folder_id);
        
        $onEvent('sources', collect($relevantKnowledge)->map(fn($k) => ['filename' => $k['metadata']['filename'] ?? 'unknown'])->unique()->values()->all());

        $history = $this->getRecentHistory($vertical);
        $messages = $this->buildChatMessages($vertical, $query, $relevantKnowledge, $history);

        $fullResponse = "";
        $onEvent('status', 'Synthesizing...');
        
        $this->ollama->streamChat($messages, function ($chunk) use (&$fullResponse, $onEvent) {
            $fullResponse .= $chunk;
            $onEvent('chunk', $chunk);
        });

        if (empty(trim($fullResponse))) {
            $fullResponse = "I have processed your request.";
            $onEvent('chunk', $fullResponse);
        }

        $result = $this->finalize($vertical, $fullResponse, [], $relevantKnowledge, $intent, null);
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

        switch ($intent) {
            case 'COMMAND_HELP':
                $assistantResponse = "### 🪄 Arkhein Magic Commands\n\n" .
                    "- `/create [filename]` : Create a new file (uses recent context for content).\n" .
                    "- `/move [file] [folder]` : Move a file to a subfolder.\n" .
                    "- `/organize` : Automatically group files by their extension.\n" .
                    "- `/delete [filename]` : Remove a file from the authorized folder.\n\n" .
                    "You can follow commands with natural language, e.g., `/create a summary file called news.md`.";
                break;

            case 'COMMAND_CREATE':
            case 'COMMAND_MOVE':
            case 'COMMAND_DELETE':
                $history = $vertical->interactions()->latest()->limit(3)->get()->reverse();
                $context = $history->map(fn($m) => "{$m->role}: {$m->content}")->implode("\n");
                $result = $this->worker->extract($query, $folderPath, $currentFiles, $context);
                $pendingActions = $result['actions'];
                $reasoning = $result['reasoning'];
                $this->fillPlaceholders($vertical, $pendingActions);

                $assistantResponse = !empty($pendingActions) 
                    ? "Command parsed. I've prepared the plan. Please confirm below."
                    : "I couldn't identify the specific targets for that command. Try `/create [filename]`.";
                break;

            case 'COMMAND_ORGANIZE':
                $orgPrompt = "Organize all files in this folder by their core topics and project names. 
                Group documents into relevant thematic folders (e.g., 'marketing', 'interviews', 'product', 'summaries'). 
                Ensure names are professional and consistent.";
                
                $result = $this->worker->extract($orgPrompt, $folderPath, $currentFiles);
                $pendingActions = $result['actions'];
                $reasoning = $result['reasoning'];
                $assistantResponse = "Strategic organization plan generated. I've analyzed your file topics and grouped them logically. Please confirm below.";
                break;

            case 'COMMAND_SYNC':
                if ($vertical->folder) {
                    \App\Jobs\IndexFolderJob::dispatch($vertical->folder)->onConnection('background');
                    $assistantResponse = "Re-indexing started for **{$vertical->folder->name}**. I will update my knowledge base shortly.";
                } else {
                    $assistantResponse = "No folder associated with this vertical to sync.";
                }
                break;

            default:
                $assistantResponse = "Command not recognized.";
        }

        return $this->finalize($vertical, $assistantResponse, $pendingActions, [], $intent, $reasoning);
    }

    protected function fillPlaceholders(Vertical $vertical, array &$actions, ?string $defaultContent = null)
    {
        foreach ($actions as &$action) {
            if ($action['type'] === 'create_file' && ($action['params']['content'] ?? '') === 'PLACEHOLDER') {
                $instruction = $action['params']['instruction'] ?? null;
                if ($instruction) {
                    $knowledge = $this->rag->recall($instruction, 15, $vertical->folder_id);
                    $prompt = "You are drafting a professional document based on specific knowledge.\nGenerate high-quality, well-structured markdown content.\n\n### KNOWLEDGE:\n" . collect($knowledge)->map(fn($k) => "- " . $k['content'])->implode("\n\n") . "\n\n### TASK:\n{$instruction}";
                    $action['params']['content'] = $this->ollama->generate($prompt);
                    $action['description'] = $this->actionService->describe($action['type'], $action['params']);
                    continue;
                }

                if ($defaultContent) {
                    $action['params']['content'] = $defaultContent;
                } else {
                    $candidates = $vertical->interactions()->where('role', 'assistant')->latest()->limit(10)->get();
                    foreach ($candidates as $msg) {
                        $meta = $msg->metadata;
                        if (is_string($meta)) $meta = json_decode($meta, true);
                        $intent = $meta['intent'] ?? 'CHAT';
                        if (in_array($intent, ['COMMAND_HELP', 'CONFIRMATION', 'COMMAND_SYNC'])) continue;
                        if (str_contains($msg->content, "Command parsed") || str_contains($msg->content, "prepared the plan")) continue;
                        $cleanContent = preg_replace('/^### PREVIEW:[\s\n]*/i', '', $msg->content);
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

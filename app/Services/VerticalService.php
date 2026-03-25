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

        $intent = $this->bouncer->classify($query);
        
        $folderPath = $vertical->folder?->path;
        $currentFiles = [];
        if ($folderPath && File::isDirectory($folderPath)) {
            $currentFiles = collect(File::allFiles($folderPath))->map(fn($f) => $f->getRelativePathname())->toArray();
        }

        $assistantResponse = "";
        $pendingActions = [];
        $relevantKnowledge = [];

        // 1. AUTO-EXECUTION (Natural Language Confirmation)
        if ($intent === 'CONFIRMATION') {
            Log::info("Arkhein Orchestrator: Confirmation received. Searching for plan.");
            
            // Search back up to 5 messages for a plan
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
                
                // Update original plan to reflect execution
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

        // 2. MAGIC TOUCH COMMANDS
        if (str_starts_with($intent, 'COMMAND_')) {
            $result = $this->handleCommand($vertical, $intent, $query, $folderPath, $currentFiles);
            return $result;
        }

        // 3. NORMAL INTENTS (CHAT)
        $relevantKnowledge = $this->rag->recall($query, 10, $vertical->folder_id);
        $history = $vertical->interactions()->latest()->skip(1)->limit(5)->get()->reverse();
        $assistantResponse = $this->ollama->chat($this->buildChatMessages($vertical, $query, $relevantKnowledge, $history));

        if (empty(trim($assistantResponse))) {
            $assistantResponse = "I have processed your request.";
        }

        return $this->finalize($vertical, $assistantResponse, $pendingActions, $relevantKnowledge, $intent, null);
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
                
                // Fill placeholders using the previous assistant message if available
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
                
                // 1. DEEP CREATION: Check if there's a specific instruction for this file
                $instruction = $action['params']['instruction'] ?? null;
                if ($instruction) {
                    Log::info("Arkhein Orchestrator: Triggering Deep Creation for instruction -> {$instruction}");
                    
                    // Perform a fresh RAG lookup for this specific file content
                    $knowledge = $this->rag->recall($instruction, 15, $vertical->folder_id);
                    $prompt = "You are drafting a professional document based on specific knowledge.
                    Generate high-quality, well-structured markdown content.
                    
                    ### KNOWLEDGE:
                    " . collect($knowledge)->map(fn($k) => "- " . $k['content'])->implode("\n\n") . "
                    
                    ### TASK:
                    {$instruction}";

                    $deepContent = $this->ollama->generate($prompt);
                    $action['params']['content'] = $deepContent;
                    $action['description'] = $this->actionService->describe($action['type'], $action['params']);
                    continue;
                }

                // 2. CONTEXTUAL CREATION: Use default content (e.g. from current turn synthesis)
                if ($defaultContent) {
                    $action['params']['content'] = $defaultContent;
                } else {
                    // 3. HISTORICAL CREATION: Find the last knowledge-rich message in history
                    $candidates = $vertical->interactions()
                        ->where('role', 'assistant')
                        ->latest()
                        ->limit(10)
                        ->get();

                    foreach ($candidates as $msg) {
                        $meta = $msg->metadata;
                        if (is_string($meta)) $meta = json_decode($meta, true);
                        $intent = $meta['intent'] ?? 'CHAT';

                        if (in_array($intent, ['COMMAND_HELP', 'CONFIRMATION', 'COMMAND_SYNC'])) continue;
                        if (str_contains($msg->content, "Command parsed") || str_contains($msg->content, "prepared the plan")) continue;
                        if (str_contains($msg->content, "lost track of the specific file plan")) continue;

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
        $systemPrompt = "You are Arkhein Vantage, an advanced analytical assistant for a specific folder of documents.
        Your primary role is to converse, synthesize, and analyze the documents provided in your knowledge context.
        
        CRITICAL RULES:
        1. Answer questions based on the provided KNOWLEDGE.
        2. If the user asks you to write, draft, or summarize something, DO IT directly in your response. Do not say 'I will create a file'. Just write the text.
        3. You CANNOT create or move files directly.
        4. Be PROACTIVE: If you just provided a long summary or drafted a document, politely suggest to the user: 'If you want me to save this to a file, just use the command `/create [filename]`'.
        5. If the user asks you to move or organize files, explain: 'I can organize your files! Just type `/organize` or `/move [filename] [folder]`.'";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $msg) { $messages[] = ['role' => $msg->role, 'content' => $msg->content]; }
        $ctx = "### KNOWLEDGE:\n";
        foreach ($knowledge as $item) { $ctx .= "- " . $item['content'] . "\n\n"; }
        $messages[] = ['role' => 'user', 'content' => "{$ctx}\n\nQuery: {$query}"];
        return $messages;
    }
}

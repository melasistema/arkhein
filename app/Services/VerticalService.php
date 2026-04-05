<?php

namespace App\Services;

use App\Models\Vertical;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use App\Services\Commands\CommandRegistry;

class VerticalService
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag,
        protected ActionService $actionService,
        protected IntentService $bouncer,
        protected ActionExtractor $worker,
        protected FileArchitectService $architect,
        protected CognitiveArbiter $arbiter,
        protected CommandRegistry $commandRegistry
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
        $task = null;
        if ($vertical->folder_id) {
            $task = \App\Models\SystemTask::createInSilo(
                $vertical->folder_id,
                'thinking',
                "Reasoning: {$query}"
            );
        }

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
        // 1. Initial Perception Pass (Level 1 Grounding)
        $folder = $vertical->folder;
        $schema = $folder?->environmental_schema ?? [];
        
        $task = null;
        if ($vertical->folder_id) {
            $task = \App\Models\SystemTask::createInSilo($vertical->folder_id, 'thinking', "Magic Command: Analyzing \"{$intent}\"");
        }
        
        $perception = $this->arbiter->processPerception($query, $schema);

        $command = $this->commandRegistry->get($intent);

        if (!$command) {
            if ($task) $task->update(['status' => \App\Models\SystemTask::STATUS_FAILED]);
            return $this->finalize($vertical, "Command not recognized.", [], [], $intent, null);
        }

        try {
            $result = $command->execute($vertical, $query, $perception, $currentFiles, $task ?: new \App\Models\SystemTask());
            return $this->finalize(
                $vertical, 
                $result['response'], 
                $result['actions'], 
                [], 
                $intent, 
                $result['reasoning']
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Command execution failed: " . $e->getMessage());
            if ($task) $task->update(['status' => \App\Models\SystemTask::STATUS_FAILED]);
            return $this->finalize($vertical, "An error occurred while executing the command.", [], [], $intent, null);
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

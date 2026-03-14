<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Setting;
use App\Models\ManagedFolder;
use App\Services\OllamaService;
use App\Services\MemoryService;
use App\Services\KnowledgeService;
use App\Services\FileOperationService;
use App\Services\PromptService;
use App\Services\IntentService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

use App\Models\Conversation;
use App\Models\Message;

class ChatController extends Controller
{
    public function index()
    {
        return Inertia::render('Chat', [
            'conversations' => Conversation::latest()->get(),
        ]);
    }

    public function start(Request $request)
    {
        $request->validate(['title' => 'required|string|max:255']);
        $conversation = Conversation::create(['title' => $request->title]);
        return response()->json($conversation);
    }

    public function history(Conversation $conversation)
    {
        return response()->json([
            'messages' => $conversation->messages()->oldest()->get()
        ]);
    }

    public function suggestions(FileOperationService $files)
    {
        $authorizedFiles = $files->listAuthorizedFiles();
        $folders = ManagedFolder::all()->map(fn($f) => [
            'type' => 'folder', 'name' => $f->name, 'path' => $f->path
        ]);
        $fileItems = collect($authorizedFiles)->map(fn($f) => [
            'type' => 'file', 'name' => $f['name'], 'path' => $f['path']
        ]);
        return response()->json(['items' => $folders->concat($fileItems)->values()]);
    }

    public function send(
        Request $request, 
        OllamaService $ollama, 
        KnowledgeService $knowledge, 
        FileOperationService $files,
        PromptService $prompts,
        IntentService $intents
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));

        $input = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $conversation = Conversation::findOrFail($conversationId);

        // 1. Build Context & Settings
        $model = Setting::get('llm_model', config('services.ollama.model', 'llama3.2:1b'));
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model', 'nomic-embed-text:latest'));
        $dimensions = (int) Setting::get('embedding_dimensions', 768);

        // 2. Vectorize and Save User Message
        $userEmbedding = $ollama->embeddings($embeddingModel, $input);
        $conversation->messages()->create([
            'role' => 'user', 
            'content' => $input,
            'embedding' => $userEmbedding
        ]);

        // Save User input to Knowledge base too
        if ($userEmbedding) {
            app(MemoryService::class)->save(Str::uuid(), "User Message: $input", $userEmbedding, 'chat_history', ['conversation_id' => $conversationId]);
        }

        // 3. Classify Intent
        $intent = $intents->classify($input);

        // 4. Build Context (Optimized)
        $memContext = "DIGITAL MEMORY:\n";
        $relevantKnowledge = $knowledge->recall($input, 5);
        foreach ($relevantKnowledge as $m) {
            $memContext .= "- " . $m['content'] . "\n";
        }

        $fileContext = "ARCHIVE REGISTRY:\n";
        if ($intent === 'FILE_SYSTEM') {
            // Full registry for file system tasks
            $authorizedFiles = $files->listAuthorizedFiles();
            foreach ($authorizedFiles as $f) {
                $fileContext .= "- {$f['name']} -> {$f['path']} ({$f['type']})\n";
            }
        } else {
            // High-level summary for general chat
            $fileContext .= $files->getRegistrySummary();
        }

        $history = $conversation->messages()->latest()->limit(10)->get()->reverse();
        $historyContext = "RECENT CONVERSATION:\n";
        foreach ($history as $h) {
            $historyContext .= strtoupper($h->role) . ": " . $h->content . "\n";
        }

        // 5. Modular System Prompt
        $systemPrompt = $prompts->buildSystemPrompt();
        if ($intent === 'FILE_SYSTEM') {
            $systemPrompt .= "\n\nCRITICAL: Detected Intent: FILE MANAGEMENT. You MUST use the ACTION PROTOCOL for all changes.";
        } else {
            $systemPrompt .= "\n\nCRITICAL: Detected Intent: GENERAL DIALOGUE. Prioritize conversation. Do NOT propose actions unless the user explicitly requests one.";
        }

        $finalPrompt = "System: $systemPrompt\n\n$fileContext\n\n$memContext\n\n$historyContext\n\nUser: $input\nAssistant:";

        // 6. Generate Response
        $response = $ollama->generate($model, $finalPrompt);
        $assistantMessage = $response['response'] ?? "I'm sorry, I couldn't generate a response.";

        // 7. Parse Actions
        $pendingActions = [];
        if (preg_match_all('/\[ACTION:(.*?)\]/', $assistantMessage, $matches)) {
            foreach ($matches[1] as $json) {
                $actionData = json_decode($json, true);
                if ($actionData) {
                    $pendingActions[] = array_merge($actionData, ['status' => 'pending']);
                }
            }
            $assistantMessage = trim(preg_replace('/\[ACTION:.*?\]/', '', $assistantMessage));
        }

        // 8. Vectorize and Save Assistant Response
        $assistantEmbedding = $ollama->embeddings($embeddingModel, $assistantMessage);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $assistantMessage,
            'embedding' => $assistantEmbedding,
            'metadata' => ['pending_actions' => $pendingActions]
        ]);

        // Save Assistant response to Knowledge base
        if ($assistantEmbedding) {
            app(MemoryService::class)->save(Str::uuid(), "Assistant Message: $assistantMessage", $assistantEmbedding, 'chat_history', ['conversation_id' => $conversationId]);
        }

        // 9. Async Reflection (Backgrounded)
        \App\Jobs\ReflectInteraction::dispatch($input, $assistantMessage);

        return response()->json([
            'message' => $assistantMessage,
            'pending_actions' => $pendingActions,
            'intent' => $intent
        ]);
    }

    public function executePendingAction(Request $request, FileOperationService $files, MemoryService $memory)
    {
        $action = $request->input('action');
        $result = $this->executeAction($action, $files, $memory);
        return response()->json($result);
    }

    protected function executeAction(array $action, FileOperationService $files, MemoryService $memory): array
    {
        $type = $action['type'] ?? '';
        $params = $action['params'] ?? [];
        Log::debug("Arkhein Executing Action", ['type' => $type, 'params' => $params]);

        switch ($type) {
            case 'create_file': return $files->createFile($params['path'] ?? '', $params['content'] ?? '');
            case 'create_folder': return $files->createFolder($params['path'] ?? '');
            case 'organize_folder': return $files->organizeFolder($params['path'] ?? '');
            case 'move_files': return $files->moveFiles($params['from'] ?? '', $params['to'] ?? '');
            case 'delete_file': return $files->deleteFile($params['path'] ?? '');
            case 'delete_folder': return $files->deleteFolder($params['path'] ?? '');
            case 'sync_archive': return app(\App\Services\ArchiveService::class)->sync();
            default: return ['success' => false, 'error' => 'Unknown action type.'];
        }
    }
}

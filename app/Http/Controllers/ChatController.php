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
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function index()
    {
        return Inertia::render('Chat');
    }

    public function suggestions(FileOperationService $files)
    {
        $authorizedFiles = $files->listAuthorizedFiles();
        $folders = ManagedFolder::all()->map(fn($f) => [
            'type' => 'folder',
            'name' => $f->name,
            'path' => $f->path
        ]);

        $fileItems = collect($authorizedFiles)->map(fn($f) => [
            'type' => 'file',
            'name' => $f['name'],
            'path' => $f['path']
        ]);

        return response()->json([
            'items' => $folders->concat($fileItems)->values()
        ]);
    }

    public function send(Request $request, OllamaService $ollama, KnowledgeService $knowledge, FileOperationService $files)
    {
        $input = $request->input('message');
        
        // Dynamic Model Selection from Database
        $model = Setting::get('llm_model', config('services.ollama.model', 'llama3.2:1b'));
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model', 'nomic-embed-text:latest'));
        $dimensions = (int) Setting::get('embedding_dimensions', 768);

        // 1. Context: File System Registry
        $authorizedFiles = $files->listAuthorizedFiles();
        $fileContext = "ARCHIVE REGISTRY (Authorized paths only):\n";
        foreach ($authorizedFiles as $f) {
            $fileContext .= "- {$f['name']} -> {$f['path']} ({$f['type']})\n";
        }

        // 2. Intelligent Memory Recall (Deep Module)
        $relevantKnowledge = $knowledge->recall($input, 5);
        $memContext = "DIGITAL MEMORY (Relevant insights):\n";
        if (empty($relevantKnowledge)) {
            $memContext .= "- No relevant memories found.\n";
        } else {
            foreach ($relevantKnowledge as $m) {
                $memContext .= "- " . $m['content'] . "\n";
            }
        }

        // 3. System Prompt with Action Capabilities
        $actionDefinition = "
ACTION PROTOCOL:
You are the executor. To perform a task, append exactly one JSON block per action at the VERY END of your response.

Format: [ACTION:{\"type\":\"action_name\",\"params\":{}}]

Available Actions:
- create_file: {\"path\":\"@folder/subpath/file.ext\", \"content\":\"text\"}
- create_folder: {\"path\":\"@folder/new_dir\"}
- organize_folder: {\"path\":\"@folder\"}
- move_files: {\"from\":\"@folder/file.ext\", \"to\":\"@folder/new_dir/file.ext\"}
- delete_file: {\"path\":\"@folder/file.ext\"}
- delete_folder: {\"path\":\"@folder/subpath\"}
- sync_archive: {}

RULES:
1. MANDATORY: Use the '@folder' format for all paths.
2. Refer to the ARCHIVE REGISTRY above for valid @mentions.
3. If creating a file in a sub-directory, ensure the directory exists or use create_folder first.
4. Do NOT use absolute paths (/Users/...). Use @mentions only.";

        $systemPrompt = str_replace(
            ['{name}', '{role}', '{intention}', '{personality}', '{ethics}'],
            [
                config('ai.name'),
                config('ai.role'),
                config('ai.intention'),
                implode("\n- ", config('ai.personality')),
                implode("\n- ", config('ai.ethics'))
            ],
            config('ai.system_prompt')
        );

        $finalPrompt = "System: $systemPrompt\n\n$fileContext\n\n$memContext\n\n$actionDefinition\n\nUser: $input\nAssistant:";

        Log::debug("Arkhein Prompt sent to Ollama", ['input' => $input]);

        // 4. Generate response
        $response = $ollama->generate($model, $finalPrompt);
        $assistantMessage = $response['response'] ?? "I'm sorry, I couldn't generate a response.";

        Log::debug("Arkhein Raw Response", ['response' => $assistantMessage]);

        // 5. Parse Multiple Actions (Wait for User Confirmation)
        $pendingActions = [];
        if (preg_match_all('/\[ACTION:(.*?)\]/', $assistantMessage, $matches)) {
            foreach ($matches[1] as $json) {
                $actionData = json_decode($json, true);
                if ($actionData) {
                    $pendingActions[] = array_merge($actionData, ['status' => 'pending']);
                }
            }
            // Strip all action blocks from the visible message
            $assistantMessage = trim(preg_replace('/\[ACTION:.*?\]/', '', $assistantMessage));
        }

        // 6. Async Reflection & Habit Learning (Silently update memory)
        $knowledge->reflect($input, $assistantMessage);

        // 7. Save this interaction as a memory for future context
        $interactionText = "User: $input\nAssistant: $assistantMessage";
        $interactionEmbedding = $ollama->embeddings($embeddingModel, $interactionText);
        if ($interactionEmbedding) {
            app(MemoryService::class)->save(Str::uuid(), $interactionText, $interactionEmbedding, 'chat', ['type' => 'chat']);
        }

        return response()->json([
            'message' => $assistantMessage,
            'memories_used' => count($relevantKnowledge),
            'pending_actions' => $pendingActions,
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
            case 'create_file':
                return $files->createFile($params['path'] ?? '', $params['content'] ?? '');
            case 'create_folder':
                return $files->createFolder($params['path'] ?? '');
            case 'organize_folder':
                return $files->organizeFolder($params['path'] ?? '');
            case 'move_files':
                return $files->moveFiles($params['from'] ?? '', $params['to'] ?? '');
            case 'delete_file':
                return $files->deleteFile($params['path'] ?? '');
            case 'delete_folder':
                return $files->deleteFolder($params['path'] ?? '');
            case 'sync_archive':
                $results = app(\App\Services\ArchiveService::class)->sync();
                return ['success' => true, 'data' => $results];
            default:
                return ['success' => false, 'error' => 'Unknown action type.'];
        }
    }
}

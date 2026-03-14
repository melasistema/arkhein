<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Setting;
use App\Models\ManagedFolder;
use App\Services\OllamaService;
use App\Services\MemoryService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

use App\Services\FileOperationService;

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

    public function send(Request $request, OllamaService $ollama, MemoryService $memory, FileOperationService $files)
    {
        $input = $request->input('message');
        
        $model = Setting::get('llm_model', config('services.ollama.model', 'llama3.2:1b'));
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model', 'nomic-embed-text:latest'));
        $dimensions = (int) Setting::get('embedding_dimensions', 768);

        // 1. Context: Recent Files
        $authorizedFiles = $files->listAuthorizedFiles();
        $fileContext = "Available Authorized Files:\n";
        foreach (array_slice($authorizedFiles, 0, 10) as $f) {
            $fileContext .= "- {$f['name']} ({$f['path']})\n";
        }

        // 2. Memory Search
        $queryEmbedding = $ollama->embeddings($embeddingModel, $input);
        $memory->ensureIndex($dimensions);
        $similarMemories = $memory->search($queryEmbedding, 3);

        $memContext = "";
        if (!empty($similarMemories)) {
            $memContext = "Relevant memories:\n";
            foreach ($similarMemories as $m) {
                $memContext .= "- " . $m['content'] . "\n";
            }
        }

        // 3. System Prompt with Action Capabilities
        $actionDefinition = "
ACTION PROTOCOL:
You are an executor. When a file operation is required, you MUST append the action in this EXACT JSON format at the end of your response.

Format: [ACTION:{\"type\":\"action_name\",\"params\":{}}]

Available Actions:
- create_file: {\"path\":\"absolute_path\", \"content\":\"text\"}
- organize_folder: {\"path\":\"absolute_path\"}
- move_files: {\"from\":\"source_path\", \"to\":\"destination_path\"}
- delete_file: {\"path\":\"absolute_path\"}
- delete_folder: {\"path\":\"absolute_path\"}
- sync_archive: {}

Example for creating a file:
The scroll has been inscribed. [ACTION:{\"type\":\"create_file\",\"params\":{\"path\":\"/Users/vix/test.md\", \"content\":\"Inscribed content\"}}]

RULES:
1. Use ONLY the 'Available Authorized Files' paths.
2. Do NOT use placeholders. Use absolute paths.
3. If no action is needed, speak only in text.
4. You can provide multiple actions if required, each in its own [ACTION:...] block.";

        $systemPrompt = config('ai.system_prompt') . "\n\n" . $fileContext . "\n\n" . $memContext . "\n\n" . $actionDefinition;
        // ... (str_replace logic)

        // 4. Generate response
        $response = $ollama->generate($model, "System: $systemPrompt\n\nUser: $input\nAssistant:");
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

        // 6. Save memory
        $memory->save(Str::uuid(), "User: $input\nAssistant: $assistantMessage", $queryEmbedding, ['type' => 'chat']);

        return response()->json([
            'message' => $assistantMessage,
            'memories_used' => count($similarMemories),
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

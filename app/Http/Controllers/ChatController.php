<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Setting;
use App\Services\OllamaService;
use App\Services\MemoryService;
use Illuminate\Support\Str;

use App\Services\FileOperationService;

class ChatController extends Controller
{
    public function index()
    {
        return Inertia::render('Chat');
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
CRITICAL: You can execute actions by including a JSON block at the end of your response.
Format: [ACTION:{\"type\":\"action_name\",\"params\":{}}]
Available Actions:
- create_file: {\"path\":\"full_path\", \"content\":\"text\"}
- organize_folder: {\"path\":\"folder_path\"}

Example: [ACTION:{\"type\":\"create_file\",\"params\":{\"path\":\"/Users/vix/docs/note.md\", \"content\":\"Hello\"}}]";

        $systemPrompt = config('ai.system_prompt') . "\n\n" . $fileContext . "\n\n" . $memContext . "\n\n" . $actionDefinition;
        $systemPrompt = str_replace(
            ['{name}', '{role}', '{intention}', '{personality}', '{ethics}'],
            [
                config('ai.name'),
                config('ai.role'),
                config('ai.intention'),
                implode("\n- ", config('ai.personality')),
                implode("\n- ", config('ai.ethics'))
            ],
            $systemPrompt
        );

        // 4. Generate response
        $response = $ollama->generate($model, "System: $systemPrompt\n\nUser: $input\nAssistant:");
        $assistantMessage = $response['response'] ?? "I'm sorry, I couldn't generate a response.";

        // 5. Parse and Execute Actions
        $executedAction = null;
        if (preg_match('/\[ACTION:(.*?)\]/', $assistantMessage, $matches)) {
            $actionData = json_decode($matches[1], true);
            if ($actionData) {
                $executedAction = $this->executeAction($actionData, $files);
                // Clean the message for the user
                $assistantMessage = trim(preg_replace('/\[ACTION:.*?\]/', '', $assistantMessage));
                if ($executedAction['success']) {
                    $assistantMessage .= "\n\n[System: Action executed successfully]";
                } else {
                    $assistantMessage .= "\n\n[System Error: " . $executedAction['error'] . "]";
                }
            }
        }

        // 6. Save memory
        $memory->save(Str::uuid(), "User: $input\nAssistant: $assistantMessage", $queryEmbedding, ['type' => 'chat']);

        return response()->json([
            'message' => $assistantMessage,
            'memories_used' => count($similarMemories),
            'action' => $executedAction,
        ]);
    }

    protected function executeAction(array $action, FileOperationService $files): array
    {
        $type = $action['type'] ?? '';
        $params = $action['params'] ?? [];

        switch ($type) {
            case 'create_file':
                return $files->createFile($params['path'] ?? '', $params['content'] ?? '');
            case 'organize_folder':
                return $files->organizeFolder($params['path'] ?? '');
            default:
                return ['success' => false, 'error' => 'Unknown action type.'];
        }
    }
}

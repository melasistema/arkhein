<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Setting;
use App\Services\OllamaService;
use App\Services\MemoryService;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index()
    {
        return Inertia::render('Chat');
    }

    public function send(Request $request, OllamaService $ollama, MemoryService $memory)
    {
        $input = $request->input('message');
        
        $model = Setting::get('llm_model', config('services.ollama.model', 'llama3.2:1b'));
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model', 'nomic-embed-text:latest'));
        $dimensions = (int) Setting::get('embedding_dimensions', 768);

        // 1. Generate embedding for user input
        $queryEmbedding = $ollama->embeddings($embeddingModel, $input);

        // 2. Search for similar memories
        $memory->ensureIndex($dimensions);
        $similarMemories = $memory->search($queryEmbedding, 3);

        // 3. Construct prompt with context
        $context = "";
        if (!empty($similarMemories)) {
            $context = "Relevant memories:\n";
            foreach ($similarMemories as $m) {
                $context .= "- " . $m['content'] . "\n";
            }
        }

        $prompt = "Context: $context\nUser: $input\nAssistant:";
        
        // 4. Inject Persona: The Architect of the Shell
        $systemPrompt = config('ai.system_prompt');
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

        $finalPrompt = "System: $systemPrompt\n\n$prompt";

        // 5. Generate response
        $response = $ollama->generate($model, $finalPrompt);
        $assistantMessage = $response['response'] ?? "I'm sorry, I couldn't generate a response.";

        // 6. Save this interaction as a new memory
        $memory->save(Str::uuid(), "User: $input\nAssistant: $assistantMessage", $queryEmbedding, ['type' => 'chat']);

        return response()->json([
            'message' => $assistantMessage,
            'memories_used' => count($similarMemories),
        ]);
    }
}

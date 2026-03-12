<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
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
        $model = config('services.ollama.model', 'llama3');
        $embeddingModel = config('services.ollama.embedding_model', 'nomic-embed-text');

        // 1. Generate embedding for user input
        $queryEmbedding = $ollama->embeddings($embeddingModel, $input);

        // 2. Search for similar memories
        $memory->ensureIndex(count($queryEmbedding));
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
        
        // 4. Generate response
        $response = $ollama->generate($model, $prompt);
        $assistantMessage = $response['response'] ?? "I'm sorry, I couldn't generate a response.";

        // 5. Save this interaction as a new memory
        $memory->save(Str::uuid(), "User: $input\nAssistant: $assistantMessage", $queryEmbedding, ['type' => 'chat']);

        return response()->json([
            'message' => $assistantMessage,
            'memories_used' => count($similarMemories),
        ]);
    }
}

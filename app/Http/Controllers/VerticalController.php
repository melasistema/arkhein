<?php

namespace App\Http\Controllers;

use App\Models\Vertical;
use App\Models\Setting;
use App\Models\ManagedFolder;
use App\Services\OllamaService;
use App\Services\KnowledgeService;
use App\Services\ArchiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VerticalController extends Controller
{
    public function index()
    {
        return response()->json([
            'verticals' => Vertical::with('folder')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'folder_id' => 'required|exists:managed_folders,id',
            'type' => 'string',
            'settings' => 'array'
        ]);

        $vertical = Vertical::create($validated);

        return response()->json($vertical);
    }

    public function update(Request $request, Vertical $vertical)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'settings' => 'array'
        ]);

        $vertical->update($validated);

        return response()->json($vertical);
    }

    public function destroy(Vertical $vertical)
    {
        $vertical->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Trigger indexing for a specific vertical's folder.
     */
    public function sync(Vertical $vertical, ArchiveService $archive)
    {
        if (!$vertical->folder_id) {
            return response()->json(['success' => false, 'error' => 'No folder associated with this vertical.'], 400);
        }

        $folder = ManagedFolder::find($vertical->folder_id);
        $report = $archive->indexFolder($folder);

        return response()->json([
            'success' => true,
            'report' => $report
        ]);
    }

    public function query(Request $request, Vertical $vertical, OllamaService $ollama, KnowledgeService $knowledge)
    {
        $request->validate(['query' => 'required|string']);
        
        $input = $request->input('query');
        $model = Setting::get('llm_model', config('services.ollama.model'));

        \Illuminate\Support\Facades\Log::info("Arkhein RAG Debug: Starting Query", [
            'vertical_id' => $vertical->id,
            'vertical_name' => $vertical->name,
            'folder_id' => $vertical->folder_id,
            'query' => $input
        ]);

        // Check if we have ANY knowledge in the DB
        $totalKnowledge = \App\Models\Knowledge::on('nativephp')->count();
        $folderKnowledge = \App\Models\Knowledge::on('nativephp')->where('metadata->folder_id', $vertical->folder_id)->count();

        \Illuminate\Support\Facades\Log::info("Arkhein RAG Debug: DB Stats", [
            'total_knowledge_chunks' => $totalKnowledge,
            'folder_knowledge_chunks' => $folderKnowledge
        ]);

        // 1. Contextual Recall (Scoped to Vertical Folder)
        $relevantKnowledge = $knowledge->recall($input, 10, $vertical->folder_id);
        
        // DEBUG: Get raw results without threshold to see what's happening
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model'));
        $queryEmbedding = $ollama->embeddings($embeddingModel, $input);
        $rawSearch = $queryEmbedding ? app(\App\Services\MemoryService::class)->search($queryEmbedding, 5, 0) : [];

        \Illuminate\Support\Facades\Log::info("Arkhein RAG Debug: Recall Results", [
            'count' => count($relevantKnowledge),
            'samples' => collect($relevantKnowledge)->take(2)->map(fn($k) => Str::limit($k['content'], 50))
        ]);

        $context = "VERTICAL CONTEXT (Source: " . ($vertical->folder?->name ?? 'Folder') . "):\n";
        foreach ($relevantKnowledge as $m) {
            $context .= "- " . $m['content'] . "\n";
        }

        // 2. Focused System Prompt (with override support)
        $basePrompt = $vertical->settings['system_prompt'] ?? config('arkhein.vantage.prompts.rag_system');

        $systemPrompt = $basePrompt . "\n\nContext:\n" . $context;

        $finalPrompt = "System: $systemPrompt\n\nUser: $input\nAssistant:";

        // 3. Generate Focused Response
        $response = $ollama->generate($model, $finalPrompt);

        return response()->json([
            'response' => $response['response'] ?? "I couldn't analyze the documents.",
            'sources' => collect($relevantKnowledge)->map(fn($k) => [
                'filename' => $k['metadata']['filename'] ?? 'unknown',
                'path' => $k['metadata']['path'] ?? ''
            ])->unique()->values(),
            'debug' => [
                'total_db_chunks' => $totalKnowledge,
                'folder_db_chunks' => $folderKnowledge,
                'recall_count' => count($relevantKnowledge),
                'top_raw_scores' => collect($rawSearch)->map(fn($r) => ['score' => $r['score'], 'folder_id' => $r['metadata']['folder_id'] ?? null])
            ]
        ]);
    }
}

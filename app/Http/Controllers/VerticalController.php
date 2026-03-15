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
    public function sync(Vertical $vertical)
    {
        if (!$vertical->folder_id) {
            return response()->json(['success' => false, 'error' => 'No folder associated with this vertical.'], 400);
        }

        $folder = ManagedFolder::find($vertical->folder_id);
        \App\Jobs\IndexFolderJob::dispatch($folder);

        return response()->json([
            'success' => true,
            'message' => 'Indexing started in the background.'
        ]);
    }

    public function query(Request $request, Vertical $vertical, OllamaService $ollama, KnowledgeService $knowledge)
    {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));
        
        $request->validate(['query' => 'required|string']);
        
        $input = $request->input('query');
        $model = Setting::get('llm_model', config('services.ollama.model'));

        // 1. Contextual Recall (Scoped to Vertical Folder)
        $relevantKnowledge = $knowledge->recall($input, 10, $vertical->folder_id);
        
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
            ])->unique()->values()
        ]);
    }
}

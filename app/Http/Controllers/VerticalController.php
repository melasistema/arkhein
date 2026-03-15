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

    public function clearHistory(Vertical $vertical)
    {
        $vertical->interactions()->delete();
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

        // 1. Persist User Message
        $vertical->interactions()->create([
            'role' => 'user',
            'content' => $input
        ]);

        // 2. Fetch Conversation History (last 10 messages for context)
        $history = $vertical->interactions()
            ->latest()
            ->limit(11) // User msg + 10 previous
            ->get()
            ->reverse();

        $historyContext = "CONVERSATION HISTORY:\n";
        foreach ($history as $msg) {
            $historyContext .= strtoupper($msg->role) . ": " . $msg->content . "\n";
        }

        // 3. Contextual Recall (Scoped to Vertical Folder)
        $relevantKnowledge = $knowledge->recall($input, 10, $vertical->folder_id);
        
        $context = "VERTICAL CONTEXT (Source: " . ($vertical->folder?->name ?? 'Folder') . "):\n";
        foreach ($relevantKnowledge as $m) {
            $context .= "- " . $m['content'] . "\n";
        }

        // 4. Focused System Prompt
        $basePrompt = $vertical->settings['system_prompt'] ?? config('arkhein.vantage.prompts.rag_system');

        $systemPrompt = $basePrompt . "\n\n" . $context . "\n\n" . $historyContext;

        $finalPrompt = "System: $systemPrompt\n\nAssistant:";

        // 5. Generate Response
        $response = $ollama->generate($model, $finalPrompt);
        $assistantContent = $response['response'] ?? "I couldn't analyze the documents.";

        // 6. Persist Assistant Response
        $vertical->interactions()->create([
            'role' => 'assistant',
            'content' => $assistantContent,
            'metadata' => [
                'sources' => collect($relevantKnowledge)->map(fn($k) => [
                    'filename' => $k['metadata']['filename'] ?? 'unknown'
                ])->unique()->values()->all()
            ]
        ]);

        return response()->json([
            'response' => $assistantContent,
            'sources' => $vertical->interactions()->latest()->first()->metadata['sources'] ?? []
        ]);
    }
}

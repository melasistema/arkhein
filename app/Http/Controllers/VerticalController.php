<?php

namespace App\Http\Controllers;

use App\Models\Vertical;
use App\Models\Setting;
use App\Models\ManagedFolder;
use App\Services\MemoryService;
use App\Services\OllamaService;
use App\Services\RagService; // Updated
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Added for debugging

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

    public function update(Request $request, string $verticalId)
    {
        $vertical = Vertical::findOrFail($verticalId);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'settings' => 'array',
        ]);

        $vertical->update($validated);

        return response()->json($vertical);
    }

    public function destroy(string $verticalId, MemoryService $memory)
    {
        $vertical = Vertical::findOrFail($verticalId);
        $folderId = $vertical->folder_id;

        $vertical->delete();

        // If this card was the last one pointing at a folder, purge its document knowledge.
        // This keeps SQLite (SSOT) and Vektor (disposable index) free of orphan data.
        if ($folderId) {
            $stillReferenced = Vertical::where('folder_id', $folderId)->exists();

            if (! $stillReferenced) {
                $memory->purgeFolderKnowledge((int) $folderId);
            }
        }

        return response()->json(['success' => true]);
    }

    public function clearHistory(string $verticalId)
    {
        $vertical = Vertical::findOrFail($verticalId);

        $vertical->interactions()->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Trigger indexing for a specific vertical's folder.
     */
    public function sync(string $verticalId)
    {
        $vertical = Vertical::findOrFail($verticalId);

        if (! $vertical->folder_id) {
            return response()->json(['success' => false, 'error' => 'No folder associated with this vertical.'], 400);
        }

        $folder = ManagedFolder::find($vertical->folder_id);
        \App\Jobs\IndexFolderJob::dispatch($folder)->onConnection('background');

        return response()->json([
            'success' => true,
            'message' => 'Indexing started in the background.',
        ]);
    }

    public function query(Request $request, string $verticalId, OllamaService $ollama, RagService $rag) // Updated signature
    {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));
        
        // Manually retrieve the vertical
        $vertical = Vertical::findOrFail($verticalId);
        
        $request->validate(['query' => 'required|string']);
        
        $input = $request->input('query');
        $model = Setting::get('llm_model', config('services.ollama.model'));

        // 1. Persist User Message
        $vertical->interactions()->create([
            'vertical_id' => $vertical->id,
            'role' => 'user',
            'content' => $input
        ]);

        // 2. Fetch Conversation History (last 10 messages for context)
        $history = $vertical->interactions()
            ->latest()
            ->limit(11) 
            ->get()
            ->reverse();

        $historyContext = "CONVERSATION HISTORY:
";
        foreach ($history as $msg) {
            $historyContext .= strtoupper($msg->role) . ": " . $msg->content . "
";
        }

        // 3. Contextual Recall (Scoped to Vertical Folder)
        $relevantKnowledge = $rag->recall($input, 10, $vertical->folder_id);
        
        $context = "VERTICAL CONTEXT (Source: " . ($vertical->folder?->name ?? 'Folder') . "):
";
        foreach ($relevantKnowledge as $m) {
            $context .= "- " . $m['content'] . "
";
        }

        // 4. Focused System Prompt
        $basePrompt = $vertical->settings['system_prompt'] ?? config('arkhein.vantage.prompts.rag_system');

        $systemPrompt = $basePrompt . "

" . $context . "

" . $historyContext;

        $finalPrompt = "System: $systemPrompt

Assistant:";

        // 5. Generate Response (OllamaService handles config internally)
        $assistantContent = $ollama->generate($finalPrompt);

        // 6. Persist Assistant Response
        $interaction = $vertical->interactions()->create([
            'vertical_id' => $vertical->id,
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
            'sources' => $interaction->metadata['sources'] ?? []
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Vertical;
use App\Models\Setting;
use App\Models\ManagedFolder;
use App\Services\OllamaService;
use App\Services\RagService; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; 

class VerticalController extends Controller
{
    public function index()
    {
        return response()->json([
            'verticals' => Vertical::with(['folder', 'interactions' => function($q) {
                $q->latest()->limit(50);
            }])->get()
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

        // Add Artistic Greeting
        $folder = ManagedFolder::find($validated['folder_id']);
        // 2. Add Welcome Message
        $vertical->interactions()->create([
            'role' => 'assistant',
            'content' => "### 💠 Silo Connection Established: {$folder->name}\n\n" .
                "I have mapped the architecture of this authorized silo. You can now perform deep document queries or use **Magic Commands** to operate on your files.\n\n" .
                "**Quick Start:**\n" .
                "- Type `/help` to see all available magic commands.\n" .
                "- Ask: *\"Summarize the core purpose of the documents here.\"*\n" .
                "- Use `/create [filename]` to draft new files from your knowledge.\n\n" .
                "How shall we begin our analysis?"
        ]);
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

    public function destroy(string $verticalId, \App\Services\MemoryService $memory)
    {
        $vertical = Vertical::findOrFail($verticalId);
        $folderId = $vertical->folder_id;
        
        $vertical->delete();

        // If no more verticals reference this folder, we purge its knowledge
        if ($folderId) {
            $remaining = Vertical::where('folder_id', $folderId)->count();
            if ($remaining === 0) {
                $memory->purgeFolderKnowledge($folderId);
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

    public function executeAction(Request $request, string $verticalId, \App\Services\ActionService $actionService)
    {
        $vertical = Vertical::with('folder')->findOrFail($verticalId);

        $validated = $request->validate([
            'type' => 'required|string',
            'params' => 'required|array',
        ]);

        $result = $actionService->execute($validated['type'], $validated['params'], $vertical->folder);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Filesystem operation failed. Check macOS folder permissions.'
            ]);
        }

        return response()->json($result);
    }

    public function query(Request $request, string $verticalId, \App\Services\VerticalService $verticalService)
    {
        set_time_limit(config('arkhein.protocols.inference_timeout', 300));

        $vertical = Vertical::with('folder')->findOrFail($verticalId);
        $request->validate(['query' => 'required|string']);

        $input = $request->input('query');

        try {
            $result = $verticalService->ask($vertical, $input);
            
            if ($result['response'] === "Inference engine failed. Check system log.") {
                $result['response'] = "I am currently unable to reach the inference engine. Please check the system log.";
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error("Vertical Query Failed: " . $e->getMessage());
            return response()->json([
                'interaction' => null,
                'response' => "I am currently unable to reach the inference engine. Please check the system log.",
                'pending_actions' => [],
                'reasoning' => null,
                'sources' => []
            ]);
        }
    }

    public function streamQuery(Request $request, string $verticalId, \App\Services\VerticalService $verticalService)
    {
        set_time_limit(config('arkhein.protocols.inference_timeout', 300));

        $vertical = Vertical::with('folder')->findOrFail($verticalId);
        $request->validate(['query' => 'required|string']);

        $input = $request->input('query');

        return response()->stream(function () use ($vertical, $input, $verticalService) {
            $verticalService->stream($vertical, $input, function ($event, $data) {
                echo "data: " . json_encode(['event' => $event, 'data' => $data]) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            });
            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }, 200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

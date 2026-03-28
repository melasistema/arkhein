<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\ManagedFolder;
use App\Services\OllamaService;
use App\Services\MemoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Native\Laravel\Dialog;

use App\Services\ArchiveService;

class SettingsController extends Controller
{
    public function index(Request $request, OllamaService $ollama)
    {
        $models = [];
        $isOllamaOnline = true;

        try {
            $models = $ollama->tags();
        } catch (\Exception $e) {
            Log::warning("Arkhein Settings: Ollama is unreachable. " . $e->getMessage());
            $isOllamaOnline = false;
        }

        $folders = ManagedFolder::all();

        // 1. Fetch Current DB Values
        $currentLLM = Setting::get('llm_model');
        $currentEmbedding = Setting::get('embedding_model');
        $currentDimensions = Setting::get('embedding_dimensions');

        // 2. SMART DETECTION: If settings are empty (First Run), check if recommended models are in Ollama
        $recommendedLLM = config('services.ollama.model');
        $recommendedEmbedding = config('services.ollama.embedding_model');
        $recommendedDimensions = (int) config('services.ollama.embedding_dimensions');

        // SANITIZATION: If DB has the OLD default (2560) but we are now on Nomic (768), fix it
        if ((int)$currentDimensions === 2560 && $currentEmbedding === 'nomic-embed-text:latest') {
            Setting::set('embedding_dimensions', 768);
            $currentDimensions = 768;
        }

        $isOptimized = true;

        if ($isOllamaOnline && (!$currentLLM || !$currentEmbedding)) {
            $availableModelNames = collect($models)->pluck('name')->all();

            // Auto-detect LLM
            if (in_array($recommendedLLM, $availableModelNames)) {
                Setting::set('llm_model', $recommendedLLM);
                $currentLLM = $recommendedLLM;
            }

            // Auto-detect Embedding
            if (in_array($recommendedEmbedding, $availableModelNames)) {
                Setting::set('embedding_model', $recommendedEmbedding);
                Setting::set('embedding_dimensions', $recommendedDimensions);
                $currentEmbedding = $recommendedEmbedding;
                $currentDimensions = $recommendedDimensions;
            }
        }

        // 3. Determine if the current setup matches the "Optimized" Arkhein state
        $isOptimized = ($currentLLM === $recommendedLLM) && 
                      ($currentEmbedding === $recommendedEmbedding) && 
                      ((int)$currentDimensions === (int)$recommendedDimensions);

        $data = [
            'models' => $models,
            'is_ollama_online' => $isOllamaOnline,
            'folders' => $folders,
            'is_optimized' => $isOptimized,
            'reconcile' => [
                'status' => Setting::get('system_reconcile_status', 'idle'),
                'progress' => (int) Setting::get('system_reconcile_progress', 0),
                'last_at' => Setting::get('system_reconcile_last_at'),
            ],
            'recommended' => [
                'llm' => $recommendedLLM,
                'embedding' => $recommendedEmbedding,
                'dimensions' => $recommendedDimensions,
            ],
            'current' => [
                'llm_model' => $currentLLM ?? '',
                'embedding_model' => $currentEmbedding ?? '',
                'embedding_dimensions' => (int) ($currentDimensions ?? $recommendedDimensions),
            ]
        ];

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('Settings', $data);
    }

    public function sync()
    {
        $folders = ManagedFolder::all();
        
        foreach ($folders as $folder) {
            // Prefer NativePHP's background queue connection for desktop UX.
            \App\Jobs\IndexFolderJob::dispatch($folder)->onConnection('background');
        }

        return back()->with([
            'success' => "Indexing started for {$folders->count()} folders in the background.",
            'folders' => ManagedFolder::all() // Push fresh state
        ]);
    }

    public function addFolder()
    {
        $path = Dialog::new()
            ->folders()
            ->title('Authorize Folder for Arkhein')
            ->button('Authorize')
            ->open();

        if ($path) {
            $folder = ManagedFolder::updateOrCreate(
                ['path' => $path],
                ['name' => basename($path)]
            );

            // AUTO-INDEX after authorization
            \App\Jobs\IndexFolderJob::dispatch($folder)->onConnection('background');
        }

        return back();
    }

    public function removeFolder(ManagedFolder $folder, MemoryService $memory)
    {
        Log::info("Arkhein: De-authorizing folder @{$folder->name}. Purging all associated knowledge and cards.");

        // 1. Purge all Knowledge chunks from RAG index and binary Vektor
        $memory->purgeFolderKnowledge($folder->id);

        // 2. Delete all Vantage cards (Verticals) referencing this folder
        $folder->verticals()->delete();

        // 3. Remove the managed folder authority
        $folder->delete();

        return back();
    }

    public function rebuild()
    {
        // Dispatch the unbreakable batched job for Global partition
        \App\Jobs\ReconcileMemoryJob::dispatch(null)->onConnection('background');

        return response()->json(['success' => true]);
    }

    public function update(Request $request, MemoryService $memory)
    {
        $request->validate([
            'llm_model' => 'required|string',
            'embedding_model' => 'required|string',
            'embedding_dimensions' => 'required|integer|min:32|max:4096',
        ]);

        $oldModel = Setting::get('embedding_model');
        $oldDimensions = (int) Setting::get('embedding_dimensions');

        Setting::set('llm_model', $request->llm_model);
        Setting::set('embedding_model', $request->embedding_model);
        Setting::set('embedding_dimensions', $request->embedding_dimensions);

        // If embeddings changed, we MUST reset memory to avoid corruption and dimension mismatch
        if ($oldModel !== $request->embedding_model || $oldDimensions !== (int) $request->embedding_dimensions) {
            Log::info("Arkhein: Embedding settings changed. Purging existing knowledge to maintain consistency.");
            $memory->reset();
        }

        return back()->with('success', 'Settings updated successfully. Existing memory was purged to match new model dimensions.');
    }
}

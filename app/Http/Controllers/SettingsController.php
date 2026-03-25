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
        $models = $ollama->tags();
        $folders = ManagedFolder::all();

        // 1. Fetch Current DB Values
        $currentLLM = Setting::get('llm_model');
        $currentEmbedding = Setting::get('embedding_model');
        $currentDimensions = Setting::get('embedding_dimensions');

        // 2. SMART DETECTION: If settings are empty (First Run), check if recommended models are in Ollama
        $recommendedLLM = config('services.ollama.model');
        $recommendedEmbedding = config('services.ollama.embedding_model');
        $recommendedDimensions = config('services.ollama.embedding_dimensions');

        $isOptimized = true;

        if (!$currentLLM || !$currentEmbedding) {
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
            'folders' => $folders,
            'is_optimized' => $isOptimized,
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
            // Set UI state immediately; job will clear it on completion/failure.
            $folder->update(['is_indexing' => true]);

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
            ManagedFolder::updateOrCreate(
                ['path' => $path],
                ['name' => basename($path)]
            );
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

    public function rebuild(MemoryService $memory)
    {
        $dimensions = (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
        $memory->rebuildIndex($dimensions);

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

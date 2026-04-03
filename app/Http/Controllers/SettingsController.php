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

        $recommendedLLM = config('services.ollama.model', 'mistral:latest');
        $recommendedVision = config('services.ollama.vision_model', 'qwen3-vl:latest');
        $recommendedEmbedding = config('services.ollama.embedding_model', 'nomic-embed-text:latest');
        $recommendedDimensions = (int) config('services.ollama.embedding_dimensions', 768);

        // 1. Fetch Available Models for Smart Matching
        $availableNames = collect($models)->pluck('name')->all();
        $findBest = function($target) use ($availableNames) {
            if (in_array($target, $availableNames)) return $target;
            $cleanTarget = str_replace(':latest', '', $target);
            if (in_array($cleanTarget, $availableNames)) return $cleanTarget;
            if (in_array("{$cleanTarget}:latest", $availableNames)) return "{$cleanTarget}:latest";
            return $target;
        };

        // 2. Fetch Current DB Values with Smart Fallbacks
        $currentLLM = Setting::get('llm_model');
        if (!$currentLLM) {
            $currentLLM = $findBest($recommendedLLM);
        }

        $currentVision = Setting::get('vision_model');
        if (!$currentVision) {
            $currentVision = $findBest($recommendedVision);
        }

        $currentEmbedding = Setting::get('embedding_model');
        if (!$currentEmbedding) {
            $currentEmbedding = $findBest($recommendedEmbedding);
        }

        $currentDimensions = (int) Setting::get('embedding_dimensions', $recommendedDimensions);

        // 3. Determine if the current setup matches the "Optimized" Arkhein state
        $clean = fn($m) => str_replace(':latest', '', $m);
        
        $isOptimized = ($clean($currentLLM) === $clean($recommendedLLM)) && 
                      ($clean($currentEmbedding) === $clean($recommendedEmbedding)) && 
                      ($currentDimensions === $recommendedDimensions);

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
                'vision' => $recommendedVision,
                'embedding' => $recommendedEmbedding,
                'dimensions' => $recommendedDimensions,
            ],
            'current' => [
                'llm_model' => $currentLLM,
                'vision_model' => $currentVision,
                'embedding_model' => $currentEmbedding,
                'embedding_dimensions' => $currentDimensions,
            ]
        ];

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('Settings', $data);
    }

    public function toggleVisualIndexing(ManagedFolder $folder)
    {
        if ($folder->sync_status !== ManagedFolder::STATUS_IDLE) {
            return back()->withErrors(['folder' => "Cannot modify vision settings while a task is queued or running."]);
        }

        $oldValue = $folder->allow_visual_indexing;
        $newValue = !$oldValue;

        $folder->update([
            'allow_visual_indexing' => $newValue
        ]);

        // Smart Sync: Check if any images need promotion (if ON) or demotion (if OFF)
        $query = \App\Models\Document::where('folder_id', $folder->id)
            ->where('mime_type', 'like', 'image/%');

        if ($newValue) {
            $needsWork = $query->where('metadata->is_presence_only', true)->exists();
        } else {
            $needsWork = $query->where(function($q) {
                $q->whereNull('metadata->is_presence_only')
                  ->orWhere('metadata->is_presence_only', false);
            })->exists();
        }

        if ($needsWork) {
            $folder->update(['sync_status' => ManagedFolder::STATUS_QUEUED]);
            \App\Jobs\IndexFolderJob::dispatch($folder)->onConnection('background');
        }

        return back();
    }

    public function sync()
    {
        $folders = ManagedFolder::all();
        
        foreach ($folders as $folder) {
            $folder->update(['sync_status' => ManagedFolder::STATUS_QUEUED]);
            \App\Jobs\IndexFolderJob::dispatch($folder)->onConnection('background');
        }

        return back()->with([
            'success' => "Indexing tasks queued for {$folders->count()} folders.",
            'folders' => ManagedFolder::all()
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
                [
                    'name' => basename($path),
                    'sync_status' => ManagedFolder::STATUS_QUEUED
                ]
            );

            // Automatically trigger indexing for the new silo
            \App\Jobs\IndexFolderJob::dispatch($folder)->onConnection('background');
        }

        return back();
    }

    public function removeFolder(ManagedFolder $folder, MemoryService $memory)
    {
        Log::info("Arkhein: De-authorizing folder @{$folder->name}. Purging all associated knowledge and binary silos.");

        // 1. Purge all Knowledge chunks from RAG index and binary Vektor silos
        $memory->purgeFolderKnowledge($folder->id);

        // 2. Delete all Vantage cards (Verticals) referencing this folder
        $folder->verticals()->delete();

        // 3. Remove the managed folder authority
        $folder->delete();

        // 4. Trigger Global Reconciliation to sync aggregate memory
        $dimensions = (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
        $memory->rebuildGlobalIndex($dimensions);

        return back();
    }

    public function rebuild()
    {
        // Dispatch the unbreakable batched job for Global partition using background worker
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

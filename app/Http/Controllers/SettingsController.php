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
        $data = [
            'models' => $ollama->tags(),
            'folders' => ManagedFolder::all(),
            'current' => [
                'llm_model' => Setting::get('llm_model', config('services.ollama.model')),
                'embedding_model' => Setting::get('embedding_model', config('services.ollama.embedding_model')),
                'embedding_dimensions' => (int) Setting::get('embedding_dimensions', 768),
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
            $folder->update(['is_indexing' => true]);
            \App\Jobs\IndexFolderJob::dispatch($folder);
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

    public function removeFolder(ManagedFolder $folder)
    {
        $folder->delete();
        return back();
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

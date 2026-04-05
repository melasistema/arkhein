<?php

namespace App\Providers;

use Native\Laravel\Facades\Window;
use Native\Laravel\Contracts\ProvidesPhpIni;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // 1. Ensure internal storage structure exists
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/arkhein/workspaces'), 0777);

        Window::open()
            ->title('Arkhein Assistant')
            ->maximized();

        // Ensure default settings exist (Self-seeding for production/dmg)
        $this->ensureDefaults();

        // Proactive Memory Integrity Check (Non-blocking)
        dispatch(function () {
            try {
                $dim = (int) \App\Models\Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
                app(\App\Services\MemoryService::class)->ensureIndex($dim);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Arkhein: Boot-time indexing check failed: " . $e->getMessage());
            }
        })->afterResponse();
    }

    protected function ensureDefaults(): void
    {
        // 1. Fetch Target Defaults from Config (Sane Baseline)
        $targetLLM = config('services.ollama.model');
        $targetVision = config('services.ollama.vision_model');
        $targetEmbedding = config('services.ollama.embedding_model');
        $targetDimensions = config('services.ollama.embedding_dimensions');

        // 2. Try to fetch available models to pick the best version
        $availableModels = [];
        try {
            $ollama = app(\App\Services\OllamaService::class);
            $tags = $ollama->tags();
            $availableModels = collect($tags)->pluck('name')->toArray();
        } catch (\Exception $e) {
            // Ollama might be offline, we use the config literal
        }

        $findBest = function($target) use ($availableModels) {
            if (in_array($target, $availableModels)) return $target;
            $clean = str_replace(':latest', '', $target);
            if (in_array($clean, $availableModels)) return $clean;
            if (in_array("{$clean}:latest", $availableModels)) return "{$clean}:latest";
            return $target;
        };

        // 3. Blindly push them if missing.
        if (!\App\Models\Setting::get('llm_model')) {
            \App\Models\Setting::set('llm_model', $findBest($targetLLM));
        }

        if (!\App\Models\Setting::get('vision_model')) {
            \App\Models\Setting::set('vision_model', $findBest($targetVision));
        }

        if (!\App\Models\Setting::get('embedding_model')) {
            \App\Models\Setting::set('embedding_model', $findBest($targetEmbedding));
            \App\Models\Setting::set('embedding_dimensions', $targetDimensions);
        }
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}

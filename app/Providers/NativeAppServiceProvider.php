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
        $defaults = [
            'llm_model' => config('services.ollama.model'),
            'embedding_model' => config('services.ollama.embedding_model'),
            'embedding_dimensions' => config('services.ollama.embedding_dimensions'),
        ];

        foreach ($defaults as $key => $value) {
            if (!\App\Models\Setting::get($key)) {
                \App\Models\Setting::set($key, $value);
            }
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

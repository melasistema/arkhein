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

        // Proactive Memory Integrity Check
        try {
            $dim = (int) \App\Models\Setting::on('nativephp')->find('embedding_dimensions')?->value ?? 768;
            app(\App\Services\MemoryService::class)->ensureIndex($dim);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Arkhein: Boot-time indexing failed: " . $e->getMessage());
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

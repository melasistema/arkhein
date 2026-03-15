<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MemoryService;
use App\Models\Setting;

class RebuildMemory extends Command
{
    protected $signature = 'memory:rebuild';
    protected $description = 'Rebuild the Vektor binary index from the SQLite Source of Truth';

    public function handle(MemoryService $memory)
    {
        // Use find() specifically to get the model on the right connection
        $dimSetting = \App\Models\Setting::on('nativephp')->find('embedding_dimensions');
        $modelSetting = \App\Models\Setting::on('nativephp')->find('embedding_model');

        $dimensions = (int) ($dimSetting?->value ?? config('services.ollama.embedding_dimensions'));
        $model = $modelSetting?->value ?? 'default';
        
        $this->info("Starting memory rebuild using SSOT (nativephp.sqlite)...");
        $this->warn("Model: $model");
        $this->warn("Dimensions: $dimensions");

        if ($memory->rebuildIndex($dimensions)) {
            $this->info("✔ Vektor index rebuilt successfully.");
        } else {
            $this->error("✘ Rebuild failed.");
        }
    }
}

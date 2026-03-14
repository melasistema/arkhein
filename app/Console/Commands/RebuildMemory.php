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
        $dimensions = (int) Setting::get('embedding_dimensions', 768);
        
        $this->info("Starting memory rebuild using SSOT (SQLite)...");
        $this->warn("Model: " . Setting::get('embedding_model', 'default'));
        $this->warn("Dimensions: $dimensions");

        if ($memory->rebuildIndex($dimensions)) {
            $this->info("✔ Vektor index rebuilt successfully.");
        } else {
            $this->error("✘ Rebuild failed.");
        }
    }
}

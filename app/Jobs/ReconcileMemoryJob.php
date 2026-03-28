<?php

namespace App\Jobs;

use App\Models\Knowledge;
use App\Models\Setting;
use App\Services\MemoryService;
use Centamiv\Vektor\Services\Indexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReconcileMemoryJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ?int $folderId = null,
        protected int $offset = 0,
        protected int $batchSize = 500,
        protected ?int $total = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MemoryService $memory): void
    {
        $dimensions = (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
        
        // 1. First Run: Initialize Shadow Index
        if ($this->offset === 0) {
            Log::info("Arkhein Reconcile: Initializing shadow rebuild for partition [" . ($this->folderId ?? 'global') . "]");
            $memory->prepareShadow($this->folderId);
            
            $query = Knowledge::on('nativephp');
            if ($this->folderId) {
                $query->where('metadata->folder_id', $this->folderId);
            }
            
            $this->total = $query->count();
            Log::info("Arkhein Reconcile: Total items to process: " . $this->total);
            
            Setting::set('system_reconcile_progress', 0);
            Setting::set('system_reconcile_status', 'running');
        }

        // 2. Set Memory to Shadow Mode
        $memory->setPartition($this->folderId, true);
        $indexer = new Indexer();

        // 3. Process Batch
        $query = Knowledge::on('nativephp');
        if ($this->folderId) {
            $query->where('metadata->folder_id', $this->folderId);
        }
        
        $items = $query->orderBy('created_at', 'asc')
            ->offset($this->offset)
            ->limit($this->batchSize)
            ->get();

        Log::info("Arkhein Reconcile: Processing batch [" . ($this->offset) . " to " . ($this->offset + $items->count()) . "]");

        foreach ($items as $item) {
            $embedding = $item->embedding;
            if (is_string($embedding)) $embedding = json_decode($embedding, true);
            if (!is_array($embedding) || count($embedding) !== $dimensions) continue;

            try {
                $indexer->insert($item->id, $embedding);
            } catch (\Throwable $e) {
                // Silently skip corrupted items in binary index during reconcile
            }
        }

        $processed = $this->offset + $items->count();
        $progress = ($this->total > 0) ? (int) (($processed / $this->total) * 100) : 100;
        
        Setting::set('system_reconcile_progress', $progress);

        // 4. Chain next batch or finalize
        if ($processed < $this->total && $items->count() > 0) {
            dispatch(new self($this->folderId, $processed, $this->batchSize, $this->total))
                ->onConnection('background');
        } else {
            Log::info("Arkhein Reconcile: Swapping shadow index for partition [" . ($this->folderId ?? 'global') . "]");
            $memory->swapShadow($this->folderId);
            Setting::set('system_reconcile_progress', 100);
            Setting::set('system_reconcile_status', 'idle');
            Setting::set('system_reconcile_last_at', now()->toDateTimeString());
        }
    }
}

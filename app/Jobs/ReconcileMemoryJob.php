<?php

namespace App\Jobs;

use App\Models\Knowledge;
use App\Models\Setting;
use App\Services\MemoryService;
use Centamiv\Vektor\Services\Indexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Native\Laravel\Facades\Notification;

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
            
            // Use query builder for maximum connection stability in background
            $query = \Illuminate\Support\Facades\DB::connection('nativephp')->table('knowledge');
            if ($this->folderId) {
                $query->where('metadata', 'LIKE', '%"folder_id":' . $this->folderId . '%');
            }
            
            $this->total = $query->count();
            Log::info("Arkhein Reconcile: Found total items in SSOT: " . $this->total);
            
            Setting::set('system_reconcile_progress', 0);
            Setting::set('system_reconcile_status', 'running');
        }

        // 2. Process Batch within the Shadow Scope
        $memory->withScope($this->folderId, function() use ($memory, $dimensions) {
            \Centamiv\Vektor\Core\Config::setDimensions($dimensions);
            $indexer = new Indexer();

            // Fetch items
            $query = \Illuminate\Support\Facades\DB::connection('nativephp')->table('knowledge');
            if ($this->folderId) {
                $query->where('metadata', 'LIKE', '%"folder_id":' . $this->folderId . '%');
            }
            
            $items = $query->orderBy('created_at', 'asc')
                ->offset($this->offset)
                ->limit($this->batchSize)
                ->get();

            Log::info("Arkhein Reconcile: Fetched " . $items->count() . " items for batch starting at " . $this->offset);

            $inserted = 0;
            $skipped = 0;

            foreach ($items as $item) {
                $embedding = $item->embedding;
                if (is_string($embedding)) $embedding = json_decode($embedding, true);
                
                if (!is_array($embedding) || count($embedding) !== $dimensions) {
                    $skipped++;
                    continue;
                }

                try {
                    $indexer->insert($item->id, $embedding);
                    $inserted++;
                } catch (\Throwable $e) {
                    // Skip
                }
            }

            Log::info("Arkhein Reconcile: Batch Result -> Inserted: {$inserted}, Skipped: {$skipped}");

            $processed = $this->offset + $items->count();
            $progress = ($this->total > 0) ? (int) (($processed / $this->total) * 100) : 100;
            
            Setting::set('system_reconcile_progress', $progress);

            // 3. Chain next batch or finalize
            if ($processed < $this->total && $items->count() > 0) {
                Log::info("Arkhein Reconcile: Dispatching next batch at offset {$processed}");
                dispatch(new self($this->folderId, $processed, $this->batchSize, $this->total))
                    ->onConnection('background');
            } else {
                Log::info("Arkhein Reconcile: Swapping shadow index for partition [" . ($this->folderId ?? 'global') . "]");
                $memory->swapShadow($this->folderId);
                Setting::set('system_reconcile_progress', 100);
                Setting::set('system_reconcile_status', 'idle');
                Setting::set('system_reconcile_last_at', now()->toDateTimeString());

                Notification::new()
                    ->title('Memory Reconciled')
                    ->message('The aggregate vector index has been optimized and synchronized.')
                    ->show();
            }
        }, true); // TRUE: Use shadow index
    }
}

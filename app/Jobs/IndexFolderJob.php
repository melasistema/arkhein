<?php

namespace App\Jobs;

use App\Models\ManagedFolder;
use App\Services\ArchiveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Native\Laravel\Facades\Notification;

class IndexFolderJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ManagedFolder $folder
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ArchiveService $archive): void
    {
        Log::info("Arkhein: Starting background indexing for folder: {$this->folder->name}");
        
        $this->folder->update(['is_indexing' => true]);

        try {
            $report = $archive->indexFolder($this->folder);
            
            $this->folder->update([
                'last_indexed_at' => now(),
                'is_indexing' => false
            ]);
            
            Notification::new()
                ->title('Archive Sync Complete')
                ->message("Finished indexing @{$this->folder->name}. {$report['files']} files processed.")
                ->show();

            Log::info("Arkhein: Finished indexing folder: {$this->folder->name}", $report);
        } catch (\Exception $e) {
            $this->folder->update(['is_indexing' => false]);
            Log::error("Arkhein: Indexing job failed for {$this->folder->name}: " . $e->getMessage());
            throw $e;
        }
    }
}

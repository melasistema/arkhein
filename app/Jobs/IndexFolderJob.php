<?php

namespace App\Jobs;

use App\Models\ManagedFolder;
use App\Services\ArchiveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Native\Laravel\Facades\Notification;

class IndexFolderJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 3600;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ManagedFolder $folder
    ) {}

    /**
     * Execute the job.
     **/
     public function handle(ArchiveService $archive, \App\Services\MemoryService $memory): void
     {
         // SQLite + Vektor are not concurrency-friendly. We serialize indexing runs
         // to avoid lock contention and rebuild races.
         $this->withIndexLock(function () use ($archive, $memory) {
             Log::info("Arkhein: Starting background indexing for folder: {$this->folder->name}");

             $this->folder->update(['is_indexing' => true]);

             try {
                 $report = $archive->indexFolder($this->folder);

                 // Final Global Reconciliation to ensure aggregate index is in sync
                 $dimensions = (int) \App\Models\Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
                 $memory->rebuildGlobalIndex($dimensions);

                 $this->folder->update([
                     'last_indexed_at' => now(),
                     'is_indexing' => false,
                 ]);

                 Notification::new()
                    ->title('Archive Sync Complete')
                    ->message("Finished indexing @{$this->folder->name}. {$report['files']} files processed.")
                    ->show();

                Log::info("Arkhein: Finished indexing folder: {$this->folder->name}", $report);
            } catch (\Throwable $e) {
                $this->folder->update(['is_indexing' => false]);
                Log::error("Arkhein: Indexing job failed for {$this->folder->name}: " . $e->getMessage());
                throw $e;
            }
        });
    }

    protected function withIndexLock(callable $callback): void
    {
        $dir = storage_path('app/arkhein');
        File::ensureDirectoryExists($dir);

        $lockPath = $dir . DIRECTORY_SEPARATOR . 'indexing.lock';
        $handle = fopen($lockPath, 'c');

        if ($handle === false) {
            throw new \RuntimeException('Failed to create/open indexing lock file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Failed to acquire indexing lock.');
            }

            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

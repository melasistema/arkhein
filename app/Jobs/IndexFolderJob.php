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
        protected ManagedFolder $folder,
        protected ?string $taskId = null
    ) {}

    /**
     * Execute the job.
     **/
     public function handle(ArchiveService $archive, \App\Services\MemoryService $memory): void
     {
         // SQLite + Vektor are not concurrency-friendly. We serialize indexing runs
         // to avoid lock contention and rebuild races.
         $archive->withIndexLock(function () use ($archive, $memory) {
             Log::info("Arkhein: Starting background indexing for folder: {$this->folder->name}");
             $task = $this->taskId ? \App\Models\SystemTask::find($this->taskId) : null;

             if ($task) {
                 $task->update([
                     'status' => \App\Models\SystemTask::STATUS_RUNNING,
                     'started_at' => now()
                 ]);
             }

             $this->folder->update([
                 'sync_status' => ManagedFolder::STATUS_INDEXING,
                 'is_indexing' => true
             ]);

             try {
                 $report = $archive->indexFolder($this->folder, false, $task);

                 // Level 0 Grounding: Scan the environment
                 app(\App\Services\EnvironmentScanner::class)->scan($this->folder);

                 $this->folder->update([
                     'last_indexed_at' => now(),
                     'sync_status' => ManagedFolder::STATUS_IDLE,
                     'is_indexing' => false,
                 ]);

                 if ($task) {
                     $task->update([
                         'status' => \App\Models\SystemTask::STATUS_COMPLETED,
                         'completed_at' => now(),
                         'progress' => 100,
                         'description' => "Indexed {$report['files']} files"
                     ]);
                 }

                 Notification::new()
                    ->title('Archive Sync Complete')
                    ->message("Finished indexing @{$this->folder->name}. {$report['files']} files processed.")
                    ->show();

                Log::info("Arkhein: Finished indexing folder: {$this->folder->name}", $report);
            } catch (\Throwable $e) {
                $this->folder->update([
                    'sync_status' => ManagedFolder::STATUS_IDLE,
                    'is_indexing' => false
                ]);
                if ($task) {
                    $task->update([
                        'status' => \App\Models\SystemTask::STATUS_FAILED,
                        'description' => "Error: " . $e->getMessage()
                    ]);
                }
                Log::error("Arkhein: Indexing job failed for {$this->folder->name}: " . $e->getMessage());
                throw $e;
            }
        });
    }
}

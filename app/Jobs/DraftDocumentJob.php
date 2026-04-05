<?php

namespace App\Jobs;

use App\Models\ManagedFolder;
use App\Services\FileArchitectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Native\Laravel\Facades\Notification;

class DraftDocumentJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600; // 10 minutes
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ManagedFolder $folder,
        protected string $path,
        protected string $instruction,
        protected ?string $taskId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(FileArchitectService $architect): void
    {
        Log::info("Arkhein Architect: Background job started", ['file' => $this->path]);
        $task = $this->taskId ? \App\Models\SystemTask::find($this->taskId) : null;

        try {
            // 1. Set Status
            $this->folder->update(['sync_status' => 'drafting']);
            if ($task) {
                $task->update([
                    'status' => \App\Models\SystemTask::STATUS_RUNNING,
                    'started_at' => now()
                ]);
            }

            // 2. Run the Assembly Pipeline
            $content = $architect->assemble($this->folder, $this->instruction, function($progress) use ($task) {
                $this->folder->update(['current_indexing_file' => $progress]);
                if ($task) {
                    $task->update(['description' => $progress]);
                }
            });

            // 3. Save the File
            $absolutePath = rtrim($this->folder->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($this->path, DIRECTORY_SEPARATOR);
            file_put_contents($absolutePath, $content);

            // 4. Index the new file immediately
            app(\App\Services\ArchiveService::class)->indexFile($this->folder, $absolutePath);

            // 5. Finalize
            $this->folder->update([
                'sync_status' => \App\Models\ManagedFolder::STATUS_IDLE,
                'current_indexing_file' => null
            ]);

            if ($task) {
                $task->update([
                    'status' => \App\Models\SystemTask::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'progress' => 100,
                    'description' => "Drafted '{$this->path}'"
                ]);
            }

            Notification::new()
                ->title('Document Assembled')
                ->message("The file '{$this->path}' has been successfully drafted and indexed.")
                ->show();

            Log::info("Arkhein Architect: Background job completed", ['file' => $this->path]);

        } catch (\Throwable $e) {
            $this->folder->update(['sync_status' => \App\Models\ManagedFolder::STATUS_IDLE]);
            if ($task) {
                $task->update([
                    'status' => \App\Models\SystemTask::STATUS_FAILED,
                    'description' => "Error: " . $e->getMessage()
                ]);
            }
            Log::error("Arkhein Architect: Background job failed", ['msg' => $e->getMessage()]);
            throw $e;
        }
    }
}

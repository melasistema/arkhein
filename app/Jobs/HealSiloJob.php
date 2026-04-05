<?php

namespace App\Jobs;

use App\Models\ManagedFolder;
use App\Models\Document;
use App\Models\SystemTask;
use App\Services\ArchiveService;
use App\Services\SiloIntegrityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class HealSiloJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600;
    public $tries = 1;

    public function __construct(
        protected ManagedFolder $folder,
        protected ?string $taskId = null
    ) {}

    public function handle(ArchiveService $archive, SiloIntegrityService $integrity): void
    {
        Log::info("Arkhein Integrity: Healing silo @{$this->folder->name}");
        $task = $this->taskId ? SystemTask::find($this->taskId) : null;

        if ($task) {
            $task->update(['status' => SystemTask::STATUS_RUNNING, 'started_at' => now()]);
        }

        $this->folder->update(['sync_status' => ManagedFolder::STATUS_INDEXING]);

        try {
            // 1. Identify Ghost Documents (In DB, not on disk)
            $dbPaths = Document::where('folder_id', $this->folder->id)->pluck('path')->all();
            $removedCount = 0;

            foreach ($dbPaths as $path) {
                $absPath = rtrim($this->folder->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
                if (!File::exists($absPath)) {
                    Log::info("Arkhein Integrity: Purging ghost document: {$path}");
                    Document::where('folder_id', $this->folder->id)->where('path', $path)->delete();
                    $removedCount++;
                }
            }

            // 2. Standard Sync (This will ingest new files)
            $report = $archive->indexFolder($this->folder, false, $task);

            if ($task) {
                $task->update([
                    'status' => SystemTask::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'progress' => 100,
                    'description' => "Healed: {$removedCount} purged, {$report['files']} new arrivals."
                ]);
            }

            $this->folder->update([
                'sync_status' => ManagedFolder::STATUS_IDLE,
                'disk_signature' => $integrity->computeSignature($this->folder->path)
            ]);

        } catch (\Throwable $e) {
            $this->folder->update(['sync_status' => ManagedFolder::STATUS_IDLE]);
            if ($task) $task->update(['status' => SystemTask::STATUS_FAILED, 'description' => $e->getMessage()]);
            throw $e;
        }
    }
}

<?php

namespace App\Services;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SiloIntegrityService
{
    /**
     * Compute a lightweight signature of the folder's physical state.
     */
    public function computeSignature(string $path): ?string
    {
        if (!File::isDirectory($path)) return null;

        // Signature = Total Files + Last Modified Time of the root folder
        // This is extremely fast even for large folders.
        $files = File::allFiles($path);
        $count = count($files);
        $mtime = filemtime($path);

        return md5("{$count}|{$mtime}");
    }

    /**
     * Check all authorized folders for drift and mark them as stale if needed.
     */
    public function checkAll(): array
    {
        $folders = ManagedFolder::all();
        $drifted = [];

        foreach ($folders as $folder) {
            // Skip folders that are already doing something
            if ($folder->sync_status !== ManagedFolder::STATUS_IDLE) continue;

            $currentSignature = $this->computeSignature($folder->path);
            
            if ($currentSignature && $currentSignature !== $folder->disk_signature) {
                Log::info("Arkhein Integrity: Drift detected for @{$folder->name}. Launching Self-Heal.");
                
                $task = \App\Models\SystemTask::createInSilo(
                    $folder->id,
                    'sync',
                    "Self-Healing: @{$folder->name}"
                );

                \App\Jobs\HealSiloJob::dispatch($folder, $task->id)->onConnection('background');
                
                // Temporarily mark as stale so heartbeat knows it's pending a job pick-up
                $folder->update(['sync_status' => ManagedFolder::STATUS_STALE]);
                $drifted[] = $folder;
            }
        }

        return $drifted;
    }
}

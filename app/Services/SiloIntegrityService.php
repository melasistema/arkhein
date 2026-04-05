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

        $start = microtime(true);

        // Signature = Total Files + Last Modified Time of the root folder
        $files = File::allFiles($path);
        $count = count($files);
        $mtime = filemtime($path);

        $duration = microtime(true) - $start;
        if ($duration > 0.5) {
            Log::warning("Arkhein Integrity: Slow disk scan for {$path} (" . number_format($duration, 2) . "s)");
        }

        return md5("{$count}|{$mtime}");
    }

    /**
     * Check all authorized folders for drift and mark them as stale if needed.
     * Throttled to run at most once every 60 seconds per silo.
     */
    public function checkAll(): array
    {
        $folders = ManagedFolder::all();
        $drifted = [];

        foreach ($folders as $folder) {
            // 1. Skip folders that are already busy
            if ($folder->sync_status !== ManagedFolder::STATUS_IDLE) continue;

            // 2. Throttle: Only check disk integrity once every 60 seconds
            $cacheKey = "integrity_check_{$folder->id}";
            if (\Illuminate\Support\Facades\Cache::has($cacheKey)) continue;

            $currentSignature = $this->computeSignature($folder->path);
            
            // Set throttle lock
            \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addSeconds(60));

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

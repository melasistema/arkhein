<?php

namespace App\Services\Commands;

use App\Models\Vertical;
use App\Models\SystemTask;

class SyncCommand implements MagicCommandInterface
{
    public static function getHandlesIntent(): string
    {
        return 'COMMAND_SYNC';
    }

    public function execute(Vertical $vertical, string $query, array $perception, array $currentFiles, SystemTask $task): array
    {
        if ($vertical->folder) {
            $task->update(['type' => 'sync', 'description' => "Re-indexing silo @{$vertical->folder->name}"]);
            \App\Jobs\IndexFolderJob::dispatch($vertical->folder, $task->id)->onConnection('background');
            
            return [
                'response' => "Re-indexing started for **{$vertical->folder->name}**. You can monitor the progress in the system monitor.",
                'actions' => [],
                'reasoning' => null
            ];
        }

        $task->update(['status' => SystemTask::STATUS_FAILED]);
        
        return [
            'response' => "No folder associated with this vertical to sync.",
            'actions' => [],
            'reasoning' => null
        ];
    }
}
<?php

namespace App\Services\Commands;

use App\Models\Vertical;
use App\Models\SystemTask;

class HelpCommand implements MagicCommandInterface
{
    public static function getHandlesIntent(): string
    {
        return 'COMMAND_HELP';
    }

    public function execute(Vertical $vertical, string $query, array $perception, array $currentFiles, SystemTask $task): array
    {
        $response = "### 🪄 Arkhein Magic Commands\n\n" .
            "- `/create [filename]` : Create a new file (uses recent context for content).\n" .
            "- `/move [file] [folder]` : Move a file to a subfolder.\n" .
            "- `/organize` : Automatically group files by their extension or theme.\n" .
            "- `/delete [filename]` : Remove a file from the authorized folder.\n\n" .
            "You can follow commands with natural language, e.g., `/create a summary file called news.md`.";
            
        $task->update([
            'status' => SystemTask::STATUS_COMPLETED, 
            'progress' => 100, 
            'description' => 'Help displayed'
        ]);

        return [
            'response' => $response,
            'actions' => [],
            'reasoning' => null
        ];
    }
}
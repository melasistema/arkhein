<?php

namespace App\Services\Commands;

use App\Models\Vertical;
use App\Models\SystemTask;

interface MagicCommandInterface
{
    /**
     * The intent(s) this command handles (e.g., 'COMMAND_CREATE' or ['COMMAND_CREATE', 'COMMAND_MOVE']).
     *
     * @return string|array<string>
     */
    public static function getHandlesIntent(): string|array;

    /**
     * Execute the command logic and return the result.
     *
     * @return array{response: string, actions: array, reasoning: ?string}
     */
    public function execute(Vertical $vertical, string $query, array $perception, array $currentFiles, SystemTask $task): array;
}
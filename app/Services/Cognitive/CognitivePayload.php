<?php

namespace App\Services\Cognitive;

use App\Models\ManagedFolder;
use App\Models\SystemTask;

class CognitivePayload
{
    public string $query;
    public int $folderId;
    public ?ManagedFolder $folder;
    public ?SystemTask $task;
    
    public string $manifest = '';
    public array $perception = [];
    public string $context = '';
    public array|string $plan = [];
    public string $scratchpad = '';
    public string $verified = '';
    public ?string $finalResult = null;

    public function __construct(string $query, int $folderId, ?SystemTask $task = null)
    {
        $this->query = $query;
        $this->folderId = $folderId;
        $this->task = $task;
    }
}
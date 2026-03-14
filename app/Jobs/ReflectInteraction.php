<?php

namespace App\Jobs;

use App\Services\KnowledgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReflectInteraction implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $userMessage,
        protected string $assistantResponse
    ) {}

    /**
     * Execute the job.
     */
    public function handle(KnowledgeService $knowledge): void
    {
        $knowledge->reflect($this->userMessage, $this->assistantResponse);
    }
}

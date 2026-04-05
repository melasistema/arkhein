<?php

namespace App\Services\Commands;

use Illuminate\Support\Facades\Log;

class CommandRegistry
{
    /** @var array<string, MagicCommandInterface> */
    protected array $commands = [];

    public function __construct(iterable $commands = [])
    {
        foreach ($commands as $command) {
            $this->register($command);
        }
    }

    public function register(MagicCommandInterface $command): void
    {
        $intents = $command::getHandlesIntent();
        
        if (is_string($intents)) {
            $intents = [$intents];
        }

        foreach ($intents as $intent) {
            $this->commands[$intent] = $command;
            Log::debug("Arkhein CommandRegistry: Registered {$intent}");
        }
    }

    public function get(string $intent): ?MagicCommandInterface
    {
        return $this->commands[$intent] ?? null;
    }

    public function has(string $intent): bool
    {
        return isset($this->commands[$intent]);
    }
}
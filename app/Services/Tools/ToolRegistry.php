<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\Log;

class ToolRegistry
{
    protected array $tools = [];

    public function __construct()
    {
        $this->register(new CreateFileTool());
        $this->register(new CreateFolderTool());
        $this->register(new MoveFileTool());
        $this->register(new DeleteFileTool());
        $this->register(new InventoryTool());
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Get all tool definitions as a simplified array for LLM prompts.
     */
    public function getDefinitions(): array
    {
        return collect($this->tools)->map(fn($t) => [
            'name' => $t->getName(),
            'description' => $t->getDescription(),
            'parameters' => $t->getSchema()
        ])->values()->toArray();
    }
}

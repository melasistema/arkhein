<?php

namespace App\Services;

use App\Models\ManagedFolder;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;

class ActionService
{
    public function __construct(protected ToolRegistry $registry) {}

    /**
     * Propose an action using the dedicated tool.
     */
    public function propose(string $type, array $params): array
    {
        $tool = $this->registry->get($type);
        
        return [
            'id' => uniqid('action_'),
            'type' => $type,
            'params' => $params,
            'status' => 'pending',
            'description' => $tool ? $tool->describeAction($params) : "Unknown action: {$type}"
        ];
    }

    /**
     * Execute a confirmed action via the Tool Registry.
     */
    public function execute(string $type, array $params, ?ManagedFolder $folder = null): array
    {
        $tool = $this->registry->get($type);

        if (!$tool) {
            Log::warning("ActionService: Tool not found: {$type}");
            return ['success' => false, 'error' => "Tool '{$type}' not supported."];
        }

        return $tool->execute($params, $folder);
    }

    /**
     * Bridge method for the legacy/human-friendly describe calls.
     */
    public function describe(string $type, array $params): string
    {
        $tool = $this->registry->get($type);
        return $tool ? $tool->describeAction($params) : "Unknown action";
    }

    /**
     * Expose tool definitions for the Extractor/LLM.
     */
    public function getToolDefinitions(): array
    {
        return $this->registry->getDefinitions();
    }
}

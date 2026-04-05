<?php

namespace App\Services\Tools;

use App\Models\ManagedFolder;

interface ToolInterface
{
    /**
     * The unique name of the tool (e.g., 'create_file').
     */
    public function getName(): string;

    /**
     * A clear description for the LLM to understand when to use this tool.
     */
    public function getDescription(): string;

    /**
     * The JSON Schema for the parameters this tool accepts.
     */
    public function getSchema(): array;

    /**
     * Determine if this tool requires explicit operator consent before execution.
     */
    public function requiresOperatorConsent(): bool;

    /**
     * Execute the tool logic.
     */
    public function execute(array $params, ?\App\Models\ManagedFolder $folder = null): array;

    /**
     * Return a human-friendly description of a specific planned action.
     */
    public function describeAction(array $params): string;
}

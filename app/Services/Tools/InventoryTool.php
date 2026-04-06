<?php

namespace App\Services\Tools;

use App\Models\Document;
use App\Models\ManagedFolder;
use Illuminate\Support\Facades\Log;

class InventoryTool extends AbstractTool
{
    public function getName(): string
    {
        return 'silo_inventory';
    }

    public function getDescription(): string
    {
        return "Get a complete and accurate list of files in the current silo. Use this for 'how many' or 'list all' requests.";
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Optional glob-style pattern to filter files (e.g. MR-*)'
                ],
                'group_by' => [
                    'type' => 'string',
                    'enum' => ['folder', 'none'],
                    'description' => 'How to organize the results.'
                ]
            ]
        ];
    }

    public function requiresOperatorConsent(): bool
    {
        return false; // Safe read-only tool
    }

    public function execute(array $params, ?ManagedFolder $folder = null): array
    {
        if (!$folder) {
            return ['success' => false, 'error' => 'No folder context provided.'];
        }

        $query = Document::where('folder_id', $folder->id);

        if (!empty($params['pattern'])) {
            $sqlPattern = str_replace('*', '%', $params['pattern']);
            $query->where('path', 'LIKE', $sqlPattern);
        }

        $docs = $query->get(['path', 'summary', 'metadata']);

        if ($docs->isEmpty()) {
            return [
                'success' => true,
                'data' => [],
                'message' => "No files found matching the criteria in @{$folder->name}."
            ];
        }

        $list = $docs->map(function($d) {
            $type = $d->metadata['perception']['document_type'] ?? 'Unknown';
            return "- [{$type}] {$d->path}" . ($d->summary ? " (Summary: {$d->summary})" : "");
        })->toArray();

        return [
            'success' => true,
            'data' => $list,
            'count' => $docs->count(),
            'message' => "I found {$docs->count()} matching files in the @{$folder->name} silo."
        ];
    }

    public function describeAction(array $params): string
    {
        return "Performing structural inventory of the silo.";
    }
}

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
        return "Get a complete and accurate list of files across one or all silos. Use this for 'how many' or 'list all' requests.";
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
                'folder_id' => [
                    'type' => 'integer',
                    'description' => 'Optional ID of a specific silo to target.'
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
        $folderId = $params['folder_id'] ?? ($folder ? $folder->id : null);
        
        $query = Document::query();

        if ($folderId) {
            $query->where('folder_id', $folderId);
        }

        if (!empty($params['pattern'])) {
            $sqlPattern = str_replace('*', '%', $params['pattern']);
            $query->where('path', 'LIKE', $sqlPattern);
        }

        $docs = $query->get(['path', 'summary', 'metadata', 'folder_id']);

        if ($docs->isEmpty()) {
            $target = $folderId ? "silo [ID: {$folderId}]" : "all silos";
            return [
                'success' => true,
                'data' => [],
                'message' => "No files found matching the criteria in {$target}."
            ];
        }

        $list = $docs->map(function($d) use ($folderId) {
            $type = $d->metadata['perception']['document_type'] ?? 'Unknown';
            $siloInfo = !$folderId ? " [Silo ID: {$d->folder_id}]" : "";
            return "- [{$type}] {$d->path}{$siloInfo}" . ($d->summary ? " (Summary: {$d->summary})" : "");
        })->toArray();

        return [
            'success' => true,
            'data' => $list,
            'count' => $docs->count(),
            'message' => "I found {$docs->count()} matching files."
        ];
    }

    public function describeAction(array $params): string
    {
        return "Performing structural inventory of the silos.";
    }
}

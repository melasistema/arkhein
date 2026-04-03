<?php

namespace App\Services\Extractors;

use App\ValueObjects\MediaResult;
use Illuminate\Support\Facades\File;

class PresenceProcessor implements MediaProcessorInterface
{
    /**
     * This is a catch-all processor.
     */
    public function supports(string $extension, string $mimeType): bool
    {
        return true;
    }

    public function process(string $path): MediaResult
    {
        $filename = basename($path);
        $size = $this->formatBytes(File::size($path));
        
        // Presence only: No deep content, just a searchable metadata fragment.
        return new MediaResult(
            content: "File present: {$filename} ({$size}). No deep indexing performed.",
            summary: "Placeholder for {$filename}",
            metadata: [
                'file_size_human' => $size,
                'is_presence_only' => true
            ],
            type: 'presence'
        );
    }

    protected function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

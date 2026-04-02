<?php

namespace App\Services\Extractors;

use App\ValueObjects\MediaResult;

interface MediaProcessorInterface
{
    /**
     * Check if this processor supports the given file and mime type.
     */
    public function supports(string $extension, string $mimeType): bool;

    /**
     * Process the media file and return a structured result.
     */
    public function process(string $path): MediaResult;
}

<?php

namespace App\Services\Extractors;

interface ExtractorInterface
{
    /**
     * Check if this extractor supports the given file extension.
     */
    public function supports(string $extension): bool;

    /**
     * Extract text content from the file.
     */
    public function extract(string $path): string;
}

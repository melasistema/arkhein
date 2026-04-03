<?php

namespace App\Services\Extractors\Splitters;

interface SplitterInterface
{
    /**
     * Split text into chunks based on semantic or structural boundaries.
     */
    public function split(string $text, int $maxSize): array;
}

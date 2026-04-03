<?php

namespace App\Services\Extractors\Splitters;

class MarkdownSplitter implements SplitterInterface
{
    public function split(string $text, int $maxSize): array
    {
        // First pass: Split by headers
        $patterns = [
            "/^#\s+/m",    // H1
            "/^##\s+/m",   // H2
            "/^###\s+/m",  // H3
        ];

        $chunks = [$text];

        foreach ($patterns as $pattern) {
            $newChunks = [];
            foreach ($chunks as $chunk) {
                if (mb_strlen($chunk) <= $maxSize) {
                    $newChunks[] = $chunk;
                    continue;
                }

                $parts = preg_split($pattern, $chunk, -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($parts as $part) {
                    if (trim($part)) {
                        $newChunks[] = trim($part);
                    }
                }
            }
            $chunks = $newChunks;
        }

        // Final pass: Use StandardSplitter for any chunks still too large
        $standard = new StandardSplitter();
        $finalChunks = [];
        foreach ($chunks as $chunk) {
            if (mb_strlen($chunk) > $maxSize) {
                $finalChunks = array_merge($finalChunks, $standard->split($chunk, $maxSize));
            } else {
                $finalChunks[] = $chunk;
            }
        }

        return $finalChunks;
    }
}

<?php

namespace App\Services\Extractors\Splitters;

class StandardSplitter implements SplitterInterface
{
    public function split(string $text, int $maxSize): array
    {
        $separators = ["\n\n", "\n", ". ", " ", ""];
        return $this->recursiveSplit($text, $separators, $maxSize);
    }

    protected function recursiveSplit(string $text, array $separators, int $maxSize): array
    {
        if (mb_strlen($text) <= $maxSize) {
            return [$text];
        }

        $separator = array_shift($separators);
        if ($separator === null) {
            return str_split($text, $maxSize);
        }

        $chunks = [];
        $parts = explode($separator, $text);
        $currentChunk = "";

        foreach ($parts as $part) {
            if (mb_strlen($currentChunk . $separator . $part) <= $maxSize) {
                $currentChunk .= ($currentChunk ? $separator : "") . $part;
            } else {
                if ($currentChunk) {
                    $chunks[] = $currentChunk;
                }
                
                if (mb_strlen($part) > $maxSize) {
                    $subChunks = $this->recursiveSplit($part, $separators, $maxSize);
                    foreach ($subChunks as $sc) {
                        $chunks[] = $sc;
                    }
                    $currentChunk = "";
                } else {
                    $currentChunk = $part;
                }
            }
        }

        if ($currentChunk) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }
}

<?php

namespace App\ValueObjects;

class CognitiveFragment
{
    public function __construct(
        public string $content,        // Raw text for the LLM to read
        public string $vectorAnchor,   // Context-enriched text for the Embedding model
        public array $metadata = []    // Fragment-specific metadata
    ) {}

    public static function make(string $content, string $summary, string $path): self
    {
        // The Anchor: Summary + Path + Content
        // This ensures the vector is mathematically tied to its context
        $anchor = "DOCUMENT: [{$path}]\nSUMMARY: {$summary}\n\nCONTENT: {$content}";

        return new self($content, $anchor);
    }
}

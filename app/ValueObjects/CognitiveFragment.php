<?php

namespace App\ValueObjects;

class CognitiveFragment
{
    public function __construct(
        public string $content,        // Raw text for the LLM to read
        public string $vectorAnchor,   // Context-enriched text for the Embedding model
        public array $metadata = []    // Fragment-specific metadata
    ) {}

    public static function make(string $content, string $summary, string $path, array $perception = []): self
    {
        // The Anchor: Metadata + Summary + Path + Content
        // We inject the semantic document type and extracted metadata into the anchor
        // to ensure the embedding is aware of the "nature" of the document.
        $type = $perception['document_type'] ?? 'GENERAL';
        $metaStr = "";
        
        if (!empty($perception['extracted_metadata'])) {
            foreach ($perception['extracted_metadata'] as $key => $value) {
                if (is_array($value)) $value = json_encode($value);
                $metaStr .= "META_{$key}: {$value}\n";
            }
        }

        $anchor = "DOCUMENT_TYPE: [{$type}]\nDOCUMENT: [{$path}]\nSUMMARY: {$summary}\n{$metaStr}\nCONTENT: {$content}";

        return new self($content, $anchor);
    }
}

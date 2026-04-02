<?php

namespace App\ValueObjects;

class MediaResult
{
    public function __construct(
        public string $content,
        public ?string $summary = null,
        public array $metadata = [],
        public array $fragments = [], // Optional pre-chunked data
        public string $type = 'file_part'
    ) {}

    public static function fromText(string $text, array $metadata = []): self
    {
        return new self($text, null, $metadata);
    }
}

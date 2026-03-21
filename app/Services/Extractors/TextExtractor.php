<?php

namespace App\Services\Extractors;

use Illuminate\Support\Facades\File;

class TextExtractor implements ExtractorInterface
{
    protected array $supported = ['txt', 'md', 'php', 'js', 'ts', 'vue', 'json', 'css', 'html'];

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->supported);
    }

    public function extract(string $path): string
    {
        return File::get($path);
    }
}

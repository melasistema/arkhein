<?php

namespace App\Services\Extractors;

use App\ValueObjects\MediaResult;
use Illuminate\Support\Facades\File;

class TextProcessor implements MediaProcessorInterface
{
    protected array $extensions = ['txt', 'md', 'json', 'yml', 'yaml', 'php', 'js', 'ts', 'css', 'html'];

    public function supports(string $extension, string $mimeType): bool
    {
        return in_array(strtolower($extension), $this->extensions) || str_starts_with($mimeType, 'text/');
    }

    public function process(string $path): MediaResult
    {
        $content = File::get($path);
        return MediaResult::fromText($content);
    }
}

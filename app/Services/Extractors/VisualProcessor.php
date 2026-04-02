<?php

namespace App\Services\Extractors;

use App\Services\OllamaService;
use App\ValueObjects\MediaResult;
use Illuminate\Support\Facades\Log;

class VisualProcessor implements MediaProcessorInterface
{
    protected array $extensions = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    protected array $mimeTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public function __construct(
        protected OllamaService $ollama
    ) {}

    public function supports(string $extension, string $mimeType): bool
    {
        return in_array(strtolower($extension), $this->extensions) || in_array($mimeType, $this->mimeTypes);
    }

    public function process(string $path): MediaResult
    {
        try {
            $prompt = "Describe this image in detail. Focus on the main subjects, colors, layout, and any visible text. Be professional and objective.";
            $description = $this->ollama->generateWithImages($prompt, [$path]);

            return new MediaResult(
                content: $description,
                summary: "Visual Analysis of " . basename($path),
                metadata: [
                    'file_size' => filesize($path),
                    'is_visual' => true
                ],
                type: 'visual_description'
            );
        } catch (\Exception $e) {
            Log::error("Arkhein MediaCore: Visual processing failed for {$path}: " . $e->getMessage());
            return new MediaResult("Image analysis unavailable.");
        }
    }
}

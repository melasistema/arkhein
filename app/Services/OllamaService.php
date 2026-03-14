<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaService
{
    protected string $host;

    public function __construct()
    {
        $this->host = config('services.ollama.host', env('OLLAMA_HOST', 'http://localhost:11434'));
    }

    /**
     * Generate a completion from a model.
     */
    public function generate(string $model, string $prompt, array $options = [])
    {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
        ];

        // Format (e.g. 'json')
        if (isset($options['format'])) {
            $payload['format'] = $options['format'];
        }

        // Internal Ollama Model Options
        if (isset($options['options'])) {
            $payload['options'] = $options['options'];
        }

        $response = Http::timeout(300)->post("{$this->host}/api/generate", $payload);

        if ($response->failed()) {
            Log::error("Ollama generate failed: " . $response->body());
            return null;
        }

        return $response->json();
    }

    /**
     * Generate embeddings for a given text.
     */
    public function embeddings(string $model, string $prompt)
    {
        $response = Http::timeout(60)->post("{$this->host}/api/embeddings", [
            'model' => $model,
            'prompt' => $prompt,
        ]);

        if ($response->failed()) {
            Log::error("Ollama embeddings failed: " . $response->body());
            return null;
        }

        return $response->json('embedding');
    }

    /**
     * List local models.
     */
    public function tags()
    {
        $response = Http::get("{$this->host}/api/tags");

        if ($response->failed()) {
            return [];
        }

        return $response->json('models');
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Setting;

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
    public function generate(string $prompt, ?string $model = null, array $options = []): string
    {
        $settingModel = Setting::get('llm_model');
        $configModel = config('services.ollama.model');
        $selectedModel = $model ?? $settingModel ?? $configModel;

        Log::debug("OllamaService: Generating with model selection", [
            'explicit_model_arg' => $model,
            'setting_value' => $settingModel,
            'config_fallback' => $configModel,
            'final_selected_model' => $selectedModel,
        ]);
        
        if (empty($selectedModel)) {
            Log::error("Ollama generate failed: No LLM model configured.");
            return "Inference engine unreachable. Check configuration.";
        }

        $payload = [
            'model' => $selectedModel,
            'prompt' => $this->sanitize($prompt),
            'stream' => false,
        ];

        if (isset($options['format'])) {
            $payload['format'] = $options['format'];
        }

        if (isset($options['options'])) {
            $payload['options'] = $options['options'];
        }

        $timeout = config('arkhein.boundaries.execution_timeout', 300);
        $response = Http::timeout($timeout)->post("{$this->host}/api/generate", $payload);

        if ($response->failed()) {
            Log::error("Ollama generate failed: " . $response->body());
            return "Inference engine failed. Check system log.";
        }

        return $response->json('response') ?? "Inference engine returned an empty response.";
    }

    /**
     * Generate a response using the Chat API.
     */
    public function chat(array $messages, ?string $model = null, array $options = []): string
    {
        $settingModel = Setting::get('llm_model');
        $configModel = config('services.ollama.model');
        $selectedModel = $model ?? $settingModel ?? $configModel;

        if (empty($selectedModel)) {
            Log::error("Ollama chat failed: No LLM model configured.");
            return "Inference engine unreachable. Check configuration.";
        }

        $payload = [
            'model' => $selectedModel,
            'messages' => $messages,
            'stream' => false,
        ];

        if (isset($options['format'])) $payload['format'] = $options['format'];
        if (isset($options['options'])) $payload['options'] = $options['options'];

        $timeout = config('arkhein.boundaries.execution_timeout', 300);
        $response = Http::timeout($timeout)->post("{$this->host}/api/chat", $payload);

        if ($response->failed()) {
            Log::error("Ollama chat failed: " . $response->body());
            return "Inference engine failed. Check system log.";
        }

        return $response->json('message.content') ?? "Inference engine returned an empty response.";
    }

    /**
     * Generate embeddings for a given text.
     */
    public function embeddings(string $prompt, ?string $model = null): ?array
    {
        $settingModel = Setting::get('embedding_model');
        $configModel = config('services.ollama.embedding_model');
        $selectedModel = $model ?? $settingModel ?? $configModel;

        Log::debug("OllamaService: Getting embeddings with model selection", [
            'explicit_model_arg' => $model,
            'setting_value' => $settingModel,
            'config_fallback' => $configModel,
            'final_selected_model' => $selectedModel,
        ]);

        if (empty($selectedModel)) {
            Log::error("Ollama embeddings failed: No embedding model configured.");
            return null;
        }

        $response = Http::timeout(60)->post("{$this->host}/api/embeddings", [
            'model' => $selectedModel,
            'prompt' => $this->sanitize($prompt),
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
    public function tags(): array
    {
        $response = Http::get("{$this->host}/api/tags");

        if ($response->failed()) {
            return [];
        }

        return $response->json('models') ?? [];
    }

    /**
     * Ensure the string is valid UTF-8 for JSON encoding.
     */
    protected function sanitize(string $text): string
    {
        $sanitized = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if ($sanitized === false) {
            Log::error("OllamaService: Malformed UTF-8 characters detected and removed.");
            $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        }
        return $sanitized;
    }
}

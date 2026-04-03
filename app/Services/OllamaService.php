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
        
        // Ensure healthy context window for local LLMs
        if (!isset($payload['options']['num_ctx'])) {
            $payload['options']['num_ctx'] = 16384;
        }

        $timeout = config('arkhein.protocols.inference_timeout', 300);
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

        if (!isset($payload['options']['num_ctx'])) {
            $payload['options']['num_ctx'] = 16384;
        }

        $timeout = config('arkhein.protocols.inference_timeout', 300);
        $response = Http::timeout($timeout)->post("{$this->host}/api/chat", $payload);

        if ($response->failed()) {
            Log::error("Ollama chat failed: " . $response->body());
            return "Inference engine failed. Check system log.";
        }

        return $response->json('message.content') ?? "Inference engine returned an empty response.";
    }

    /**
     * Stream a response using the Chat API.
     */
    public function streamChat(array $messages, callable $onChunk, ?string $model = null, array $options = []): void
    {
        $settingModel = Setting::get('llm_model');
        $configModel = config('services.ollama.model');
        $selectedModel = $model ?? $settingModel ?? $configModel;

        if (empty($selectedModel)) {
            Log::error("Ollama streamChat failed: No LLM model configured.");
            $onChunk("Inference engine unreachable. Check configuration.");
            return;
        }

        $payload = [
            'model' => $selectedModel,
            'messages' => $messages,
            'stream' => true,
        ];

        if (isset($options['format'])) $payload['format'] = $options['format'];
        if (isset($options['options'])) $payload['options'] = $options['options'];

        if (!isset($payload['options']['num_ctx'])) {
            $payload['options']['num_ctx'] = 16384;
        }

        $timeout = config('arkhein.protocols.inference_timeout', 300);
        
        try {
            $response = Http::timeout($timeout)
                ->withOptions(['stream' => true])
                ->post("{$this->host}/api/chat", $payload);

            if ($response->failed()) {
                Log::error("Ollama streamChat failed: " . $response->body());
                $onChunk("Inference engine failed. Check system log.");
                return;
            }

            $body = $response->toPsrResponse()->getBody();

            while (!$body->eof()) {
                $line = $this->readLine($body);
                if (empty($line)) continue;

                $data = json_decode($line, true);
                if (isset($data['message']['content'])) {
                    $onChunk($data['message']['content']);
                }
                
                if (isset($data['done']) && $data['done']) {
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error("Ollama streamChat exception: " . $e->getMessage());
            $onChunk("\n\n[Stream Interrupted]");
        }
    }

    protected function readLine($body): string
    {
        $line = '';
        while (!$body->eof()) {
            $char = $body->read(1);
            if ($char === "\n") break;
            $line .= $char;
        }
        return $line;
    }

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

    public function generateWithImages(string $prompt, array $imagePaths, ?string $model = null): string
    {
        $selectedModel = $model ?? Setting::get('vision_model', config('services.ollama.vision_model', 'qwen3-vl:latest'));

        if (empty($selectedModel)) {
            Log::error("Ollama vision failed: No vision model configured.");
            return "Vision engine unreachable.";
        }

        $images = [];
        foreach ($imagePaths as $path) {
            if (file_exists($path)) {
                $images[] = base64_encode(file_get_contents($path));
            }
        }

        $payload = [
            'model' => $selectedModel,
            'prompt' => $prompt,
            'images' => $images,
            'stream' => false,
        ];

        $timeout = config('arkhein.protocols.inference_timeout', 300);
        $response = Http::timeout($timeout)->post("{$this->host}/api/generate", $payload);

        if ($response->failed()) {
            Log::error("Ollama vision failed: " . $response->body());
            return "Vision analysis failed.";
        }

        return $response->json('response') ?? "Vision engine returned empty response.";
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

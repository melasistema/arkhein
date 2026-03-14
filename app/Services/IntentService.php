<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class IntentService
{
    public function __construct(
        protected OllamaService $ollama
    ) {}

    /**
     * Classify the user intent.
     * Exposes simple intent string, encapsulates the classification pipeline.
     */
    public function classify(string $input): string
    {
        $model = Setting::get('llm_model', config('services.ollama.model'));
        
        // Minimalist classification prompt
        $prompt = "Classify intent as: CHAT, FILE_SYSTEM, or SCHEDULE.
Input: \"$input\"
JSON Result:";

        Log::info("Arkhein: Classifying intent...");

        try {
            $response = $this->ollama->generate($model, $prompt, [
                'format' => 'json',
                'options' => [
                    'num_predict' => 20, // Limit tokens for speed
                    'temperature' => 0   // Deterministic
                ]
            ]);

            if (!$response || empty($response['response'])) {
                return 'CHAT';
            }

            $result = json_decode($response['response'], true);
            return strtoupper($result['intent'] ?? 'CHAT');
        } catch (\Exception $e) {
            Log::error("Intent classification crash: " . $e->getMessage());
            return 'CHAT';
        }
    }
}

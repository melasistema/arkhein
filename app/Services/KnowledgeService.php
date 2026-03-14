<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\UserInsight;
use App\Models\Memory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KnowledgeService
{
    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory
    ) {}

    /**
     * Search all memory layers (Archive, Chat, Insights).
     */
    public function recall(string $query, int $limit = 5): array
    {
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model', 'nomic-embed-text:latest'));
        $embedding = $this->ollama->embeddings($embeddingModel, $query);

        if (!$embedding) return [];

        $this->memory->ensureIndex(count($embedding));
        return $this->memory->search($embedding, $limit);
    }

    /**
     * Process a recent interaction to extract new insights.
     * The Reflection Pipeline.
     */
    public function reflect(string $userMessage, string $assistantResponse): void
    {
        $model = Setting::get('llm_model', config('services.ollama.model', 'llama3.2:1b'));
        
        $prompt = "As an AI reflection module, analyze this interaction between a user and an assistant.
Extract any new personal facts, habits, or behavioral patterns about the user.

Interaction:
User: {$userMessage}
Assistant: {$assistantResponse}

Return ONLY a JSON array of insights or an empty array [].
Format: [{\"type\": \"fact|habit|pattern|personality\", \"content\": \"The insight text\", \"importance\": 1-10}]";

        $response = $this->ollama->generate($model, $prompt, ['format' => 'json']);
        
        if (!$response || !isset($response['response'])) return;

        try {
            $insights = json_decode($response['response'], true);
            
            if (is_array($insights)) {
                foreach ($insights as $data) {
                    $this->recordInsight($data);
                }
            }
        } catch (\Exception $e) {
            Log::error("Reflection parsing failed: " . $e->getMessage());
        }
    }

    /**
     * Record and index a single insight.
     */
    protected function recordInsight(array $data): void
    {
        $content = $data['content'] ?? '';
        if (empty($content)) return;

        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model', 'nomic-embed-text:latest'));
        $embedding = $this->ollama->embeddings($embeddingModel, $content);

        $insight = UserInsight::updateOrCreate(
            ['content' => $content],
            [
                'type' => $data['type'] ?? 'fact',
                'embedding' => $embedding,
                'importance' => $data['importance'] ?? 1,
                'metadata' => $data,
                'last_observed_at' => now(),
            ]
        );

        $insight->increment('occurrence_count');

        // 2. Index for RAG
        if ($embedding) {
            $this->memory->save(
                Str::uuid()->toString(),
                "User Insight ({$insight->type}): {$content}",
                $embedding,
                'insight',
                ['insight_id' => $insight->id]
            );
        }
    }
}

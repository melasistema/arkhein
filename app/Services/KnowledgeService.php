<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Knowledge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KnowledgeService
{
    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory,
        protected PromptService $prompts
    ) {}

    /**
     * Search the Knowledge Base with a similarity threshold.
     */
    public function recall(string $query, int $limit = 5, ?int $folderId = null): array
    {
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model'));
        $embedding = $this->ollama->embeddings($embeddingModel, $query);

        if (!$embedding) {
            Log::error("Arkhein RAG: Failed to generate embedding for query.");
            return [];
        }

        // If a folderId is provided, we search with a MUCH higher limit and filter post-retrieval
        // since the vector index is flat and global.
        $searchLimit = $folderId ? $limit * 100 : $limit;
        $results = $this->memory->search($embedding, $searchLimit);

        Log::info("Arkhein RAG: Raw Memory Search Results", [
            'raw_count' => count($results),
            'top_score' => count($results) > 0 ? $results[0]['score'] : null,
            'threshold_used' => config('knowledge.recall_threshold', 0.65)
        ]);

        if ($folderId) {
            $filtered = collect($results)
                ->filter(function ($item) use ($folderId) {
                    $metadata = $item['metadata'] ?? [];
                    $match = isset($metadata['folder_id']) && (int) $metadata['folder_id'] === $folderId;
                    return $match;
                })
                ->take($limit)
                ->values()
                ->toArray();

            Log::info("Arkhein RAG: Filtered Results", [
                'folder_id' => $folderId,
                'filtered_count' => count($filtered)
            ]);

            return $filtered;
        }

        return $results;
    }

    /**
     * Process interaction to extract and reconcile insights.
     */
    public function reflect(string $userMessage, string $assistantResponse): void
    {
        $model = Setting::get('llm_model', config('services.ollama.model'));
        $prompt = config('prompts.reflection.extract_insights');
        
        $context = "User: {$userMessage}\nAssistant: {$assistantResponse}";

        $response = $this->ollama->generate($model, $prompt . "\n\n" . $context, ['format' => 'json']);
        
        if (!$response || empty($response['response'])) return;

        try {
            $insights = json_decode($response['response'], true);
            if (is_array($insights)) {
                foreach ($insights as $data) {
                    // LLM might return a stringified JSON inside the array
                    if (is_string($data)) {
                        $data = json_decode($data, true);
                    }
                    
                    if (is_array($data)) {
                        $this->ingest($data);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Reflection extraction failed: " . $e->getMessage());
        }
    }

    /**
     * Ingest a new insight, performing reconciliation if needed.
     */
    public function ingest(array $data): void
    {
        $content = $data['content'] ?? '';
        if (empty($content)) return;

        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model'));
        $embedding = $this->ollama->embeddings($embeddingModel, $content);
        if (!$embedding) return;

        // 1. Search for existing similar insights (High Threshold)
        $threshold = config('knowledge.reconciliation_threshold', 0.88);
        $existing = $this->memory->search($embedding, 1, $threshold);

        if (!empty($existing)) {
            $this->reconcile($data, $existing[0], $embedding);
        } else {
            // New unique insight
            $this->saveInsight(Str::uuid(), $data, $embedding);
        }
    }

    /**
     * Compare new observation with existing knowledge via LLM.
     */
    protected function reconcile(array $new, array $old, array $newEmbedding): void
    {
        $model = Setting::get('llm_model', config('services.ollama.model'));
        $prompt = config('prompts.reflection.reconcile_knowledge');
        
        $fullPrompt = str_replace(
            ['{new_insight}', '{existing_context}'],
            [$new['content'], $old['content']],
            $prompt
        );

        $response = $this->ollama->generate($model, $fullPrompt, ['format' => 'json']);
        if (!$response || empty($response['response'])) return;

        try {
            $result = json_decode($response['response'], true);
            $action = $result['action'] ?? 'keep';

            if ($action === 'update' || $action === 'merge') {
                // Update the existing record with consolidated info
                $this->saveInsight($old['id'], [
                    'type' => $new['type'],
                    'content' => $result['content'] ?? $new['content'],
                    'importance' => $result['importance'] ?? $new['importance']
                ], $newEmbedding);
                
                Log::info("Arkhein: Knowledge reconciled ({$action}).");
            }
        } catch (\Exception $e) {
            Log::error("Reconciliation failed: " . $e->getMessage());
        }
    }

    protected function saveInsight(string $id, array $data, array $embedding): void
    {
        $this->memory->save($id, $data['content'], $embedding, 'insight', [
            'type' => $data['type'],
            'importance' => $data['importance'] ?? 1,
            'timestamp' => now()->toIso8601String()
        ]);
    }
}

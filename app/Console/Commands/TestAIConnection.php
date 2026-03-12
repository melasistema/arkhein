<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OllamaService;
use App\Services\MemoryService;

class TestAIConnection extends Command
{
    protected $signature = 'test:ai';
    protected $description = 'Test Ollama and Redis Stack Vector Search connectivity';

    public function handle(OllamaService $ollama, MemoryService $memory)
    {
        $this->info("Checking Ollama Connection...");
        $models = $ollama->tags();
        
        if (empty($models)) {
            $this->error("Could not connect to Ollama or no models found.");
            $this->warn("Make sure Ollama is running and has models pulled.");
            return;
        }

        $this->success("Ollama connected! Found " . count($models) . " models.");
        
        // Select a model
        $model = $models[0]['name'] ?? 'llama3';
        $this->info("Using model: $model");

        $text = "Hello Arkhein, testing long term memory.";
        $this->info("Generating embeddings for: '$text'");
        
        $embedding = $ollama->embeddings($model, $text);
        
        if (!$embedding) {
            $this->error("Failed to generate embedding.");
            return;
        }

        $this->success("Embedding generated (" . count($embedding) . " dimensions).");

        $this->info("Checking Redis Stack Connection...");
        $dimensions = count($embedding);
        
        if ($memory->ensureIndex($dimensions)) {
            $this->success("Redis Stack Index verified.");
        } else {
            $this->error("Redis Stack Index creation failed.");
            return;
        }

        $this->info("Saving memory to Redis...");
        if ($memory->save('test-1', $text, $embedding, ['test' => true])) {
            $this->success("Memory saved successfully.");
        }

        $this->info("Searching for similar memories...");
        $results = $memory->search($embedding, 1);
        
        if (count($results) > 0) {
            $this->success("Search successful! Found memory: " . $results[0]['content']);
        } else {
            $this->warn("Search returned no results.");
        }
    }

    protected function success($message)
    {
        $this->line("<info>✔</info> $message");
    }
}

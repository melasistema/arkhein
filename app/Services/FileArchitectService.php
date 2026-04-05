<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ManagedFolder;
use App\Models\SystemTask;
use Illuminate\Support\Facades\Log;

class FileArchitectService
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag
    ) {}

    /**
     * Assemble a complex document using the Cognitive Layer Stack.
     */
    public function assemble(ManagedFolder $folder, string $instruction, callable $onProgress = null): string
    {
        Log::info("Arkhein Architect: Starting Cognitive Assembly", ['instruction' => $instruction]);

        // 1. THE DECONSTRUCTION LAYER (Planning)
        if ($onProgress) $onProgress("Deconstructing instruction into strategy...");
        $roadmap = $this->createRoadmap($instruction);

        // 2. THE TARGETING LAYER
        if ($onProgress) $onProgress("Identifying target documents...");
        $targetPaths = $this->identifyTargets($folder, $instruction);
        
        if (empty($targetPaths)) {
            return "No relevant documents found to fulfill this instruction.";
        }

        // 3. THE HARVESTING LAYER (with Latent Reasoning)
        $factMap = [];
        $targetPaths = array_values($targetPaths);
        foreach ($targetPaths as $index => $path) {
            $count = $index + 1;
            $total = count($targetPaths);
            if ($onProgress) $onProgress("Harvesting data ({$count}/{$total}): " . basename($path));
            
            $fact = $this->harvestFact($folder, $path, $instruction, $roadmap);
            if ($fact) {
                $factMap[] = $fact;
            }
        }

        // 4. THE ASSEMBLY LAYER (Drafting)
        if ($onProgress) $onProgress("Drafting initial document...");
        $initialDraft = $this->synthesize($factMap, $instruction);

        // 5. THE SELF-CORRECTION LAYER (Critique & Refinement)
        if ($onProgress) $onProgress("Verifying accuracy and refining...");
        return $this->refine($initialDraft, $factMap, $instruction);
    }

    /**
     * Layer 1: Break instruction into a roadmap.
     */
    protected function createRoadmap(string $instruction): string
    {
        $prompt = "You are the Arkhein Metacognitive pass. 
        TASK: Break the USER INSTRUCTION into a step-by-step data extraction strategy.
        USER INSTRUCTION: \"{$instruction}\"
        
        What specific keys, patterns, or markers should we look for in each file to ensure 100% accuracy?
        Output a 1-sentence dense strategy.";

        return $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.1]]);
    }

    /**
     * Layer 2 & 3: Fact Harvesting with Latent Reasoning.
     */
    protected function harvestFact(ManagedFolder $folder, string $path, string $instruction, string $roadmap): ?string
    {
        $vessel = Document::where('folder_id', $folder->id)->where('path', $path)->first();
        if (!$vessel) return null;

        $fragments = \App\Models\Knowledge::on('nativephp')
            ->where('document_id', $vessel->id)
            ->orderBy('metadata->chunk_index', 'asc')
            ->pluck('content')
            ->implode("\n\n");

        if (empty(trim($fragments))) return null;

        // Prompt includes the Roadmap (Contextual Injection) and asks for Thinking (Scratchpad)
        $prompt = "You are the Arkhein Data Miner.
        STRATEGY: {$roadmap}
        TARGET: \"{$instruction}\"
        FILE: {$path}
        
        FILE CONTENT:
        {$fragments}

        INSTRUCTIONS:
        1. First, wrap your internal analysis in <think>...</think> tags.
        2. Then, extract the EXACT values. Do NOT summarize.
        3. One sentence maximum. If missing, respond 'NOT_FOUND'.";

        $response = $this->ollama->generate($prompt, null, [
            'options' => ['temperature' => 0, 'num_ctx' => 8192]
        ]);
        
        // Strip the thinking block from the stored fact
        $fact = preg_replace('/<think>.*?<\/think>/s', '', $response);
        $fact = trim($fact);

        if (str_contains(strtoupper($fact), 'NOT_FOUND') || empty($fact)) {
            return null;
        }

        return "- Source [{$path}]: {$fact}";
    }

    protected function synthesize(array $factMap, string $instruction): string
    {
        $data = implode("\n", $factMap);
        $prompt = "You are the Arkhein Master Architect.
        TASK: Assemble the provided SOURCE DATA into a professional Markdown document.
        USER INSTRUCTION: \"{$instruction}\"
        
        SOURCE DATA:
        {$data}

        RULES:
        1. Use EXACT values from source data. No generic labels.
        2. Structured Markdown format. No preamble.
        
        DOCUMENT CONTENT:";

        return $this->ollama->generate($prompt, null, [
            'options' => ['temperature' => 0.1, 'num_ctx' => 16384, 'num_predict' => 4096]
        ]);
    }

    /**
     * Layer 4: The Refinement Pass.
     */
    protected function refine(string $draft, array $factMap, string $instruction): string
    {
        $data = implode("\n", $factMap);
        $prompt = "You are the Arkhein Quality Auditor.
        Compare the DRAFT with the ORIGINAL DATA. 
        
        DRAFT:
        {$draft}
        
        ORIGINAL DATA:
        {$data}
        
        TASK: If the DRAFT is missing any items from ORIGINAL DATA or used generic labels instead of values, rewrite it to be 100% accurate. 
        Otherwise, return the DRAFT exactly as is.
        Output ONLY the final markdown content.
        
        FINAL DOCUMENT:";

        return $this->ollama->generate($prompt, null, [
            'options' => ['temperature' => 0, 'num_ctx' => 16384, 'num_predict' => 4096]
        ]);
    }

    protected function identifyTargets(ManagedFolder $folder, string $instruction): array
    {
        if (preg_match('/\b(all|every|everything|complete list|entire)\b/i', $instruction)) {
            return Document::where('folder_id', $folder->id)->pluck('path')->all();
        }

        $docs = Document::where('folder_id', $folder->id)->get(['path', 'summary']);
        $manifest = $docs->map(fn($d) => "- {$d->path} | Summary: {$d->summary}")->implode("\n");

        $prompt = "Identify target files for: \"{$instruction}\"\nMANIFEST:\n{$manifest}\nOutput JSON array of paths.";
        $response = $this->ollama->generate($prompt, null, ['format' => 'json']);
        
        try {
            $data = json_decode($response, true);
            if (isset($data['paths'])) $data = $data['paths'];
            if (!is_array($data)) return [];
            return collect($data)->map(fn($i) => is_array($i) ? ($i['path'] ?? null) : $i)->filter()->values()->all();
        } catch (\Exception $e) {
            return [];
        }
    }
}

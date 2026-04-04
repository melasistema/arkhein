<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ManagedFolder;
use Illuminate\Support\Facades\Log;

class FileArchitectService
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag
    ) {}

    /**
     * Assemble a complex document using a multi-stage agentic pipeline.
     */
    public function assemble(ManagedFolder $folder, string $instruction, callable $onProgress = null): string
    {
        Log::info("Arkhein Architect: Starting assembly", ['instruction' => $instruction]);

        // Stage 1: Target Identification (Selection)
        if ($onProgress) $onProgress("Identifying target documents...");
        $targetPaths = $this->identifyTargets($folder, $instruction);
        
        if (empty($targetPaths)) {
            Log::warning("Arkhein Architect: No target documents identified.");
            return "No relevant documents found to fulfill this instruction.";
        }

        Log::info("Arkhein Architect: Targets identified", ['count' => count($targetPaths)]);

        // Stage 2: Fact Harvesting (Extraction Loop)
        $factMap = [];
        $targetPaths = array_values($targetPaths); // Force simple numeric indices
        foreach ($targetPaths as $index => $path) {
            $count = $index + 1;
            $total = count($targetPaths);
            if ($onProgress) $onProgress("Harvesting data from document {$count}/{$total}...");
            
            $fact = $this->harvestFact($folder, $path, $instruction);
            if ($fact) {
                $factMap[] = $fact;
            }
        }

        // Stage 3: Final Assembly (Synthesis)
        if ($onProgress) $onProgress("Synthesizing final document...");
        return $this->synthesize($factMap, $instruction);
    }

    /**
     * Use the Silo Manifest to decide which files are relevant.
     */
    protected function identifyTargets(ManagedFolder $folder, string $instruction): array
    {
        // 1. WISE HEURISTIC: If user wants 'all' files, don't ask the LLM to guess.
        // This ensures 100% accuracy for aggregate tasks on small models.
        if (preg_match('/\b(all|every|everything|complete list|entire)\b/i', $instruction)) {
            Log::info("Arkhein Architect: Global task detected. Targeting all documents in silo.");
            return Document::where('folder_id', $folder->id)->pluck('path')->all();
        }

        // 2. FOCUSED SELECTION: Ask LLM to filter if the task is specific
        $docs = Document::where('folder_id', $folder->id)->get(['path', 'summary']);
        $manifest = $docs->map(fn($d) => "- {$d->path} | Summary: {$d->summary}")->implode("\n");

        $prompt = "You are the Arkhein Selection Agent. Based on the SILO MANIFEST and the USER INSTRUCTION, identify every file that must be read to complete the task.
        
        USER INSTRUCTION: \"{$instruction}\"
        
        SILO MANIFEST:
        {$manifest}

        Respond ONLY with a JSON array of strings representing the relative file paths.
        Example: [\"file1.md\", \"folder/file2.md\"]";

        $response = $this->ollama->generate($prompt, null, ['format' => 'json']);
        
        try {
            $data = json_decode($response, true);
            
            // Handle common LLM output variations
            if (isset($data['paths'])) $data = $data['paths'];
            if (isset($data['files'])) $data = $data['files'];
            if (isset($data['targets'])) $data = $data['targets'];

            if (!is_array($data)) return [];

            // Flat-mapping: Ensure we have strings, even if LLM returned objects like [{"path": "..."}]
            return collect($data)->map(function($item) {
                if (is_array($item)) {
                    return $item['path'] ?? $item['file'] ?? $item['name'] ?? null;
                }
                return is_string($item) ? $item : null;
            })->filter()->values()->all();

        } catch (\Exception $e) {
            Log::error("Arkhein Architect: Failed to parse target JSON", ['response' => $response]);
            return [];
        }
    }

    /**
     * Read a specific file and extract the requested detail.
     */
    protected function harvestFact(ManagedFolder $folder, string $path, string $instruction): ?string
    {
        // 1. Fetch document context
        $vessel = Document::where('folder_id', $folder->id)->where('path', $path)->first();
        if (!$vessel) return null;

        // 2. FETCH ALL FRAGMENTS DIRECTLY
        $fragments = \App\Models\Knowledge::on('nativephp')
            ->where('document_id', $vessel->id)
            ->orderBy('metadata->chunk_index', 'asc')
            ->pluck('content')
            ->implode("\n\n");

        if (empty(trim($fragments))) return null;

        // 3. Precise Extraction Pass
        $prompt = "You are the Arkhein Data Miner.
        TASK: Extract the EXACT values from the FILE CONTENT to fulfill the TARGET REQUIREMENT.
        
        FILE: {$path}
        TARGET REQUIREMENT: \"{$instruction}\"
        
        FILE CONTENT:
        {$fragments}

        RULES:
        1. Extract specific NAMES, DATES, and DETAILS.
        2. Do NOT use generic labels or placeholders.
        3. One sentence maximum.
        4. If the data is missing, respond with 'NOT_FOUND'.
        
        EXTRACTED DATA:";

        $fact = $this->ollama->generate($prompt, null, [
            'options' => [
                'temperature' => 0, 
                'num_ctx' => 8192,
                'num_predict' => 256
            ]
        ]);
        
        $fact = trim($fact);
        if (str_contains(strtoupper($fact), 'NOT_FOUND') || empty($fact)) {
            return null;
        }

        return "- SOURCE [{$path}]: {$fact}";
    }

    /**
     * Combine harvested facts into a polished document.
     */
    protected function synthesize(array $factMap, string $instruction): string
    {
        $data = implode("\n", $factMap);
        
        $prompt = "You are the Arkhein Master Architect.
        TASK: Assemble the provided SOURCE DATA into a professional Markdown document.
        
        USER INSTRUCTION: \"{$instruction}\"
        
        SOURCE DATA:
        {$data}

        STRICT RULES:
        1. USE THE EXACT NAMES AND DETAILS FROM THE SOURCE DATA.
        2. NEVER USE PLACEHOLDERS like 'Primary Diagnosis' or 'Name'.
        3. If you see 'John Doe: Hypertension' in the data, your document MUST say 'John Doe: Hypertension'.
        4. Output ONLY the document content. No preamble.
        5. Use a structured Markdown list or table.
        
        FINAL DOCUMENT:";

        return $this->ollama->generate($prompt, null, [
            'options' => [
                'temperature' => 0.1, 
                'num_ctx' => 16384,
                'num_predict' => 4096 // Ensure we don't truncate long lists
            ]
        ]);
    }
}

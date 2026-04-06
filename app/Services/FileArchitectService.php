<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ManagedFolder;
use App\Models\SystemTask;
use Illuminate\Support\Facades\Log;
use App\Services\CognitiveService;

class FileArchitectService
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag,
        protected CognitiveService $cognitive
    ) {}

    /**
     * Assemble a complex document using the Cognitive Layer Stack.
     */
    public function assemble(ManagedFolder $folder, string $instruction, callable $onProgress = null, string $targetPath = 'draft.md'): string
    {
        Log::info("Arkhein Architect: Starting Cognitive Assembly", ['instruction' => $instruction]);
        
        $folderId = (string) $folder->id;
        $cotFilename = basename($targetPath) . ".architect.md";
        $cotContent = "# Architect Workflow: {$targetPath}\n\n";
        $cotContent .= "## Instruction\n> {$instruction}\n\n";
        
        $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);

        // 1. THE DECONSTRUCTION LAYER (Planning)
        if ($onProgress) $onProgress("Deconstructing instruction into strategy...");
        $roadmap = $this->createRoadmap($instruction);
        
        $cotContent .= "## Phase 1: Roadmap\n{$roadmap}\n\n";
        $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);

        // 2. THE TARGETING LAYER
        if ($onProgress) $onProgress("Identifying target documents...");
        $targetPaths = $this->identifyTargets($folder, $instruction);
        
        if (empty($targetPaths)) {
            $cotContent .= "## Phase 2: Targeting\nERROR: No relevant documents found in silo manifest.\n";
            $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);
            return "I could not identify any relevant documents in this silo to fulfill your request.";
        }
        
        $cotContent .= "## Phase 2: Targeting\nFound " . count($targetPaths) . " candidate documents.\n";
        foreach($targetPaths as $p) $cotContent .= "- {$p}\n";
        $cotContent .= "\n";
        $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);

        // 3. THE HARVESTING LAYER (with Latent Reasoning)
        $factMap = [];
        $targetPaths = array_values($targetPaths);
        $cotContent .= "## Phase 3: Harvesting\n";
        $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);
        
        foreach ($targetPaths as $index => $path) {
            $count = $index + 1;
            $total = count($targetPaths);
            if ($onProgress) $onProgress("Harvesting data ({$count}/{$total}): " . basename($path));
            
            $fact = $this->harvestFact($folder, $path, $instruction, $roadmap);
            if ($fact) {
                $factMap[] = $fact;
                $cotContent .= "### [MATCH] {$path}\n> {$fact}\n\n";
            } else {
                $cotContent .= "### [SKIP] {$path}\n> No matching data found.\n\n";
            }
            $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);
        }

        if (empty($factMap)) {
            $errorMsg = "I scanned " . count($targetPaths) . " files but found no data matching your specific request.";
            $cotContent .= "## Phase 4: Synthesis\nABORTED: No data harvested.\n";
            $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);
            return $errorMsg;
        }

        // 4. THE ASSEMBLY LAYER (Drafting)
        if ($onProgress) $onProgress("Drafting initial document...");
        $initialDraft = $this->synthesize($factMap, $instruction);
        
        $cotContent .= "## Phase 4: Initial Synthesis\n[Draft Generated from " . count($factMap) . " sources]\n\n";
        $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);

        // 5. THE SELF-CORRECTION LAYER (Critique & Refinement)
        if ($onProgress) $onProgress("Verifying accuracy and refining...");
        $finalDraft = $this->refine($initialDraft, $factMap, $instruction);
        
        $cotContent .= "## Phase 5: Final Refinement\nRefinement completed. Final draft is ready.\n";
        $this->cognitive->persistCoT('workspace', $folderId, $cotFilename, $cotContent);

        return $finalDraft;
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
        
        GOAL: Extract specific data for: \"{$instruction}\"
        STRATEGY: {$roadmap}
        FILE: {$path}
        
        FILE CONTENT (RAW):
        {$fragments}

        INSTRUCTIONS:
        1. Perform a deep scan of the CONTENT above.
        2. Identify specific values (dates, names, amounts, descriptions) matching the GOAL.
        3. If no matching data is found, output 'NOT_FOUND'.
        4. Output ONLY the extracted facts. No preamble. One dense list or paragraph.
        5. Use EXACT strings from the content.";

        $response = $this->ollama->generate($prompt, null, [
            'options' => ['temperature' => 0, 'num_ctx' => 8192]
        ]);
        
        $fact = trim($response);

        if (str_contains(strtoupper($fact), 'NOT_FOUND') || empty($fact)) {
            return null;
        }

        return "- Source [{$path}]: {$fact}";
    }

    protected function synthesize(array $factMap, string $instruction): string
    {
        $data = implode("\n", $factMap);
        $prompt = "You are the Arkhein Master Architect.
        
        GOAL: Assemble the provided SOURCE DATA into a professional Markdown document.
        INSTRUCTION: \"{$instruction}\"
        
        SOURCE DATA:
        {$data}

        MANDATORY RULES:
        1. Use ONLY the values found in the SOURCE DATA.
        2. If the data is missing specific requested fields (e.g. taxes), do NOT invent them. Leave them out or mark as 'Not Available'.
        3. Never use generic or filler data (e.g., 'XYZ Corp').
        4. Output professional Markdown. No preamble or conversational filler.
        
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
        
        COMPARE the DRAFT with the ORIGINAL SOURCE DATA. 
        
        DRAFT:
        {$draft}
        
        ORIGINAL SOURCE DATA:
        {$data}
        
        TASK:
        1. Identify any data in the DRAFT that does NOT exist in the ORIGINAL SOURCE DATA (Hallucinations).
        2. REMOVE all hallucinations.
        3. Ensure all pieces of data from the ORIGINAL SOURCE DATA are present.
        4. If the draft is accurate and hallucination-free, return it exactly.
        
        Output ONLY the final cleaned markdown content. No preamble.
        
        FINAL DOCUMENT:";

        return $this->ollama->generate($prompt, null, [
            'options' => ['temperature' => 0, 'num_ctx' => 16384, 'num_predict' => 4096]
        ]);
    }

    protected function identifyTargets(ManagedFolder $folder, string $instruction): array
    {
        $hasGlobalKeyword = (bool) preg_match('/\b(all|every|everything|complete list|entire|total|inventory)\b/i', $instruction);

        if ($hasGlobalKeyword) {
            // For global requests, we prioritize documents that aren't empty
            return Document::where('folder_id', $folder->id)
                ->whereNotNull('summary')
                ->pluck('path')
                ->take(50) // Safety cap
                ->all();
        }

        $docs = Document::where('folder_id', $folder->id)->get(['path', 'summary']);
        $manifest = $docs->map(fn($d) => "- {$d->path} | Summary: {$d->summary}")->implode("\n");

        $prompt = "TASK: Identify specific target files that contain data needed for: \"{$instruction}\"
        
        MANIFEST (Silo Contents):
        {$manifest}
        
        Respond ONLY with a JSON array of strings containing the file paths.
        Example: [\"path/to/file1.md\", \"path/to/file2.pdf\"]";

        $response = $this->ollama->generate($prompt, null, ['format' => 'json']);
        
        try {
            $data = json_decode($response, true);
            if (is_array($data) && isset($data['paths'])) $data = $data['paths'];
            
            if (!is_array($data)) {
                // Fallback for messy JSON
                if (preg_match('/\[.*\]/s', $response, $matches)) {
                    $data = json_decode($matches[0], true);
                }
            }
            
            return collect($data)
                ->map(fn($i) => is_array($i) ? ($i['path'] ?? null) : $i)
                ->filter()
                ->values()
                ->all();
        } catch (\Exception $e) {
            Log::warning("Architect: Target identification failed, falling back to all docs.");
            return Document::where('folder_id', $folder->id)->pluck('path')->take(10)->all();
        }
    }
}

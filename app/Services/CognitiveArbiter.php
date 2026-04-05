<?php

namespace App\Services;

use App\Models\SystemTask;
use Illuminate\Support\Facades\Log;

class CognitiveArbiter
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag,
        protected FileArchitectService $architect
    ) {}

    public function process(string $query, int $folderId, ?SystemTask $task = null): string
    {
        Log::info("Arkhein Cognitive Stack: Initializing pass for query", ['query' => $query]);

        if ($task) {
            $task->update(['status' => SystemTask::STATUS_RUNNING, 'started_at' => now()]);
        }

        // 1. Load Grounding Data (Level 0)
        $folder = \App\Models\ManagedFolder::find($folderId);
        $schema = $folder?->environmental_schema ?? [];
        
        // 2. Build Silo Manifest (Ground Truth)
        $allDocs = \App\Models\Document::where('folder_id', $folderId)->get(['path', 'summary']);
        $manifest = "### SILO MANIFEST (GROUND TRUTH):\n";

        if (!empty($schema['folder_map'])) {
            $manifest .= "FOLDER HIERARCHY & FILE COUNTS:\n" . implode("\n", $schema['folder_map']) . "\n\n";
            $manifest .= "TOTAL FILES IN SILO: " . ($schema['total_files'] ?? $allDocs->count()) . "\n\n";
        }

        $manifest .= "FILE LIST (Samples):\n";
        foreach ($allDocs->take(50) as $doc) {
            $manifest .= "- {$doc->path}\n";
        }

        try {
            // Level 1: Perception (Structural Analysis)
            if ($task) $task->update(['description' => 'Perceiving intent and constraints...']);
            $perception = $this->level1($query, $schema);

            // Level 1.5: Actionable Inventory (The Sovereign Coordinator)
            if (strtolower($perception['intent']) === 'inventory') {
                if ($task) $task->update(['description' => 'Querying silo inventory...']);
                $tool = new \App\Services\Tools\InventoryTool();
                $result = $tool->execute(['pattern' => $perception['entities'][0] ?? null], $folder);
                
                if ($result['success']) {
                    return $this->level6(implode("\n", $result['data']), "Direct Database Result", $perception);
                }
            }

            // Level 2: Contextualization (Tight RAG Injection)
            if ($task) $task->update(['description' => 'Retrieving high-signal context...']);
            $context = $this->level2($query, $folderId, $perception, $manifest);

            // Level 3: Decomposition (Externalized Planning)
            if ($task) $task->update(['description' => 'Decomposing task into sub-goals...']);
            $plan = $this->level3($query, $perception, $context, $schema);

            // Level 4: Reasoning (On-Demand Physical Scratchpad)
            $intent = strtolower($perception['intent'] ?? '');
            $complexity = strtolower($perception['complexity'] ?? 'low');
            
            $isWorkspaceEnabled = config('arkhein.protocols.agent_workspace_enabled');
            
            // WISE OVERRIDE: If the user says "all", "list", "every", or "count", it IS complex.
            $hasHighIntensityKeywords = preg_match('/\b(all|every|list|count|total|inventory|summarize everything)\b/i', $query);
            
            $isComplex = (in_array($intent, ['quantitative', 'structural', 'creative']) || $complexity === 'high' || $hasHighIntensityKeywords);

            Log::debug("Arkhein Cognitive decision", [
                'intent' => $intent,
                'complexity' => $complexity,
                'has_keywords' => (bool) $hasHighIntensityKeywords,
                'is_complex_match' => $isComplex
            ]);

            $usePhysical = $isWorkspaceEnabled && $isComplex;

            if ($usePhysical) {
                if ($task) $task->update(['description' => 'Thinking deeply in physical workspace...']);
                $scratchpad = $this->level4Physical($folder, $plan, $context, $perception);
            } else {
                if ($task) $task->update(['description' => 'Executing latent reasoning scratchpad...']);
                $scratchpad = $this->level4($plan, $context, $perception);
            }

            // Level 5: Self-Critique (The Reflection Pass)
            if ($task) $task->update(['description' => 'Performing self-critique and verification...']);
            $verified = $this->level5($scratchpad, $context, $plan);

            // Level 6: Generation (Final Synthesis)
            if ($task) $task->update(['description' => 'Synthesizing final response...']);
            $rawResult = $this->level6($verified, $context, $perception);

            // THE SOVEREIGN PURGE: Ensure no internal thinking blocks leak to the user
            $result = preg_replace('/<think>.*?<\/think>/s', '', $rawResult);
            $result = trim($result);

            if ($task) {
                $task->update([
                    'status' => SystemTask::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'progress' => 100,
                    'description' => 'Analysis Complete'
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            if ($task) {
                $task->update([
                    'status' => SystemTask::STATUS_FAILED,
                    'description' => 'Reasoning Pipeline Failed'
                ]);
            }
            Log::error("CognitiveArbiter Error: " . $e->getMessage());
            throw $e;
        }
    }

    protected function level1(string $query, array $schema = []): array
    {
        $schemaStr = !empty($schema) ? json_encode($schema, JSON_PRETTY_PRINT) : "No environmental schema detected.";

        $prompt = "Level 1 (Perception): Analyze the user query based on the ENVIRONMENTAL SCHEMA.
        ENVIRONMENTAL SCHEMA:
        {$schemaStr}

        QUERY: \"{$query}\"
        
        Extract:
        1. Intent (Informational, Actionable, Creative, Quantitative, INVENTORY)
           - Use INVENTORY for: 'list files', 'how many files', 'show inventory', 'list all patients'.
        2. Complexity (high|low)
        3. Context Strategy:
           - USE_MANIFEST: If user wants a list of names, a count of files, or structural overview.
           - USE_RAG: If user asks deep questions about specific content inside files.
           - HYBRID: For complex cross-document comparisons.
        4. Key Entities (Names, Projects, Dates)
        5. Constraints (Format, Tone, Language)
        
        Respond ONLY in JSON.";

        $res = $this->ollama->generate($prompt, null, ['format' => 'json']);
        $data = json_decode($res, true);

        // Robust Fallback: Try regex if direct decode failed
        if (!$data || !isset($data['intent'])) {
            if (preg_match('/\{.*\}/s', $res, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        // Final Sane Default
        return [
            'intent' => $data['intent'] ?? 'Informational',
            'complexity' => $data['complexity'] ?? 'low',
            'strategy' => $data['context_strategy'] ?? $data['strategy'] ?? 'HYBRID',
            'entities' => $data['entities'] ?? [],
            'constraints' => $data['constraints'] ?? []
        ];
    }

    protected function level2(string $query, int $folderId, array $perception, string $manifest): string
    {
        $strategy = $perception['strategy'] ?? 'HYBRID';

        // 1. MANIFEST ONLY: Clean structural view
        if ($strategy === 'USE_MANIFEST') {
            return $manifest;
        }

        // 2. RETRIEVE FRAGMENTS (For RAG or HYBRID)
        $limit = ($perception['intent'] === 'Quantitative') ? 15 : 8;
        $fragments = $this->rag->recall($query, $limit, $folderId);
        $ctx = collect($fragments)->map(fn($f) => "[{$f['metadata']['filename']}]: {$f['content']}")->implode("\n\n");

        // 3. RAG ONLY
        if ($strategy === 'USE_RAG') {
            return "### RELEVANT FRAGMENTS:\n{$ctx}";
        }

        // 4. HYBRID: Both
        return "{$manifest}\n\n### RELEVANT FRAGMENTS:\n{$ctx}";
    }

    protected function level3(string $query, array $perception, string $context, array $schema = []): string
    {
        $schemaStr = !empty($schema) ? json_encode($schema, JSON_PRETTY_PRINT) : "No schema.";

        $prompt = "Level 3 (Decomposition): Break this task into a 3-step execution plan.
        USER: \"{$query}\"
        ENVIRONMENT: {$schemaStr}
        CONTEXT: {$context}
        
        PLAN:";
        return $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.1]]);
    }
protected function level4Physical(\App\Models\ManagedFolder $folder, string $plan, string $context, array $perception): string
{
    // Use internal application storage to avoid cluttering the user's folder
    $workspaceDir = storage_path('app/arkhein/workspaces/' . $folder->id);

    \Illuminate\Support\Facades\File::ensureDirectoryExists($workspaceDir, 0777);
    $scratchpadPath = $workspaceDir . DIRECTORY_SEPARATOR . 'scratchpad.md';

    Log::info("Arkhein Laboratory: Working on physical scratchpad", ['path' => $scratchpadPath]);

    $prompt = "You are the Arkhein Analytical Agent.
...
        TASK: Perform the following PLAN using the provided CONTEXT.
        PLAN: {$plan}
        CONTEXT: {$context}
        
        INSTRUCTIONS:
        1. You are working in a PHYSICAL SCRATCHPAD file inside the system laboratory.
        2. Perform every step of the math/count/analysis now.
        3. Be extremely detailed. List each finding.
        4. Output ONLY the markdown content for the scratchpad.";

        $reasoning = $this->ollama->generate($prompt, null, [
            'options' => ['temperature' => 0.1, 'num_ctx' => 16384]
        ]);

        \Illuminate\Support\Facades\File::put($scratchpadPath, $reasoning);

        return $reasoning;
    }

    protected function level4(string $plan, string $context, array $perception): string
    {
        $intent = strtolower($perception['intent'] ?? '');
        $analyticalRule = ($intent === 'quantitative') 
            ? "MANDATE: The user is asking for a count or total. You MUST perform this math/count NOW using the provided context and manifest. Extract every matching item and sum them up. Deliver the FINAL VALUES."
            : "";

        $prompt = "Level 4 (Reasoning): Think step-by-step through the plan using the context.
        PLAN: {$plan}
        CONTEXT: {$context}
        {$analyticalRule}
        
        Write your hidden reasoning inside <think>...</think> tags.
        Then provide a draft response.";
        
        return $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.4]]);
    }

    protected function level5(string $scratchpad, string $context, string $plan): string
    {
        $prompt = "Level 5 (Self-Critique): Review your reasoning for errors or hallucinations.
        REASONING: {$scratchpad}
        CONTEXT: {$context}
        
        Is this consistent with the data? If not, correct it.
        Output ONLY the corrected reasoning and draft.";
        
        return $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.1]]);
    }

    protected function level6(string $verified, string $context, array $perception): string
    {
        $prompt = "Level 6 (Generation): Synthesize the final user-facing response.
        DATA: {$verified}
        INTENT: {$perception['intent']}
        
        RULES:
        1. Be laconic and professional.
        2. Do not show your thinking tags.
        3. Match the user's language.
        
        FINAL RESPONSE:";
        
        return $this->ollama->generate($prompt);
    }
}

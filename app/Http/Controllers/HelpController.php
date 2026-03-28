<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\OllamaService;
use App\Services\PromptService;
use App\Models\HelpInteraction;
use App\Services\GlobalRagService;
use Illuminate\Support\Facades\Log;

class HelpController extends Controller
{
    public function index()
    {
        return Inertia::render('Help', [
            'interactions' => HelpInteraction::oldest()->get(),
        ]);
    }

    public function send(
        Request $request, 
        OllamaService $ollama, 
        PromptService $prompts,
        GlobalRagService $rag
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));

        $input = $request->input('message');
        $this->saveUserMessage($input);

        // 1. DISPATCHER: Analyze Strategy
        $strategy = $this->analyzeHelpIntent($input, $ollama);
        
        // 2. RESEARCHER: Surgical Retrieval
        $knowledge = [];
        if ($strategy['intent'] === 'DATA' || $strategy['intent'] === 'BOTH') {
            $limit = $strategy['rag_limit'] ?? 10;
            $knowledge = $rag->recall($input, $limit);
        }

        // 3. SYNTHESIZER
        $messages = $this->buildChatPayload($prompts, $knowledge, $strategy);
        $assistantMessage = $ollama->chat($messages);

        $this->saveAssistantMessage($assistantMessage);

        return response()->json([
            'message' => $assistantMessage,
            'thought' => $strategy['thought'] ?? null
        ]);
    }

    public function stream(
        Request $request, 
        OllamaService $ollama, 
        PromptService $prompts,
        GlobalRagService $rag
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));
        
        $input = $request->input('message');
        $this->saveUserMessage($input);

        return response()->stream(function () use ($ollama, $prompts, $rag, $input) {
            // 1. DISPATCHER
            echo "data: " . json_encode(['event' => 'status', 'data' => 'Analyzing intent...']) . "\n\n";
            $strategy = $this->analyzeHelpIntent($input, $ollama);
            echo "data: " . json_encode(['event' => 'thought', 'data' => $strategy['thought']]) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();

            // 2. RESEARCHER
            $knowledge = [];
            if ($strategy['intent'] === 'DATA' || $strategy['intent'] === 'BOTH') {
                echo "data: " . json_encode(['event' => 'status', 'data' => 'Searching authorized silos...']) . "\n\n";
                $limit = $strategy['rag_limit'] ?? 10;
                $knowledge = $rag->recall($input, $limit);
                if (ob_get_level() > 0) ob_flush();
                flush();
            }

            // 3. SYNTHESIZER
            echo "data: " . json_encode(['event' => 'status', 'data' => 'Synthesizing response...']) . "\n\n";
            $messages = $this->buildChatPayload($prompts, $knowledge, $strategy);

            $fullResponse = "";
            $ollama->streamChat($messages, function ($chunk) use (&$fullResponse) {
                $fullResponse .= $chunk;
                echo "data: " . json_encode(['event' => 'chunk', 'data' => $chunk]) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            });

            $this->saveAssistantMessage($fullResponse);
            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }, 200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function analyzeHelpIntent(string $input, OllamaService $ollama): array
    {
        $prompt = "You are the Arkhein Dispatcher. Analyze the user query and decide if it needs software documentation (SYSTEM) or user data/files (DATA).
        
        RULES:
        - If query is about Arkhein features, settings, or 'how to use' -> SYSTEM.
        - If query mentions a specific topic, book, person, or project -> DATA.
        - If mixed -> BOTH.

        Respond with ONLY a JSON object:
        {
          \"intent\": \"SYSTEM\"|\"DATA\"|\"BOTH\",
          \"thought\": \"A 1-sentence professional reasoning\",
          \"rag_limit\": 5
        }

        QUERY: \"{$input}\"";

        try {
            $response = $ollama->generate($prompt, null, ['format' => 'json']);
            
            // Regex fallback to extract JSON if LLM added filler
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $data = json_decode($matches[0], true);
                if (isset($data['intent'])) return $data;
            }
        } catch (\Exception $e) {
            Log::error("Help Dispatcher Failed: " . $e->getMessage());
        }

        // Safe Fallback
        return [
            'intent' => 'BOTH',
            'thought' => 'Analyzing both system architecture and local documentation for maximum coverage.',
            'rag_limit' => 5
        ];
    }

    protected function saveUserMessage(string $content)
    {
        HelpInteraction::create(['role' => 'user', 'content' => $content]);
    }

    protected function saveAssistantMessage(string $content)
    {
        HelpInteraction::create(['role' => 'assistant', 'content' => $content]);
    }

    protected function buildChatPayload(PromptService $prompts, array $knowledge, array $strategy): array
    {
        $history = HelpInteraction::latest()->limit(8)->get()->reverse();
        
        // 1. Workspace Map (Awareness of Authorized Folders)
        $folders = \App\Models\ManagedFolder::all()->map(fn($f) => "- {$f->name} (Path: {$f->path})")->implode("\n");
        $workspaceMetadata = "### CURRENT WORKSPACE MAP:\n" . ($folders ?: "No folders authorized.") . "\n\n";

        // 2. Base Persona & Docs
        $basePrompt = ($strategy['intent'] !== 'DATA') 
            ? $prompts->buildHelpPrompt() 
            : "You are the Sovereign Archivist. Answer questions based on provided user data.";
        
        $prompt = "{$basePrompt}\n\n{$workspaceMetadata}";
        
        // 3. User Data / RAG
        if (!empty($knowledge)) {
            $prompt .= "### RELEVANT DOCUMENTATION (AUTHORIZED USER DATA):\n";
            $currentSource = '';
            foreach ($knowledge as $k) {
                $sourceName = $k['metadata']['filename'] ?? 'unknown';
                $folderName = $k['metadata']['folder_name'] ?? 'Silo';
                $subfolder = $k['vessel']['subfolder'] ?? '';
                $summary = $k['vessel']['summary'] ?? '';

                if ($currentSource !== $sourceName) {
                    $prompt .= "--- SOURCE: {$folderName} > " . ($subfolder ? "{$subfolder} > " : "") . "{$sourceName} ---\n";
                    if ($summary) $prompt .= "DOCUMENT SUMMARY: {$summary}\n";
                    $currentSource = $sourceName;
                }
                
                $prompt .= "FRAGMENT: " . $k['content'] . "\n\n";
            }
        } else if ($strategy['intent'] === 'DATA' || $strategy['intent'] === 'BOTH') {
            $prompt .= "### AUTHORIZED USER DATA: [EMPTY]\n";
            $prompt .= "CRITICAL: No matching documents found in authorized silos. You MUST inform the user that you cannot find this information in their files.";
        }

        $messages = [['role' => 'system', 'content' => $prompt]];
        foreach ($history as $h) {
            $messages[] = ['role' => $h->role, 'content' => $h->content];
        }

        return $messages;
    }

    public function clear()
    {
        HelpInteraction::query()->delete();
        return response()->json(['success' => true]);
    }
}

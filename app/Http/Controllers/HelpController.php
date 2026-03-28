<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use App\Services\OllamaService;
use App\Services\PromptService;
use App\Models\HelpInteraction;

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
        \App\Services\GlobalRagService $rag
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));

        $input = $request->input('message');
        $this->saveUserMessage($input);

        // 1. Analyze Intent: SYSTEM, DATA, or BOTH
        $intent = $this->analyzeHelpIntent($input, $ollama);
        Log::info("Arkhein Archivist: Help Intent -> {$intent}");

        // 2. Conditional RAG
        $knowledge = ($intent === 'DATA' || $intent === 'BOTH') ? $rag->recall($input, 10) : [];

        $messages = $this->buildChatPayload($prompts, $knowledge, $input);
        $assistantMessage = $ollama->chat($messages);

        $this->saveAssistantMessage($assistantMessage);

        return response()->json([
            'message' => $assistantMessage
        ]);
    }

    public function stream(
        Request $request,
        OllamaService $ollama,
        PromptService $prompts,
        \App\Services\GlobalRagService $rag
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));

        $input = $request->input('message');
        $this->saveUserMessage($input);

        // 1. Analyze Intent
        $intent = $this->analyzeHelpIntent($input, $ollama);
        Log::info("Arkhein Archivist: Help Intent (Stream) -> {$intent}");

        // 2. Conditional RAG
        $knowledge = ($intent === 'DATA' || $intent === 'BOTH') ? $rag->recall($input, 10) : [];

        $messages = $this->buildChatPayload($prompts, $knowledge, $input);

        return response()->stream(function () use ($ollama, $messages) {
            $fullResponse = "";
            $ollama->streamChat($messages, function ($chunk) use (&$fullResponse) {
                $fullResponse .= $chunk;
                echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
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

    protected function analyzeHelpIntent(string $input, OllamaService $ollama): string
    {
        // Simple heuristics first
        $inputLower = strtolower($input);

        // If they ask about "Arkhein", "Software", "Commands", "How to", "Help", "System"
        $systemKeywords = ['arkhein', 'software', 'command', 'how to', 'settings', 'config', 'vantage hub', 'archivist', 'memory', 'ollama'];
        $mentionsSystem = false;
        foreach ($systemKeywords as $kw) { if (str_contains($inputLower, $kw)) { $mentionsSystem = true; break; } }

        // If they ask about "My files", "Document", "Project", or specific topics found via a quick heuristic
        // We will default to BOTH if it's ambiguous, but if it's clearly about software help, we stay SYSTEM.

        $prompt = "Classify the following query into one of three categories:
        - 'SYSTEM': User is asking for help with Arkhein software, features, commands, or settings.
        - 'DATA': User is asking about their own files, documents, or content indexed in their folders.
        - 'BOTH': Query involves both system features and user data.

        QUERY: \"{$input}\"

        Respond with ONLY the category name (SYSTEM, DATA, or BOTH).";

        $response = trim($ollama->generate($prompt));

        // Fallback to heuristic if LLM fails or gives weird output
        if (!in_array($response, ['SYSTEM', 'DATA', 'BOTH'])) {
            return $mentionsSystem ? 'SYSTEM' : 'BOTH';
        }

        return $response;
    }

    protected function saveUserMessage(string $content)
    {
        HelpInteraction::create([
            'role' => 'user',
            'content' => $content
        ]);
    }

    protected function saveAssistantMessage(string $content)
    {
        HelpInteraction::create([
            'role' => 'assistant',
            'content' => $content
        ]);
    }

    protected function buildChatPayload(PromptService $prompts, array $knowledge = [], ?string $currentQuery = null): array
    {
        $history = HelpInteraction::latest()->limit(10)->get()->reverse();

        // Gather Workspace Metadata
        $folders = \App\Models\ManagedFolder::all()->map(fn($f) => "- {$f->name} (Path: {$f->path})")->implode("\n");
        $verticals = \App\Models\Vertical::all()->map(fn($v) => "- {$v->name} (Connected to: {$v->folder?->name})")->implode("\n");

        $metadata = "### CURRENT WORKSPACE MAP:\n";
        $metadata .= "Authorized Folders:\n" . ($folders ?: "None") . "\n\n";
        $metadata .= "Active Vantage Cards:\n" . ($verticals ?: "None") . "\n";

        $systemPrompt = $prompts->buildHelpPrompt() . "\n\n" . $metadata;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        // Add history (except the last message if we are going to append knowledge to it)
        $historyItems = $history->values();
        for ($i = 0; $i < count($historyItems) - ($currentQuery ? 1 : 0); $i++) {
            $item = $historyItems[$i];
            $messages[] = ['role' => $item->role, 'content' => $item->content];
        }

        // Append RAG knowledge to the current query
        if ($currentQuery) {
            $ctx = "";
            if (!empty($knowledge)) {
                $ctx = "### RELEVANT DOCUMENTATION & SYSTEM CONTEXT:\n";
                foreach ($knowledge as $k) {
                    $ctx .= "- " . $k['content'] . "\n";
                }
                $ctx .= "\n\nUser Query: ";
            }
            $messages[] = ['role' => 'user', 'content' => $ctx . $currentQuery];
        }

        return $messages;
    }

    public function clear()
    {
        HelpInteraction::query()->delete();
        return response()->json(['success' => true]);
    }
}

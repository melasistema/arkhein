<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
        PromptService $prompts
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));

        $input = $request->input('message');
        $this->saveUserMessage($input);

        $messages = $this->buildChatPayload($prompts);
        $assistantMessage = $ollama->chat($messages);

        $this->saveAssistantMessage($assistantMessage);

        return response()->json([
            'message' => $assistantMessage
        ]);
    }

    public function stream(
        Request $request, 
        OllamaService $ollama, 
        PromptService $prompts
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));
        
        $input = $request->input('message');
        $this->saveUserMessage($input);

        $messages = $this->buildChatPayload($prompts);

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
            'X-Accel-Buffering' => 'no', // Disable buffering for Nginx
        ]);
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

    protected function buildChatPayload(PromptService $prompts): array
    {
        $history = HelpInteraction::latest()->limit(10)->get()->reverse();
        $systemPrompt = $prompts->buildHelpPrompt();
        
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
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

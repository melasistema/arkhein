<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Setting;
use App\Services\OllamaService;
use App\Services\PromptService;
use App\Models\HelpSession;

class HelpController extends Controller
{
    public function index()
    {
        // Always use a single, unified help session
        $session = HelpSession::firstOrCreate(
            ['title' => 'System Guide'],
            ['id' => \Illuminate\Support\Str::uuid()]
        );

        return Inertia::render('Help', [
            'session' => $session->load('interactions'),
        ]);
    }

    public function send(
        Request $request, 
        OllamaService $ollama, 
        PromptService $prompts
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));

        $input = $request->input('message');
        
        // Find the single help session
        $session = HelpSession::where('title', 'System Guide')->firstOrFail();

        // 1. Build Context & Settings
        $model = Setting::get('llm_model', config('services.ollama.model'));

        // 2. Save User Interaction
        $session->interactions()->create([
            'role' => 'user', 
            'content' => $input
        ]);

        // 3. Build History Context
        $history = $session->interactions()->latest()->limit(10)->get()->reverse();
        $historyContext = "RECENT CONVERSATION:\n";
        foreach ($history as $h) {
            $historyContext .= strtoupper($h->role) . ": " . $h->content . "\n";
        }

        // 4. System Prompt
        $systemPrompt = $prompts->buildHelpPrompt();
        $finalPrompt = "System: $systemPrompt\n\n$historyContext\n\nAssistant:";

        // 5. Generate Response
        $response = $ollama->generate($model, $finalPrompt);
        $assistantMessage = $response['response'] ?? "I'm sorry, I couldn't generate a response.";

        // 6. Save Assistant Response
        $session->interactions()->create([
            'role' => 'assistant',
            'content' => $assistantMessage
        ]);

        return response()->json([
            'message' => $assistantMessage
        ]);
    }

    public function clear()
    {
        $session = HelpSession::where('title', 'System Guide')->first();
        if ($session) {
            $session->interactions()->delete();
        }

        return response()->json(['success' => true]);
    }
}

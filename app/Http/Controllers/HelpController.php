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
        return Inertia::render('Help', [
            'sessions' => HelpSession::latest()->get(),
        ]);
    }

    public function start(Request $request)
    {
        $request->validate(['title' => 'required|string|max:255']);
        $session = HelpSession::create(['title' => $request->title]);
        return response()->json($session);
    }

    public function history(HelpSession $session)
    {
        return response()->json([
            'interactions' => $session->interactions()->oldest()->get()
        ]);
    }

    public function send(
        Request $request, 
        OllamaService $ollama, 
        PromptService $prompts
    ) {
        set_time_limit(config('arkhein.boundaries.execution_timeout', 300));

        $input = $request->input('message');
        $sessionId = $request->input('session_id');
        $session = HelpSession::findOrFail($sessionId);

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

        // 4. System Prompt (Dedicated Help Persona)
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
            'message' => $assistantMessage,
            'intent' => 'HELP'
        ]);
    }
}

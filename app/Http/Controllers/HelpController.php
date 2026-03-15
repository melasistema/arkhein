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
        
        // 1. Save User Interaction
        HelpInteraction::create([
            'role' => 'user', 
            'content' => $input
        ]);

        // 2. Build History Context
        $history = HelpInteraction::latest()->limit(10)->get()->reverse();
        $historyContext = "RECENT CONVERSATION:\n";
        foreach ($history as $h) {
            $historyContext .= strtoupper($h->role) . ": " . $h->content . "\n";
        }

        // 3. System Prompt
        $systemPrompt = $prompts->buildHelpPrompt();
        $finalPrompt = "System: $systemPrompt\n\n$historyContext\n\nAssistant:";

        // 4. Generate Response (OllamaService handles config internally)
        $assistantMessage = $ollama->generate($finalPrompt);

        // 5. Save Assistant Response
        HelpInteraction::create([
            'role' => 'assistant',
            'content' => $assistantMessage
        ]);

        return response()->json([
            'message' => $assistantMessage
        ]);
    }

    public function clear()
    {
        HelpInteraction::query()->delete();
        return response()->json(['success' => true]);
    }
}

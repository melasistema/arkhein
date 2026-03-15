<?php

namespace Tests\Feature;

use App\Models\HelpSession;
use App\Models\HelpInteraction;
use Illuminate\Support\Facades\Http;

test('it initializes with a default system guide session', function () {
    $response = $this->get(route('help'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Help')
        ->has('session')
        ->where('session.title', 'System Guide')
    );

    expect(HelpSession::count())->toBe(1);
});

test('it persists help interactions', function () {
    Http::fake([
        '*/api/generate' => Http::response(['response' => 'Arkhein is a local-first agent.'], 200),
    ]);

    // Ensure session exists
    $this->get(route('help'));

    $response = $this->postJson(route('help.send'), [
        'message' => 'What is Arkhein?'
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'Arkhein is a local-first agent.');

    expect(HelpInteraction::count())->toBe(2); // User + Assistant
    expect(HelpInteraction::where('role', 'user')->first()->content)->toBe('What is Arkhein?');
});

test('it can clear help history', function () {
    $session = HelpSession::firstOrCreate(['title' => 'System Guide']);
    $session->interactions()->delete(); // Ensure it's empty
    
    $session->interactions()->create(['role' => 'user', 'content' => 'Hello']);

    expect($session->interactions()->count())->toBe(1);

    $response = $this->postJson(route('help.clear'));

    $response->assertStatus(200);
    expect($session->interactions()->count())->toBe(0);
});

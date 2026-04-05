<?php

namespace Tests\Feature;

use App\Models\HelpInteraction;
use Illuminate\Support\Facades\Http;

test('it initializes with an empty stream', function () {
    $response = $this->get(route('help'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Help')
        ->has('interactions', 0)
    );

    expect(HelpInteraction::count())->toBe(0); 
});

test('it persists help interactions', function () {
    Http::fake([
        '*/api/generate' => Http::response(['intent' => 'SYSTEM', 'thought' => 'Testing'], 200),
        '*/api/chat' => Http::response(['message' => ['content' => 'Arkhein is a local-first agent.']], 200),
        '*/api/embeddings' => Http::response(['embedding' => array_fill(0, 768, 0.1)], 200),
    ]);

    $this->get(route('help')); // Initialize for side effects

    $response = $this->postJson(route('help.send'), [
        'message' => 'What is Arkhein?'
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'Arkhein is a local-first agent.');

    expect(HelpInteraction::count())->toBe(2); // User + Assistant
    expect(HelpInteraction::where('role', 'user')->first()->content)->toBe('What is Arkhein?');
});

test('it can clear help history', function () {
    HelpInteraction::create(['role' => 'user', 'content' => 'Hello']);
    expect(HelpInteraction::count())->toBe(1);

    $response = $this->postJson(route('help.clear'));

    $response->assertStatus(200);
    expect(HelpInteraction::count())->toBe(0);
});

<?php

namespace Tests\Feature;

use App\Models\ManagedFolder;
use App\Models\Vertical;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

test('sovereign configuration is loaded correctly', function () {
    expect(config('arkhein.identity.name'))->toBe('Arkhein');
    expect(config('arkhein.vantage.recall_limit'))->toBeInt();
});

test('it can manage vertical silos', function () {
    $folder = ManagedFolder::create([
        'name' => 'Research',
        'path' => '/Users/test/Research'
    ]);

    $vertical = Vertical::create([
        'name' => 'Project Hive',
        'type' => 'rag',
        'folder_id' => $folder->id
    ]);

    expect(Vertical::count())->toBe(1);
    expect($vertical->folder->name)->toBe('Research');
});

test('it can persist and retrieve smart settings', function () {
    Setting::set('llm_model', 'qwen3:8b');
    Setting::set('embedding_dimensions', 2560);

    expect(Setting::get('llm_model'))->toBe('qwen3:8b');
    expect((int)Setting::get('embedding_dimensions'))->toBe(2560);
});

test('vantage query handles ollama failure gracefully', function () {
    Http::fake([
        '*/api/generate' => Http::response(['error' => 'Connection refused'], 500),
        '*/api/embeddings' => Http::response([], 200),
    ]);

    $vertical = Vertical::create([
        'name' => 'Test Vertical',
        'type' => 'rag'
    ]);

    $response = $this->postJson(route('verticals.query', ['vertical' => $vertical->id]), [
        'query' => 'What is Arkhein?'
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('response', "I am currently unable to reach the inference engine. Please check the system log.");
});

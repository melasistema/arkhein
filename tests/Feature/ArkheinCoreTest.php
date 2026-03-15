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

test('deleting a vertical purges folder knowledge if it is no longer referenced', function () {
    $folder = ManagedFolder::create([
        'name' => 'Vault',
        'path' => '/Users/test/Vault'
    ]);

    $vertical = Vertical::create([
        'name' => 'Vault Card',
        'type' => 'rag',
        'folder_id' => $folder->id
    ]);

    \App\Models\Knowledge::on('nativephp')->create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'type' => 'file',
        'content' => 'chunk',
        'embedding' => array_fill(0, 3, 0.1),
        'metadata' => ['folder_id' => $folder->id, 'path' => '/Users/test/Vault/a.txt'],
        'importance' => 1,
    ]);

    expect(\App\Models\Knowledge::on('nativephp')->count())->toBe(1);

    $this->deleteJson(route('verticals.destroy', ['vertical' => $vertical->id]))
        ->assertOk();

    expect(Vertical::count())->toBe(0);
    expect(\App\Models\Knowledge::on('nativephp')->count())->toBe(0);
});

test('deleting a vertical does not purge folder knowledge if another vertical still references it', function () {
    $folder = ManagedFolder::create([
        'name' => 'Shared',
        'path' => '/Users/test/Shared'
    ]);

    $v1 = Vertical::create([
        'name' => 'Card One',
        'type' => 'rag',
        'folder_id' => $folder->id
    ]);

    $v2 = Vertical::create([
        'name' => 'Card Two',
        'type' => 'rag',
        'folder_id' => $folder->id
    ]);

    \App\Models\Knowledge::on('nativephp')->create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'type' => 'file',
        'content' => 'chunk',
        'embedding' => array_fill(0, 3, 0.1),
        'metadata' => ['folder_id' => $folder->id, 'path' => '/Users/test/Shared/a.txt'],
        'importance' => 1,
    ]);

    $this->deleteJson(route('verticals.destroy', ['vertical' => $v1->id]))
        ->assertOk();

    expect(Vertical::count())->toBe(1);
    expect(Vertical::first()->id)->toBe($v2->id);
    expect(\App\Models\Knowledge::on('nativephp')->count())->toBe(1);
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

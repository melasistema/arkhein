<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\OllamaStatusController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VerticalController;
use Inertia\Inertia;

Route::get('/', function() {
    return redirect()->route('dashboard');
});

Route::middleware([])->group(function () {
    Route::get('/dashboard', function (Request $request) {
        $data = [
            'status' => [
                'is_reconciling' => \App\Models\Setting::get('system_reconcile_status') === 'running',
                'reconcile_progress' => (int) \App\Models\Setting::get('system_reconcile_progress', 0),
                'is_indexing_any' => \App\Models\ManagedFolder::where('is_indexing', true)->exists(),
            ],
            'stats' => [
                'verticals_count' => \App\Models\Vertical::count(),
                'folders_count' => \App\Models\ManagedFolder::count(),
                'knowledge_count' => \App\Models\Knowledge::count(),
                'latest_fragments' => \App\Models\Knowledge::on('nativephp')
                    ->latest()
                    ->limit(5)
                    ->get(['content', 'type', 'metadata'])
            ]
        ];

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('Dashboard', $data);
    })->name('dashboard');

    // System Monitor
    Route::get('/system/heartbeat', [\App\Http\Controllers\SystemStatusController::class, 'heartbeat'])->name('system.heartbeat');

    // Help Module
    Route::get('/help', [HelpController::class, 'index'])->name('help');
    Route::post('/help/send', [HelpController::class, 'send'])->name('help.send');
    Route::post('/help/stream', [HelpController::class, 'stream'])->name('help.stream');
    Route::post('/help/clear', [HelpController::class, 'clear'])->name('help.clear');

    // Vantage Module
    Route::get('/vantage', function () {
        return Inertia::render('Vantage', [
            'verticals' => \App\Models\Vertical::with(['folder', 'interactions' => function($q) {
                $q->latest()->limit(50);
            }])->get()
        ]);
    })->name('vantage');

    Route::delete('/verticals/{vertical}/history', [VerticalController::class, 'clearHistory'])->name('verticals.history.clear');
    
    // Core Utilities
    Route::get('/ollama/status', [OllamaStatusController::class, 'check'])->name('ollama.status');
    
    // Verticals Management
    Route::get('/verticals', [VerticalController::class, 'index'])->name('verticals.index');
    Route::post('/verticals', [VerticalController::class, 'store'])->name('verticals.store');
    Route::patch('/verticals/{vertical}', [VerticalController::class, 'update'])->name('verticals.update');
    Route::delete('/verticals/{vertical}', [VerticalController::class, 'destroy'])->name('verticals.destroy');
    Route::post('/verticals/{vertical}/sync', [VerticalController::class, 'sync'])->name('verticals.sync');
    Route::post('/verticals/{vertical}/query', [VerticalController::class, 'query'])->name('verticals.query');
    Route::post('/verticals/{vertical}/stream', [VerticalController::class, 'streamQuery'])->name('verticals.stream');
    Route::post('/verticals/{vertical}/action', [VerticalController::class, 'executeAction'])->name('verticals.action.execute');

    // Chat Suggestions
    Route::get('/chat/suggestions', [\App\Http\Controllers\ChatController::class, 'suggestions'])->name('chat.suggestions');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/sync', [SettingsController::class, 'sync'])->name('settings.sync');
    Route::post('/settings/rebuild', [SettingsController::class, 'rebuild'])->name('settings.rebuild');
    Route::post('/settings/purge-cot', [SettingsController::class, 'purgeChainOfThought'])->name('settings.purge-cot');
    Route::post('/settings/folders', [SettingsController::class, 'addFolder'])->name('settings.folders.add');
    Route::post('/settings/folders/{folder}/toggle-visual', [SettingsController::class, 'toggleVisualIndexing'])->name('settings.folders.toggle-visual');
    Route::delete('/settings/folders/{folder}', [SettingsController::class, 'removeFolder'])->name('settings.folders.remove');
});

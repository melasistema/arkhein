<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

use App\Http\Controllers\ChatController;
use App\Http\Controllers\OllamaStatusController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VerticalController;
use Inertia\Inertia;

Route::get('/', [ChatController::class, 'index'])->name('chat');

Route::middleware([])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard', [
            'stats' => [
                'verticals_count' => \App\Models\Vertical::count(),
                'folders_count' => \App\Models\ManagedFolder::count(),
                'knowledge_count' => \App\Models\Knowledge::count(),
            ]
        ]);
    })->name('dashboard');

    Route::get('/vantage', function () {
        return Inertia::render('Vantage', [
            'verticals' => \App\Models\Vertical::with(['folder', 'interactions' => function($q) {
                $q->latest()->limit(50);
            }])->get()
        ]);
    })->name('vantage');

    Route::delete('/verticals/{vertical}/history', [VerticalController::class, 'clearHistory'])->name('verticals.history.clear');
    Route::get('/chat/suggestions', [ChatController::class, 'suggestions'])->name('chat.suggestions');
    Route::post('/chat/start', [ChatController::class, 'start'])->name('chat.start');
    Route::get('/chat/history/{conversation}', [ChatController::class, 'history'])->name('chat.history');
    Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
    Route::post('/chat/action/execute', [ChatController::class, 'executePendingAction'])->name('chat.action.execute');
    Route::get('/ollama/status', [OllamaStatusController::class, 'check'])->name('ollama.status');
    
    // Verticals (Specialized AI Cards)
    Route::get('/verticals', [VerticalController::class, 'index'])->name('verticals.index');
    Route::post('/verticals', [VerticalController::class, 'store'])->name('verticals.store');
    Route::patch('/verticals/{vertical}', [VerticalController::class, 'update'])->name('verticals.update');
    Route::delete('/verticals/{vertical}', [VerticalController::class, 'destroy'])->name('verticals.destroy');
    Route::post('/verticals/{vertical}/sync', [VerticalController::class, 'sync'])->name('verticals.sync');
    Route::post('/verticals/{vertical}/query', [VerticalController::class, 'query'])->name('verticals.query');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/sync', [SettingsController::class, 'sync'])->name('settings.sync');
    Route::post('/settings/rebuild', [SettingsController::class, 'rebuild'])->name('settings.rebuild');
    Route::post('/settings/folders', [SettingsController::class, 'addFolder'])->name('settings.folders.add');
    Route::delete('/settings/folders/{folder}', [SettingsController::class, 'removeFolder'])->name('settings.folders.remove');
});

// require __DIR__.'/settings.php';

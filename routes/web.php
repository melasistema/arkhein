<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

use App\Http\Controllers\ChatController;
use App\Http\Controllers\OllamaStatusController;
use App\Http\Controllers\SettingsController;

Route::get('/', [ChatController::class, 'index'])->name('chat');

Route::middleware([])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('/chat/suggestions', [ChatController::class, 'suggestions'])->name('chat.suggestions');
    Route::post('/chat/start', [ChatController::class, 'start'])->name('chat.start');
    Route::get('/chat/history/{conversation}', [ChatController::class, 'history'])->name('chat.history');
    Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
    Route::post('/chat/action/execute', [ChatController::class, 'executePendingAction'])->name('chat.action.execute');
    Route::get('/ollama/status', [OllamaStatusController::class, 'check'])->name('ollama.status');
    
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/sync', [SettingsController::class, 'sync'])->name('settings.sync');
    Route::post('/settings/folders', [SettingsController::class, 'addFolder'])->name('settings.folders.add');
    Route::delete('/settings/folders/{folder}', [SettingsController::class, 'removeFolder'])->name('settings.folders.remove');
});

// require __DIR__.'/settings.php';

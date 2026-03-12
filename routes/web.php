<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

use App\Http\Controllers\ChatController;
use App\Http\Controllers\OllamaStatusController;
use App\Http\Controllers\SettingsController;

Route::get('/', [ChatController::class, 'index'])->name('chat');

Route::middleware([])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
    Route::get('/ollama/status', [OllamaStatusController::class, 'check'])->name('ollama.status');
    
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

// require __DIR__.'/settings.php';

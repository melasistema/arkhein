<?php

namespace App\Services;

use App\Models\UserInsight;
use Native\Laravel\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProactiveService
{
    public function __construct(
        protected OllamaService $ollama
    ) {}

    /**
     * The Heartbeat: Perform a check of all active habits.
     */
    public function pulse(): void
    {
        if (!config('proactive.enabled')) return;

        $minImportance = config('proactive.min_importance', 5);

        $habits = UserInsight::on('nativephp')
            ->where('type', 'habit')
            ->where('importance', '>=', $minImportance)
            ->get();

        if ($habits->isEmpty()) return;

        foreach ($habits as $habit) {
            $this->evaluateHabit($habit);
        }
    }

    /**
     * Determine if a specific habit should trigger now.
     */
    protected function evaluateHabit(UserInsight $habit): void
    {
        $now = Carbon::now();
        
        if (config('proactive.heuristics.time_detection')) {
            if ($this->isTimeMatch($habit->content, $now)) {
                $this->triggerNotification($habit);
            }
        }
    }

    /**
     * Check if the habit content mentions a time that matches current time.
     */
    protected function isTimeMatch(string $content, Carbon $time): bool
    {
        // Simple regex for HH:MM format
        if (preg_match('/(\d{1,2}:\d{2})/', $content, $matches)) {
            $habitTime = $matches[1];
            return $time->format('H:i') === $habitTime;
        }
        return false;
    }

    /**
     * Dispatch a Native macOS Notification.
     */
    protected function triggerNotification(UserInsight $habit): void
    {
        $cooldown = config('proactive.notification_cooldown', 60);
        $cacheKey = "proactive_notified_{$habit->id}";

        if (cache()->has($cacheKey)) return;

        Notification::new()
            ->title('Arkhein Suggestion')
            ->message("It's time for: " . $habit->content)
            ->show();

        cache()->put($cacheKey, true, now()->addMinutes($cooldown));
        
        Log::info("Arkhein: Proactive notification sent for habit [{$habit->id}]");
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\OllamaService;
use Illuminate\Http\Request;

class OllamaStatusController extends Controller
{
    public function check(OllamaService $ollama)
    {
        $models = $ollama->tags();
        $isOnline = !empty($models) || $this->ping($ollama);

        return response()->json([
            'online' => $isOnline,
            'models' => $models,
            'host' => config('services.ollama.host'),
        ]);
    }

    protected function ping(OllamaService $ollama)
    {
        // Simple health check if tags is empty but service might be up
        try {
            $host = config('services.ollama.host');
            $fp = @fsockopen(parse_url($host, PHP_URL_HOST), parse_url($host, PHP_URL_PORT) ?: 11434, $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);
                return true;
            }
        } catch (\Exception $e) {}
        
        return false;
    }
}

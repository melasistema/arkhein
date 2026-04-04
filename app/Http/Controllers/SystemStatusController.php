<?php

namespace App\Http\Controllers;

use App\Models\ManagedFolder;
use App\Models\Setting;
use Illuminate\Http\Request;

class SystemStatusController extends Controller
{
    /**
     * Get the holistic system heartbeat status.
     */
    public function heartbeat()
    {
        // Fetch ALL folders that have any non-idle activity
        $activeFolders = ManagedFolder::where('sync_status', '!=', ManagedFolder::STATUS_IDLE)
            ->get(['id', 'name', 'indexing_progress', 'current_indexing_file', 'sync_status']);

        $isReconciling = Setting::get('system_reconcile_status') === 'running';
        
        return response()->json([
            'is_busy' => $activeFolders->isNotEmpty() || $isReconciling,
            'is_indexing' => $activeFolders->contains('sync_status', ManagedFolder::STATUS_INDEXING),
            'is_drafting' => $activeFolders->contains('sync_status', 'drafting'),
            'is_reconciling' => $isReconciling,
            'indexing_count' => $activeFolders->count(),
            'reconcile_progress' => (int) Setting::get('system_reconcile_progress', 0),
            'details' => [
                'folders' => $activeFolders,
                'status_text' => $this->buildStatusText($activeFolders, $isReconciling)
            ]
        ]);
    }

    protected function buildStatusText($folders, $isReconciling): string
    {
        if ($isReconciling) return "Reconciling Sovereign Memory...";
        
        if ($folders->isNotEmpty()) {
            $drafting = $folders->where('sync_status', 'drafting');
            $indexing = $folders->where('sync_status', ManagedFolder::STATUS_INDEXING);
            $queued = $folders->where('sync_status', ManagedFolder::STATUS_QUEUED);

            if ($drafting->isNotEmpty()) {
                $count = $drafting->count();
                $name = $drafting->first()->name;
                return $count === 1 ? "Drafting @{$name}..." : "Drafting {$count} documents...";
            }

            if ($indexing->isNotEmpty()) {
                $count = $indexing->count();
                $name = $indexing->first()->name;
                return $count === 1 ? "Indexing @{$name}..." : "Indexing {$count} silos...";
            }

            return "{$queued->count()} tasks waiting in queue...";
        }

        return "Ready";
    }
}

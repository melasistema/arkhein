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
        $activeFolders = ManagedFolder::whereIn('sync_status', [
            ManagedFolder::STATUS_INDEXING, 
            ManagedFolder::STATUS_QUEUED,
            'drafting'
        ])->get(['id', 'name', 'indexing_progress', 'current_indexing_file', 'sync_status']);

        $isReconciling = Setting::get('system_reconcile_status') === 'running';
        
        return response()->json([
            'is_busy' => $activeFolders->isNotEmpty() || $isReconciling,
            'is_indexing' => $activeFolders->contains('sync_status', ManagedFolder::STATUS_INDEXING),
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
            $indexing = $folders->where('sync_status', ManagedFolder::STATUS_INDEXING);
            $queued = $folders->where('sync_status', ManagedFolder::STATUS_QUEUED);
            $drafting = $folders->where('sync_status', 'drafting');

            if ($drafting->isNotEmpty()) {
                if ($drafting->count() === 1) return "Drafting @{$drafting->first()->name}...";
                return "Drafting multiple documents...";
            }

            if ($indexing->isNotEmpty()) {
                if ($indexing->count() === 1) return "Indexing @{$indexing->first()->name}...";
                return "Indexing {$indexing->count()} authorized silos...";
            }

            return "Tasks queued for {$queued->count()} folders...";
        }

        return "Ready";
    }
}

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
        $indexingFolders = ManagedFolder::where('is_indexing', true)->get(['id', 'name', 'indexing_progress', 'current_indexing_file']);
        $isReconciling = Setting::get('system_reconcile_status') === 'running';
        
        return response()->json([
            'is_busy' => $indexingFolders->isNotEmpty() || $isReconciling,
            'is_indexing' => $indexingFolders->isNotEmpty(),
            'is_reconciling' => $isReconciling,
            'indexing_count' => $indexingFolders->count(),
            'reconcile_progress' => (int) Setting::get('system_reconcile_progress', 0),
            'details' => [
                'folders' => $indexingFolders,
                'status_text' => $this->buildStatusText($indexingFolders, $isReconciling)
            ]
        ]);
    }

    protected function buildStatusText($folders, $isReconciling): string
    {
        if ($isReconciling) return "Reconciling Sovereign Memory...";
        if ($folders->isNotEmpty()) {
            if ($folders->count() === 1) return "Indexing @{$folders->first()->name}...";
            return "Indexing {$folders->count()} authorized silos...";
        }
        return "Ready";
    }
}

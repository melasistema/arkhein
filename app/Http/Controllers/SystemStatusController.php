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
        // Lazy Reconciliation: Check for drift on every heartbeat
        app(\App\Services\SiloIntegrityService::class)->checkAll();

        // 1. Fetch all tasks that are not in a final state
        $activeTasks = \App\Models\SystemTask::whereIn('status', [
            \App\Models\SystemTask::STATUS_QUEUED, 
            \App\Models\SystemTask::STATUS_RUNNING
        ])->orderBy('created_at', 'asc')->get();

        // 2. Fetch stale folders
        $staleFolders = ManagedFolder::where('sync_status', ManagedFolder::STATUS_STALE)->get();

        $isReconciling = Setting::get('system_reconcile_status') === 'running';
        
        return response()->json([
            'is_busy' => $activeTasks->isNotEmpty() || $isReconciling || $staleFolders->isNotEmpty(),
            'is_indexing' => $activeTasks->contains('type', 'sync'),
            'is_drafting' => $activeTasks->contains('type', 'drafting'),
            'is_stale' => $staleFolders->isNotEmpty(),
            'is_reconciling' => $isReconciling,
            'task_count' => $activeTasks->count(),
            'reconcile_progress' => (int) Setting::get('system_reconcile_progress', 0),
            'details' => [
                'tasks' => $activeTasks,
                'stale_folders' => $staleFolders,
                'status_text' => $this->buildStatusText($activeTasks, $isReconciling, $staleFolders)
            ]
        ]);
    }

    protected function buildStatusText($tasks, $isReconciling, $staleFolders = null): string
    {
        $staleFolders = $staleFolders ?? collect();
        
        if ($isReconciling) return "Reconciling Sovereign Memory...";
        
        if ($tasks->isNotEmpty()) {
            $running = $tasks->where('status', \App\Models\SystemTask::STATUS_RUNNING);
            
            if ($running->isNotEmpty()) {
                $current = $running->first();
                return $current->description . "...";
            }

            return "{$tasks->count()} tasks waiting in queue...";
        }

        if ($staleFolders->isNotEmpty()) {
            return "Changes detected in " . $staleFolders->count() . " silos. Sync recommended.";
        }

        return "Ready";
    }
}

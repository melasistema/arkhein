<?php

namespace App\Http\Controllers;

use App\Models\ManagedFolder;
use App\Models\Vertical;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class ChatController extends Controller
{
    /**
     * Provide intelligent suggestions for '@' and '/' triggers.
     */
    public function suggestions(Request $request)
    {
        $items = [];

        // 1. Folders (for @mentions)
        $folders = ManagedFolder::all();
        foreach ($folders as $folder) {
            $items[] = [
                'type' => 'folder',
                'name' => $folder->name,
                'path' => $folder->path,
                'id' => $folder->id
            ];
        }

        // 2. Verticals (for @mentions)
        $verticals = Vertical::all();
        foreach ($verticals as $vertical) {
            $items[] = [
                'type' => 'vertical',
                'name' => $vertical->name,
                'description' => "Vantage Card ({$vertical->type})",
                'id' => $vertical->id
            ];
        }

        return response()->json([
            'items' => $items
        ]);
    }
}

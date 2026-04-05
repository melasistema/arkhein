<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFolder extends Model
{
    protected $connection = 'nativephp';

    const STATUS_IDLE = 'idle';
    const STATUS_QUEUED = 'queued';
    const STATUS_INDEXING = 'indexing';
    const STATUS_STALE = 'stale';
    const STATUS_DRAFTING = 'drafting';

    protected $fillable = ['path', 'name', 'last_indexed_at', 'is_indexing', 'indexing_progress', 'current_indexing_file', 'binary_hash', 'allow_visual_indexing', 'sync_status', 'environmental_schema', 'disk_signature'];

    protected $casts = [
        'allow_visual_indexing' => 'boolean',
        'is_indexing' => 'boolean',
        'last_indexed_at' => 'datetime',
        'environmental_schema' => 'array',
    ];

    public function verticals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Vertical::class, 'folder_id');
    }
}

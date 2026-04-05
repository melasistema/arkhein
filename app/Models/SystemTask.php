<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemTask extends Model
{
    use HasUuids;

    protected $connection = 'nativephp';

    const STATUS_QUEUED = 'queued';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'status',
        'folder_id',
        'description',
        'progress',
        'metadata',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ManagedFolder::class, 'folder_id');
    }

    public static function createInSilo(int $folderId, string $type, string $description): self
    {
        return self::create([
            'folder_id' => $folderId,
            'type' => $type,
            'description' => $description,
            'status' => self::STATUS_QUEUED
        ]);
    }
}

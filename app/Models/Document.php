<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasUuids;

    protected $connection = 'nativephp';

    protected $fillable = [
        'folder_id',
        'path',
        'filename',
        'extension',
        'summary',
        'checksum',
        'metadata',
        'last_indexed_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_indexed_at' => 'datetime',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ManagedFolder::class, 'folder_id');
    }

    public function fragments(): HasMany
    {
        return $this->hasMany(Knowledge::class, 'document_id');
    }
}

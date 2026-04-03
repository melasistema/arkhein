<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFolder extends Model
{
    protected $connection = 'nativephp';

    protected $fillable = ['path', 'name', 'last_indexed_at', 'is_indexing', 'indexing_progress', 'current_indexing_file', 'binary_hash', 'allow_visual_indexing'];

    protected $casts = [
        'allow_visual_indexing' => 'boolean',
        'is_indexing' => 'boolean',
        'last_indexed_at' => 'datetime',
    ];

    public function verticals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Vertical::class, 'folder_id');
    }
}

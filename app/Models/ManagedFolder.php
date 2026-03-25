<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFolder extends Model
{
    protected $connection = 'nativephp';

    protected $fillable = ['path', 'name', 'last_indexed_at', 'is_indexing'];

    public function verticals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Vertical::class, 'folder_id');
    }
}

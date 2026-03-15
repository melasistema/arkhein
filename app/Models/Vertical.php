<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vertical extends Model
{
    protected $connection = 'nativephp';

    protected $fillable = [
        'name',
        'type',
        'folder_id',
        'settings'
    ];

    protected $casts = [
        'settings' => 'array'
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ManagedFolder::class, 'folder_id');
    }

    public function interactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VantageInteraction::class);
    }
}

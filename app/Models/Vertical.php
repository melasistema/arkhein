<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vertical extends Model
{
    use HasFactory;
    protected $keyType = 'int';
    public $incrementing = true;

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
        return $this->hasMany(VerticalInteraction::class, 'vertical_id', 'id');
    }
}

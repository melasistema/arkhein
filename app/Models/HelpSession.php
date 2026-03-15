<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HelpSession extends Model
{
    use HasUuids;

    protected $connection = 'nativephp';

    protected $fillable = ['title', 'summary', 'embedding', 'metadata'];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];

    public function interactions(): HasMany
    {
        return $this->hasMany(HelpInteraction::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInsight extends Model
{
    protected $fillable = [
        'type', 
        'content', 
        'embedding',
        'metadata',
        'importance', 
        'occurrence_count', 
        'last_observed_at'
    ];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
        'last_observed_at' => 'datetime',
    ];
}

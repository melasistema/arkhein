<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpInteraction extends Model
{
    protected $fillable = ['role', 'content', 'embedding', 'metadata'];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];
}

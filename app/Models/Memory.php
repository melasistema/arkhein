<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    protected $fillable = ['vektor_id', 'content', 'embedding', 'metadata'];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];
}

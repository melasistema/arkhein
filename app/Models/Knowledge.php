<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Knowledge extends Model
{
    use HasUuids;

    protected $connection = 'nativephp';

    protected $table = 'knowledge';

    protected $fillable = [
        'type',
        'content',
        'embedding',
        'metadata',
        'importance'
    ];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerticalInteraction extends Model
{
    protected $fillable = [
        'vertical_id',
        'role',
        'content',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }
}

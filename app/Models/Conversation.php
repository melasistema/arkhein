<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'summary', 'embedding', 'metadata'];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}

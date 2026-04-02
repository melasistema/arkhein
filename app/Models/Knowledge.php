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
        'document_id',
        'type',
        'mime_type',
        'content',
        'embedding',
        'metadata',
        'importance'
    ];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];

    public function document(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}

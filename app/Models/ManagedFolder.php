<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFolder extends Model
{
    protected $fillable = ['path', 'name', 'last_indexed_at'];
}

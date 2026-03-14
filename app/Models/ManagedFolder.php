<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFolder extends Model
{
    protected $connection = 'nativephp';

    protected $fillable = ['path', 'name', 'last_indexed_at'];
}

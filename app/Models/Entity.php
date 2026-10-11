<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Internal persistence models. Controllers pass explicitly validated attributes only. */
abstract class Entity extends Model
{
    protected $guarded = [];
}

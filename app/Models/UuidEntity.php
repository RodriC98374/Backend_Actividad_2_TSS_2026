<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

abstract class UuidEntity extends Entity
{
    use HasUuids;
}

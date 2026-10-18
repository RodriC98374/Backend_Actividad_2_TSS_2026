<?php

namespace App\Models;

class ChangeLog extends Entity
{
    protected $table = 'change_log';

    public $timestamps = false;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['id' => 'string', 'before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }
}

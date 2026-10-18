<?php

namespace App\Models;

class RowDecision extends Entity
{
    protected $table = 'row_decisions';

    public $timestamps = false;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['id' => 'string', 'raw_row_id' => 'string', 'configuration_revision' => 'integer', 'created_at' => 'datetime'];
    }
}

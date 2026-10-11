<?php

namespace App\Models;

class RawRow extends Entity
{
    protected $table = 'raw_rows';

    public $timestamps = false;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['id' => 'string', 'original_cells' => 'array'];
    }
}

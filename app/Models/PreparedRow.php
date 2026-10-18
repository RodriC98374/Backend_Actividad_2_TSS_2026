<?php

namespace App\Models;

class PreparedRow extends Entity
{
    protected $table = 'prepared_rows';

    public $timestamps = false;

    protected $hidden = ['prepared_contacts'];

    protected function casts(): array
    {
        return ['id' => 'string', 'raw_row_id' => 'string', 'prepared_fields' => 'array', 'prepared_contacts' => 'array', 'effective_decision' => 'array', 'proposed_inclusion' => 'boolean'];
    }
}

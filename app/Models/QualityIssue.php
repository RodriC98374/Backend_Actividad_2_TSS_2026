<?php

namespace App\Models;

class QualityIssue extends Entity
{
    protected $table = 'quality_issues';

    public $timestamps = true;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['id' => 'string', 'raw_row_id' => 'string', 'context' => 'array'];
    }
}

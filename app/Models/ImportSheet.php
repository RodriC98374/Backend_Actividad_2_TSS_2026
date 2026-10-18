<?php

namespace App\Models;

class ImportSheet extends UuidEntity
{
    protected $table = 'import_sheets';

    public $timestamps = true;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['selected' => 'boolean', 'data_region' => 'array', 'headers' => 'array', 'mapping' => 'array', 'multiselect_rules' => 'array', 'header_row' => 'integer', 'sheet_index' => 'integer', 'row_count' => 'integer', 'column_count' => 'integer'];
    }
}

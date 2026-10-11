<?php

namespace App\Models;

class DataExport extends UuidEntity
{
    protected $table = 'exports';

    public $timestamps = true;

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return ['specification' => 'array', 'data_version' => 'integer', 'expires_at' => 'datetime'];
    }
}

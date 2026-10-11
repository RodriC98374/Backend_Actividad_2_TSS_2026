<?php

namespace App\Models;

class SurveyImport extends UuidEntity
{
    protected $table = 'imports';

    public $timestamps = true;

    protected $hidden = ['storage_path', 'storage_disk'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'configuration_revision' => 'integer', 'committed_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

class ValidationRun extends UuidEntity
{
    protected $table = 'validation_runs';

    public $timestamps = true;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['configuration_snapshot' => 'array', 'counts' => 'array', 'configuration_revision' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}

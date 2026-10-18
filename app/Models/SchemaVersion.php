<?php

namespace App\Models;

class SchemaVersion extends UuidEntity
{
    protected $table = 'survey_schema_versions';

    protected function casts(): array
    {
        return ['version_number' => 'integer', 'revision' => 'integer', 'published_at' => 'datetime'];
    }
}

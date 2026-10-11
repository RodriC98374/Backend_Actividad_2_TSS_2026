<?php

namespace App\Models;

class SurveyResponse extends UuidEntity
{
    protected $table = 'survey_responses';

    public $timestamps = true;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['raw_row_id' => 'string', 'included' => 'boolean', 'response_revision' => 'integer'];
    }
}

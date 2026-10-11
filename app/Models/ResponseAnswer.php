<?php

namespace App\Models;

class ResponseAnswer extends Entity
{
    protected $table = 'response_answers';

    public $timestamps = false;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['id' => 'string', 'value' => 'json', 'provenance' => 'array'];
    }
}

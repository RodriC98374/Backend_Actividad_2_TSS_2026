<?php

namespace App\Models;

class DirectSubmission extends UuidEntity
{
    protected $table = 'direct_submissions';

    public $timestamps = false;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['original_payload' => 'array', 'publication_result' => 'array', 'received_at' => 'datetime'];
    }
}

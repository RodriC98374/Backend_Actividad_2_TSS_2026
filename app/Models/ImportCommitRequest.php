<?php

namespace App\Models;

class ImportCommitRequest extends UuidEntity
{
    protected $table = 'import_commit_requests';

    public $timestamps = false;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['request_body' => 'array', 'response_body' => 'array', 'completed_at' => 'datetime'];
    }
}

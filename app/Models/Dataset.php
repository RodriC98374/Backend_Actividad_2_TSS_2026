<?php

namespace App\Models;

class Dataset extends UuidEntity
{
    protected $table = 'datasets';

    public $timestamps = true;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['data_version' => 'integer'];
    }
}

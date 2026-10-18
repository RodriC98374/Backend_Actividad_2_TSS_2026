<?php

namespace App\Models;

class ImportProfile extends UuidEntity
{
    protected $table = 'import_profiles';

    public $timestamps = true;

    protected $hidden = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'profile_version' => 'integer'];
    }
}

<?php

namespace App\Models;

class ContactDetail extends UuidEntity
{
    protected $table = 'contact_details';

    public $timestamps = false;

    protected $hidden = [];

    protected function casts(): array
    {
        return [];
    }
}

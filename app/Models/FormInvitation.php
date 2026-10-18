<?php

namespace App\Models;

class FormInvitation extends UuidEntity
{
    protected $table = 'form_invitations';

    public $timestamps = true;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'used_at' => 'datetime'];
    }
}

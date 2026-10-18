<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldDefinition extends UuidEntity
{
    protected $table = 'survey_field_definitions';

    public $timestamps = false;

    protected $hidden = ['legacy_payload'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'sensitive' => 'boolean', 'legacy_payload' => 'array'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(FieldOption::class, 'definition_id')->orderBy('position');
    }
}

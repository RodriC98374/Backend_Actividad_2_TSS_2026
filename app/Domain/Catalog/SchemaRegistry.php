<?php

namespace App\Domain\Catalog;

use App\Models\FieldDefinition;
use App\Models\SchemaVersion;
use App\Models\SurveySchema;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchemaRegistry
{
    public function createVersion(SurveySchema $schema, ?string $base, int $actor): SchemaVersion
    {
        return DB::transaction(function () use ($schema, $base, $actor) {
            SurveySchema::lockForUpdate()->findOrFail($schema->id);
            $source = $base ? SchemaVersion::where('schema_id', $schema->id)->findOrFail($base) : null;
            if ($source && $source->status !== 'published') {
                throw new ApiProblem('base_not_published', 'La versión de origen debe estar publicada.', 409);
            }
            $version = SchemaVersion::create(['schema_id' => $schema->id, 'version_number' => (SchemaVersion::where('schema_id', $schema->id)->max('version_number') ?? 0) + 1, 'created_by' => $actor])->fresh();
            if ($source) {
                foreach (FieldDefinition::where('schema_version_id', $source->id)->with('options')->get() as $field) {
                    $copy = FieldDefinition::create(['schema_id' => $schema->id, 'schema_version_id' => $version->id, 'field_id' => $field->field_id, 'label' => $field->label, 'type' => $field->type, 'unit' => $field->unit, 'required' => $field->required, 'sensitive' => $field->sensitive, 'legacy_payload' => $field->legacy_payload]);
                    foreach ($field->options as $option) {
                        $copy->options()->create(['schema_version_id' => $version->id, 'code' => $option->code, 'label' => $option->label, 'position' => $option->position]);
                    }
                }
            }

            return $version;
        });
    }

    public function edit(SchemaVersion $version, int $revision, array $fields): array
    {
        return DB::transaction(function () use ($version, $revision, $fields) {
            SurveySchema::lockForUpdate()->findOrFail($version->schema_id);
            $v = $this->draft($version->id, $revision);
            $updated = [];
            foreach ($fields as $field) {
                $this->validateField($field);
                DB::table('survey_fields')->insertOrIgnore(['id' => (string) Str::uuid(), 'schema_id' => $v->schema_id, 'code' => $field['code']]);
                $identity = DB::table('survey_fields')->where('schema_id', $v->schema_id)->where('code', $field['code'])->first();
                $this->checkMeaning($identity->id, $field['type'], $field['unit'] ?? null, (bool) $field['sensitive']);
                $definition = FieldDefinition::updateOrCreate(['schema_version_id' => $v->id, 'field_id' => $identity->id], ['schema_id' => $v->schema_id, 'label' => $field['label'], 'type' => $field['type'], 'unit' => $field['unit'] ?? null, 'required' => $field['required'], 'sensitive' => $field['sensitive'], 'legacy_payload' => null]);
                $definition->options()->delete();
                foreach ($field['options'] as $position => $option) {
                    $definition->options()->create(['schema_version_id' => $v->id, 'code' => $option['code'], 'label' => $option['label'], 'position' => $position]);
                }
                $updated[] = $definition->id;
            }
            if (FieldDefinition::where('schema_version_id', $v->id)->count() > config('surveys.max_schema_fields', 1000)) {
                throw new ApiProblem('too_many_fields', 'El esquema supera el límite de variables.', 422);
            }
            $v->increment('revision');

            return ['version' => $v->fresh(), 'updated_definition_ids' => $updated];
        });
    }

    public function remove(SchemaVersion $version, string $definition, int $revision): SchemaVersion
    {
        return DB::transaction(function () use ($version, $definition, $revision) {
            SurveySchema::lockForUpdate()->findOrFail($version->schema_id);
            $v = $this->draft($version->id, $revision);
            $field = FieldDefinition::where('schema_version_id', $v->id)->findOrFail($definition);
            $field->options()->delete();
            $field->delete();
            $v->increment('revision');

            return $v->fresh();
        });
    }

    public function publish(SchemaVersion $version, int $revision): SchemaVersion
    {
        return DB::transaction(function () use ($version, $revision) {
            // Serializes publication of different drafts sharing stable field identities.
            SurveySchema::lockForUpdate()->findOrFail($version->schema_id);
            $v = $this->draft($version->id, $revision);
            $fields = FieldDefinition::where('schema_version_id', $v->id)->with('options')->orderBy('field_id')->get();
            if ($fields->isEmpty()) {
                throw new ApiProblem('empty_schema', 'Agregue al menos una variable antes de publicar.');
            }
            $snapshot = [];
            foreach ($fields as $field) {
                $this->checkMeaning($field->field_id, $field->type, $field->unit, $field->sensitive);
                $snapshot[] = $field->only(['field_id', 'label', 'type', 'unit', 'required', 'sensitive']) + ['legacy_payload' => $field->legacy_payload, 'options' => $field->options->map->only(['code', 'label', 'position'])->all()];
            }
            $v->update(['status' => 'published', 'content_hash' => hash('sha256', Api::canonical($snapshot)), 'published_at' => now(), 'revision' => $v->revision + 1]);

            return $v->fresh();
        });
    }

    private function draft(string $id, int $revision): SchemaVersion
    {
        $version = SchemaVersion::lockForUpdate()->findOrFail($id);
        Api::revision($version->revision, $revision);
        if ($version->status !== 'draft') {
            throw new ApiProblem('schema_immutable', 'Cree un nuevo borrador para modificar un esquema publicado.', 409);
        }

        return $version;
    }

    private function checkMeaning(string $field, string $type, ?string $unit, bool $sensitive): void
    {
        $published = FieldDefinition::where('field_id', $field)->whereIn('schema_version_id', SchemaVersion::where('status', 'published')->select('id'))->get();
        foreach ($published as $prior) {
            if ($prior->type !== $type || $prior->unit !== $unit || $prior->sensitive !== $sensitive) {
                throw new ApiProblem('field_identity_conflict', 'Tipo, unidad y sensibilidad publicados son estables. Use un código de variable nuevo.');
            }
        }
    }

    private function validateField(array $field): void
    {
        $categorical = in_array($field['type'], ['category', 'ordered_category', 'multiselect'], true);
        if ($categorical !== (count($field['options']) > 0)) {
            throw new ApiProblem('invalid_field_options', 'Solo categorías y multiselecciones requieren una lista de opciones no vacía.');
        }
        $codes = array_column($field['options'], 'code');
        if (count(array_unique($codes)) !== count($codes)) {
            throw new ApiProblem('duplicate_option_code', 'Los códigos de opción deben ser únicos dentro de la variable.');
        }
        if (($field['type'] === 'number') !== (isset($field['unit']) && $field['unit'] !== '')) {
            throw new ApiProblem('invalid_field_unit', 'Declare unidad para números (none si no aplica). Otros tipos no admiten unidad.');
        }
    }
}

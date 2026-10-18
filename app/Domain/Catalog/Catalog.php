<?php

namespace App\Domain\Catalog;

use App\Models\FieldDefinition;
use App\Models\SchemaVersion;
use App\Models\SurveySchema;
use App\Support\ApiProblem;
use Illuminate\Support\Str;

class Catalog
{
    private array $snapshots = [];

    public function version(?string $version = null): string
    {
        $version ??= config('surveys.dictionary_version');
        if (! in_array($version, [config('surveys.dictionary_version'), config('surveys.legacy_version')], true)) {
            throw new ApiProblem('unknown_dictionary', 'Versión del catálogo no disponible.');
        }

        return $version;
    }

    public function fields(string $survey, ?string $version = null): array
    {
        if (! in_array($survey, ['titulados', 'empleadores'], true)) {
            throw new ApiProblem('invalid_survey_type', 'Tipo de encuesta no válido.');
        }
        $version = $this->version($version);
        $key = $survey.':'.$version;
        if (isset($this->snapshots[$key])) {
            return $this->snapshots[$key];
        }
        $schema = SurveySchema::where('legacy_survey_type', $survey)->firstOrFail();
        $snapshot = SchemaVersion::where('schema_id', $schema->id)->where('legacy_dictionary_version', $version)->where('status', 'published')->firstOrFail();

        return $this->snapshots[$key] = FieldDefinition::where('schema_version_id', $snapshot->id)->orderBy('legacy_position')->get()->map(fn ($field) => $field->legacy_payload)->all();
    }

    public function field(string $survey, string $id, ?string $version = null): array
    {
        foreach ($this->fields($survey, $version) as $field) {
            if ($field['id'] === $id) {
                return $field;
            }
        }
        throw new ApiProblem('unknown_field', 'Campo desconocido para esta encuesta y catálogo.', 422, ['field_id' => [$id]]);
    }

    public function publicField(array $f): array
    {
        $f['allowed_filter_operators'] = match ($f['type']) {
            'boolean','category','range','scale' => ['eq', 'in', 'state_is'],
            'number' => $f['uncertain_unit'] ?? false ? ['state_is'] : ['gte', 'lte', 'between', 'state_is'],
            'multiselect' => ['contains_any', 'contains_all', 'state_is'],
            default => ['state_is'],
        };
        $f['analytics'] = match ($f['type']) {
            'boolean','category','range','scale' => ['distribution', 'crosstab'],
            'multiselect' => ['distribution'],
            'number' => $f['uncertain_unit'] ?? false ? [] : ['numeric_summary'],
            default => [],
        };

        return $f;
    }

    public static function compare(string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', Str::ascii($value))));
    }
}

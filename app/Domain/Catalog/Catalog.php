<?php

namespace App\Domain\Catalog;

use App\Support\ApiProblem;
use Illuminate\Support\Str;

class Catalog
{
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
        $all = json_decode(file_get_contents(config_path('survey-catalog.json')), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter($all, fn ($f) => $f['survey_type'] === $survey && ($version !== config('surveys.legacy_version') || ! str_starts_with($f['id'], 'X'))));
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

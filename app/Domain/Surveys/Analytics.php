<?php

namespace App\Domain\Surveys;

use App\Domain\Catalog\Catalog;
use App\Models\Dataset;
use App\Models\ResponseAnswer;
use App\Models\SurveyResponse;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;

class Analytics
{
    public function __construct(private Catalog $catalog, private Normalizer $normalizer) {}

    public function filters(mixed $filters, string $survey): array
    {
        if (is_string($filters)) {
            try {
                $filters = json_decode($filters, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiProblem('invalid_filters', 'filters debe ser JSON válido.');
            }
        }
        $filters ??= [];
        if (! is_array($filters) || ! array_is_list($filters) || count($filters) > 20) {
            throw new ApiProblem('invalid_filters', 'filters debe ser una lista de hasta 20 condiciones AND.');
        }
        foreach ($filters as &$filter) {
            if (! is_array($filter) || array_diff(array_keys($filter), ['field_id', 'operator', 'value']) || ! isset($filter['field_id'],$filter['operator']) || ! is_string($filter['field_id']) || ! is_string($filter['operator'])) {
                throw new ApiProblem('invalid_filters', 'Cada condición contiene field_id, operator y value.');
            }
            $f = $this->catalog->publicField($this->catalog->field($survey, $filter['field_id']));
            $op = $filter['operator'];
            $value = $filter['value'] ?? null;
            if (! in_array($op, $f['allowed_filter_operators'], true)) {
                throw new ApiProblem('unsupported_operator', 'Operador no permitido para el campo.');
            }
            if ($op === 'state_is') {
                if (! in_array($value, ['respondida', 'sin_respuesta', 'no_aplica', 'indeterminada', 'invalida', 'no_disponible'], true)) {
                    throw new ApiProblem('invalid_filter_value', 'Estado desconocido.');
                }

                continue;
            }
            $many = in_array($op, ['in', 'contains_any', 'contains_all', 'between']);
            $values = $many ? $value : [$value];
            if (! is_array($values) || ! array_is_list($values) || ! count($values) || count($values) > 100 || ($op === 'between' && count($values) !== 2)) {
                throw new ApiProblem('invalid_filter_value', 'La lista del filtro es inválida.');
            }
            foreach ($values as &$item) {
                if ($f['type'] === 'number') {
                    if (! is_int($item) && ! is_float($item)) {
                        throw new ApiProblem('invalid_filter_value', 'Use números JSON.');
                    }
                } elseif ($f['type'] === 'boolean') {
                    if (! is_bool($item)) {
                        throw new ApiProblem('invalid_filter_value', 'Use true o false.');
                    }
                } else {
                    if (! is_string($item) || ! in_array($item, array_column($f['options'], 'code'), true)) {
                        throw new ApiProblem('invalid_filter_value', 'Use códigos del catálogo.');
                    }
                }
            }
            unset($item);
            if ($op === 'between' && $values[0] > $values[1]) {
                throw new ApiProblem('invalid_filter_value', 'Intervalo invertido.');
            }
            $filter['value'] = $many ? $values : $values[0];
        }
        unset($filter);

        return $filters;
    }

    public function query(Dataset $dataset, string $survey, array $filters, bool $included = true)
    {
        $q = SurveyResponse::where('dataset_id', $dataset->id)->where('survey_type', $survey)->where('included', $included);
        foreach ($filters as $f) {
            $base = fn ($a) => $a->selectRaw('1')->from('response_answers as a')->whereColumn('a.response_id', 'survey_responses.id')->where('a.field_id', $f['field_id']);
            $q->where(function ($outer) use ($f, $base) {
                $outer->whereExists(function ($a) use ($f, $base) {
                    $base($a);
                    $op = $f['operator'];
                    $v = $f['value'];
                    if ($op === 'state_is') {
                        $a->where('a.state', $v);

                        return;
                    }
                    $a->where('a.state', 'respondida');
                    if ($op === 'eq') {
                        $a->whereRaw('a.value = ?::jsonb', [json_encode($v)]);
                    } elseif ($op === 'in') {
                        $a->where(function ($or) use ($v) {
                            foreach ($v as $item) {
                                $or->orWhereRaw('a.value = ?::jsonb', [json_encode($item)]);
                            }
                        });
                    } elseif ($op === 'contains_all') {
                        $a->whereRaw('a.value @> ?::jsonb', [json_encode($v)]);
                    } elseif ($op === 'contains_any') {
                        $a->where(function ($or) use ($v) {
                            foreach ($v as $item) {
                                $or->orWhereRaw('a.value @> ?::jsonb', [json_encode([$item])]);
                            }
                        });
                    } else {
                        $expr = "(CASE WHEN jsonb_typeof(a.value) = 'number' THEN (a.value::text)::numeric END)";
                        if ($op === 'between') {
                            $a->whereRaw("$expr BETWEEN ? AND ?", $v);
                        } else {
                            $a->whereRaw($expr.($op === 'gte' ? ' >= ?' : ' <= ?'), [$v]);
                        }
                    }
                });
                if ($f['operator'] === 'state_is' && $f['value'] === 'no_disponible') {
                    $outer->orWhereNotExists($base);
                }
            });
        }

        return $q;
    }

    public function calculate(Dataset $dataset, array $spec, string $kind): array
    {
        return DB::transaction(function () use ($dataset, $spec, $kind) {
            $d = Dataset::sharedLock()->findOrFail($dataset->id);
            $survey = $spec['survey_type'];
            $filters = $this->filters($spec['filters'] ?? [], $survey);
            $field = $this->catalog->publicField($this->catalog->field($survey, $spec['field_id']));
            $cap = str_replace('-', '_', $kind);
            if (! in_array($cap, $field['analytics'])) {
                throw new ApiProblem('unsupported_analysis', 'Este campo no admite ese análisis.');
            }
            $second = null;
            if ($kind === 'crosstab') {
                $second = $this->catalog->publicField($this->catalog->field($survey, $spec['column_field_id'] ?? ''));
                if (! in_array('crosstab', $second['analytics'])) {
                    throw new ApiProblem('unsupported_analysis', 'La segunda variable no admite cruces.');
                }
            }
            $query = $this->query($d, $survey, $filters);
            $total = (clone $query)->count();
            $ids = [$field['id']];
            if ($second) {
                $ids[] = $second['id'];
            }
            $counts = array_fill_keys(array_column($field['options'], 'code'), 0);
            $states = array_fill_keys(['respondida', 'sin_respuesta', 'no_aplica', 'indeterminada', 'invalida', 'no_disponible'], 0);
            $matrix = [];
            $n = 0;
            $mean = 0.0;
            $m2 = 0.0;
            $min = null;
            $max = null;
            foreach ($query->orderBy('id')->lazy(250)->chunk(250) as $responses) {
                $batch = ResponseAnswer::whereIn('response_id', $responses->pluck('id'))->whereIn('field_id', $ids)->get()->groupBy('response_id');
                foreach ($responses as $response) {
                    $answers = $batch->get($response->id, collect())->keyBy('field_id');
                    $a = $answers->get($field['id']);
                    $state = $a?->state ?? 'no_disponible';
                    $states[$state]++;
                    if ($state !== 'respondida') {
                        continue;
                    }
                    $value = $a->value;
                    if ($kind === 'numeric-summary') {
                        $n++;
                        $delta = $value - $mean;
                        $mean += $delta / $n;
                        $m2 += $delta * ($value - $mean);
                        $min = $min === null ? $value : min($min, $value);
                        $max = $max === null ? $value : max($max, $value);
                    } elseif ($kind === 'crosstab') {
                        $b = $answers->get($second['id']);
                        if ($b?->state !== 'respondida') {
                            continue;
                        }
                        $n++;
                        $x = is_bool($value) ? ($value ? 'si' : 'no') : $value;
                        $y = is_bool($b->value) ? ($b->value ? 'si' : 'no') : $b->value;
                        $matrix[$x][$y] = ($matrix[$x][$y] ?? 0) + 1;
                    } else {
                        $n++;
                        foreach (is_array($value) ? $value : [$value] as $code) {
                            if (is_bool($code)) {
                                $code = $code ? 'si' : 'no';
                            }
                            $counts[$code] = ($counts[$code] ?? 0) + 1;
                        }
                    }
                }
            }
            $result = ['dataset_id' => $d->id, 'data_version' => $d->data_version, 'normalization_version' => Normalizer::VERSION, 'survey_type' => $survey, 'field_id' => $field['id'], 'filters' => $filters, 'total_responses' => $total, 'denominator' => $n, 'states' => $states, 'empty_reason' => $n === 0 ? 'no_valid_answers' : null];
            if ($kind === 'numeric-summary') {
                $result += ['count' => $n, 'mean' => $n ? $mean : null, 'min' => $min, 'max' => $max, 'sample_standard_deviation' => $n > 1 ? sqrt($m2 / ($n - 1)) : null];
            } elseif ($kind === 'crosstab') {
                $cells = [];
                foreach ($field['options'] as $x) {
                    foreach ($second['options'] as $y) {
                        $c = $matrix[$x['code']][$y['code']] ?? 0;
                        $cells[] = ['row_code' => $x['code'], 'column_code' => $y['code'], 'count' => $c, 'percentage' => $n ? round(100 * $c / $n, 2) : null];
                    }
                }
                $result += ['column_field_id' => $second['id'], 'rows' => $field['options'], 'columns' => $second['options'], 'cells' => $cells, 'percentage_basis' => 'valid_pairs'];
            } else {
                $items = [];
                foreach ($field['options'] as $option) {
                    $c = $counts[$option['code']] ?? 0;
                    $items[] = $option + ['count' => $c, 'percentage' => $n ? round(100 * $c / $n, 2) : null];
                }
                $result += ['items' => $items, 'percentage_basis' => 'valid_respondents', 'multiple_selection' => $field['type'] === 'multiselect'];
            }

            return $result;
        });
    }
}

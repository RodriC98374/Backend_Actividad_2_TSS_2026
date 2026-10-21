<?php

namespace App\Domain\Surveys;

use App\Domain\Catalog\Catalog;

class Normalizer
{
    public const VERSION = 'normalization-v1';

    public function field(array $field, mixed $original, bool $available = true, array $columns = []): array
    {
        $answer = ['field_id' => $field['id'], 'state' => 'respondida', 'value' => null, 'provenance' => ['column_indices' => $columns, 'rule_codes' => [self::VERSION]]];
        if (! $available) {
            $answer['state'] = 'no_disponible';

            return $answer;
        }

        return $this->interpret($field, $original, $answer);
    }

    private function interpret(array $f, mixed $v, array $answer): array
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $answer['state'] = 'sin_respuesta';

            return $answer;
        }
        $valid = true;
        $value = null;
        switch ($f['type']) {
            case 'boolean':
                if (is_bool($v)) {
                    $value = $v;
                } elseif (is_string($v) && in_array(Catalog::compare($v), ['si', 'no'], true)) {
                    $value = Catalog::compare($v) === 'si';
                } else {
                    $valid = false;
                }
                break;
            case 'number':
                if (is_bool($v) || ! is_scalar($v) || ! preg_match('/^\d+(?:\.\d+)?$/D', trim((string) $v))) {
                    $valid = false;
                } else {
                    $value = (float) $v;
                    $valid = is_finite($value) && $value >= 0;
                }
                if (in_array($f['id'], ['X01', 'X02']) && $valid) {
                    $valid = floor($value) === $value && $value >= 1900 && $value <= (int) date('Y');
                }
                break;
            case 'category': case 'range': case 'scale':
                $value = $this->option($f, $v);
                $valid = $value !== null;
                break;
            case 'multiselect':
                if (! is_array($v) || ! array_is_list($v) || count($v) === 0) {
                    $valid = false;
                    break;
                }
                $value = [];
                foreach ($v as $item) {
                    $code = $this->option($f, $item);
                    if ($code === null) {
                        $valid = false;
                        break;
                    } $value[] = $code;
                }
                $value = array_values(array_unique($value));
                if ($f['id'] === 'X07' && in_array('por_definir', $value) && count($value) > 1) {
                    $valid = false;
                }
                break;
            case 'contact':
                if (! is_scalar($v) || is_bool($v)) {
                    $valid = false;
                    break;
                }
                $value = trim((string) $v);
                $valid = match ($f['id']) {
                    'T32','E00' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'T34' => filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['http', 'https']),
                    default => (bool) preg_match('/^\+?[0-9 ()\-]{5,40}$/D', $value),
                };
                break;
            default:
                $valid = is_scalar($v) && ! is_bool($v);
                $value = $valid ? (string) $v : null;
        }
        $answer['state'] = $valid ? 'respondida' : 'invalida';
        $answer['value'] = $valid ? $value : null;

        return $answer;
    }

    public function option(array $f, mixed $v): ?string
    {
        if (! is_string($v) && ! is_int($v) && ! is_float($v)) {
            return null;
        }
        foreach ($f['options'] as $option) {
            if ((string) $v === $option['code'] || Catalog::compare((string) $v) === Catalog::compare($option['label'])) {
                return $option['code'];
            }
        }

        return null;
    }

    public function conditional(array $answers): array
    {
        $conditions = ['T04' => 'T03', 'T05' => 'T03', 'T06' => 'T03', 'T07' => 'T03', 'T09' => 'T08', 'T10' => 'T08', 'T11' => 'T08', 'T32' => 'T31', 'T33' => 'T31', 'T34' => 'T31'];
        foreach ($conditions as $child => $parent) {
            if (isset($answers[$child]) && $answers[$child]['state'] === 'sin_respuesta' && ($answers[$parent]['value'] ?? null) !== true) {
                $answers[$child]['state'] = 'indeterminada';
            }
        }
        foreach (['T15', 'T16', 'T17', 'T18', 'T19'] as $child) {
            if (isset($answers[$child]) && $answers[$child]['state'] === 'sin_respuesta' && ($answers['T14']['value'] ?? null) !== 'emprendimiento_tiempo_completo') {
                $answers[$child]['state'] = 'indeterminada';
            }
        }

        return $answers;
    }
}

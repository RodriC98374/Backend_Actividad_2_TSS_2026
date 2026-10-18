<?php

namespace App\Domain\Imports;

use App\Domain\Catalog\Catalog;
use App\Models\ImportSheet;
use App\Models\RawRow;

class SheetAssessment
{
    public function __construct(private Catalog $catalog) {}

    public function assess(ImportSheet $sheet, string $survey, ?int $headerRow): array
    {
        $fields = $this->catalog->fields($survey, $sheet->dictionary_version);
        $candidates = [];
        $scanned = 0;
        foreach (RawRow::where('import_sheet_id', $sheet->id)->orderBy('row_number')->limit(50)->get() as $row) {
            $scanned++;
            $columns = $this->headers($row->original_cells, $fields);
            $nonempty = array_filter($columns, fn ($c) => $c['header'] !== null && trim($c['header']) !== '');
            if (! $nonempty || $row->row_number >= $sheet->row_count) {
                continue;
            }
            $matches = count(array_filter($columns, fn ($c) => count($c['field_candidates']) === 1));
            $text = count(array_filter($row->original_cells, fn ($c) => $c['type'] === 'string' && trim((string) ($c['value'] ?? '')) !== ''));
            $candidates[] = ['row_number' => $row->row_number, 'matched_columns' => $matches, 'nonempty_columns' => count($nonempty), 'text_columns' => $text];
        }
        usort($candidates, fn ($a, $b) => ($b['matched_columns'] <=> $a['matched_columns']) ?: ($b['text_columns'] <=> $a['text_columns']) ?: ($a['row_number'] <=> $b['row_number']));

        $columns = [];
        $profiled = 0;
        if ($headerRow !== null) {
            $header = RawRow::where('import_sheet_id', $sheet->id)->where('row_number', $headerRow)->firstOrFail();
            $columns = $this->headers($header->original_cells, $fields);
            // CSV rows may have fewer cells than the widest row. Preserve all positions.
            for ($index = count($columns); $index < $sheet->column_count; $index++) {
                $columns[] = ['column_index' => $index, 'header' => null, 'field_candidates' => [], 'requires_multiselect_rule' => false];
            }
            $seen = [];
            foreach ($columns as $index => &$column) {
                $column += ['nonempty_count' => 0, 'empty_count' => 0, 'types' => [], 'distinct_count' => 0, 'distinct_count_is_lower_bound' => false, 'formula_without_cached_value_count' => 0];
                $seen[$index] = [];
            }
            unset($column);
            foreach (RawRow::where('import_sheet_id', $sheet->id)->where('row_number', '>', $headerRow)->orderBy('row_number')->cursor() as $row) {
                $profiled++;
                $cells = array_column($row->original_cells, null, 'column_index');
                foreach ($columns as $index => &$column) {
                    $cell = $cells[$column['column_index']] ?? ['type' => 'empty', 'value' => null];
                    $value = $cell['value'];
                    if ($cell['type'] === 'formula' && $value === null) {
                        $column['formula_without_cached_value_count']++;
                    }
                    if ($value === null || (is_string($value) && trim($value) === '')) {
                        $column['empty_count']++;

                        continue;
                    }
                    $column['nonempty_count']++;
                    $type = $cell['type'];
                    $column['types'][$type] = ($column['types'][$type] ?? 0) + 1;
                    $key = hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
                    if (! isset($seen[$index][$key])) {
                        if (count($seen[$index]) < 100) {
                            $seen[$index][$key] = true;
                        } else {
                            $column['distinct_count_is_lower_bound'] = true;
                        }
                    }
                }
                unset($column);
            }
            foreach ($columns as $index => &$column) {
                $column['distinct_count'] = count($seen[$index]);
                $column['types'] = (object) $column['types'];
            }
            unset($column);
        }

        return ['sheet_id' => $sheet->id, 'survey_type' => $survey, 'dictionary_version' => $sheet->dictionary_version, 'requires_confirmation' => true, 'header_rows_scanned' => $scanned, 'header_candidates' => array_slice($candidates, 0, 5), 'selected_header_row' => $headerRow, 'profiled_rows' => $profiled, 'columns' => $columns];
    }

    private function headers(array $cells, array $fields): array
    {
        return array_map(function ($cell) use ($fields) {
            $header = $cell['value'] === null ? null : (string) $cell['value'];
            // A calculated value is never used to infer a question identifier.
            $normalized = $cell['type'] === 'string' ? $this->normalize($header ?? '') : '';
            $matches = [];
            $multiple = false;
            foreach ($fields as $field) {
                $names = array_map($this->normalize(...), [$field['id'], $field['name'], $field['label'], ...($field['aliases'] ?? [])]);
                if ($normalized !== '' && in_array($normalized, $names, true)) {
                    $matches[] = $field['id'];
                    $multiple = $multiple || $field['type'] === 'multiselect';
                }
            }

            return ['column_index' => $cell['column_index'], 'header' => $header, 'field_candidates' => $matches, 'requires_multiselect_rule' => $multiple];
        }, $cells);
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Catalog::compare($value)));
    }
}

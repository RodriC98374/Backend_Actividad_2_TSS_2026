<?php

namespace App\Domain\Imports;

use App\Domain\Catalog\Catalog;
use App\Domain\Surveys\Normalizer;
use App\Domain\Surveys\ResponseWriter;
use App\Models\ChangeLog;
use App\Models\Dataset;
use App\Models\ImportCommitRequest;
use App\Models\ImportProfile;
use App\Models\ImportSheet;
use App\Models\PreparedRow;
use App\Models\QualityIssue;
use App\Models\RawRow;
use App\Models\RowDecision;
use App\Models\SurveyImport;
use App\Models\SurveyResponse;
use App\Models\ValidationRun;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportService
{
    public function __construct(private Catalog $catalog, private WorkbookReader $reader, private Normalizer $normalizer, private ResponseWriter $writer) {}

    public static function counts(): array
    {
        return array_fill_keys(['read_rows', 'prepared_rows', 'included_rows', 'excluded_rows', 'empty_rows', 'pending_rows', 'blocking_issues', 'warning_issues'], 0);
    }

    public function upload(UploadedFile $file, array $data, int $actor): SurveyImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv', 'pdf'], true)) {
            throw new ApiProblem('unsupported_format', 'Solo se admiten XLSX, CSV y PDF de respuestas.');
        }
        $dataset = Dataset::findOrFail($data['dataset_id']);
        if ($dataset->status !== 'active') {
            throw new ApiProblem('dataset_closed', 'El estudio no está activo.', 409);
        }
        $version = $this->catalog->version($data['dictionary_version'] ?? null);
        $path = $file->storeAs('imports', (string) Str::uuid().'.'.$extension, 'local');
        $import = SurveyImport::create(['dataset_id' => $dataset->id, 'created_by' => $actor, 'original_name' => basename($file->getClientOriginalName()), 'storage_disk' => 'local', 'storage_path' => $path, 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'source_label' => $data['source_label'] ?? null, 'status' => 'reading', 'dictionary_version' => $version, 'counts' => self::counts()]);
        try {
            $sheets = $this->reader->read(Storage::disk('local')->path($path), $extension, $data['csv_delimiter'] ?? null, $data['csv_encoding'] ?? 'UTF-8');
            DB::transaction(function () use ($import, $sheets) {
                foreach ($sheets as $sheet) {
                    $rows = $sheet['rows'];
                    unset($sheet['rows']);
                    $stored = ImportSheet::create($sheet + ['import_id' => $import->id, 'dictionary_version' => $import->dictionary_version]);
                    foreach (array_chunk($rows, 200, true) as $chunk) {
                        $values = [];
                        foreach ($chunk as $index => $cells) {
                            $values[] = ['import_sheet_id' => $stored->id, 'row_number' => $index + 1, 'original_cells' => json_encode($cells, JSON_THROW_ON_ERROR), 'row_status' => 'read'];
                        }
                        RawRow::insert($values);
                    }
                }
                $import->update(['status' => 'awaiting_configuration']);
            });
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed', 'failure_code' => $e instanceof ApiProblem ? $e->errorCode : 'invalid_file', 'failure_message' => $e instanceof ApiProblem ? $e->getMessage() : 'El archivo no se pudo leer.']);
            if (! $e instanceof ApiProblem) {
                report($e);
            }
            throw new ApiProblem($import->failure_code, $import->failure_message, $e instanceof ApiProblem ? $e->status : 422, ['import_id' => [$import->id]]);
        }

        return $import;
    }

    public function detail(SurveyImport $import): array
    {
        return $import->toArray() + ['phase' => null, 'processed_rows' => $import->counts['read_rows'] ?? 0, 'total_rows' => null, 'sheets' => ImportSheet::where('import_id', $import->id)->orderBy('sheet_index')->get(), 'latest_validation_run_id' => ValidationRun::where('import_id', $import->id)->latest('created_at')->value('id'), 'duplicate_import_ids' => SurveyImport::where('dataset_id', $import->dataset_id)->where('sha256', $import->sha256)->where('id', '!=', $import->id)->pluck('id'), 'last_error' => $import->failure_code ? ['code' => $import->failure_code, 'message' => $import->failure_message, 'errors' => (object) []] : null];
    }

    public function sheet(SurveyImport $import, string $id): ImportSheet
    {
        return ImportSheet::where('import_id', $import->id)->findOrFail($id);
    }

    public function configure(SurveyImport $import, ImportSheet $sheet, array $data): array
    {
        return DB::transaction(function () use ($import, $sheet, $data) {
            $locked = SurveyImport::lockForUpdate()->findOrFail($import->id);
            $this->editable($locked);
            Api::revision($locked->configuration_revision, $data['expected_configuration_revision']);
            $missing = [];
            if ($data['selected']) {
                if ($data['dictionary_version'] !== $locked->dictionary_version) {
                    throw new ApiProblem('dictionary_version_mismatch', 'Todas las hojas deben usar el catálogo de la importación.');
                }
                $region = $data['data_region'];
                if ($region['first_data_row'] <= $data['header_row'] || ($region['last_data_row'] ?? $sheet->row_count) < $region['first_data_row'] || $region['last_column_index'] < $region['first_column_index'] || $region['last_column_index'] >= $sheet->column_count || ($region['last_data_row'] ?? $sheet->row_count) > $sheet->row_count) {
                    throw new ApiProblem('invalid_region', 'La región debe estar dentro de la hoja y después del encabezado.');
                }
                $columns = [];
                $fields = [];
                foreach ($data['columns'] as $column) {
                    $index = $column['column_index'];
                    if ($index < $region['first_column_index'] || $index > $region['last_column_index'] || in_array($index, $columns, true)) {
                        throw new ApiProblem('invalid_mapping', 'Columnas repetidas o fuera de la región.');
                    }
                    $columns[] = $index;
                    if ($column['role'] === 'field') {
                        $field = $this->catalog->field($data['survey_type'], $column['field_id'], $locked->dictionary_version);
                        if (isset($fields[$field['id']]) && $field['type'] !== 'multiselect') {
                            throw new ApiProblem('mapping_ambiguous', 'Dos columnas escalares no pueden alimentar la misma pregunta.');
                        }
                        $fields[$field['id']][] = $index;
                    }
                }
                $rules = [];
                foreach ($data['multiselect_rules'] as $rule) {
                    $id = $rule['field_id'];
                    $field = $this->catalog->field($data['survey_type'], $id, $locked->dictionary_version);
                    if ($field['type'] !== 'multiselect' || isset($rules[$id]) || ! isset($fields[$id])) {
                        throw new ApiProblem('invalid_multiselect_rule', 'Regla múltiple duplicada o sin campo asociado.');
                    }
                    $ruleColumns = $rule['representation'] === 'delimited_cell' ? [$rule['column_index']] : array_column($rule['options'], 'column_index');
                    $mapped = $fields[$id];
                    sort($mapped);
                    sort($ruleColumns);
                    if ($mapped !== $ruleColumns || count(array_unique($ruleColumns)) !== count($ruleColumns)) {
                        throw new ApiProblem('invalid_multiselect_rule', 'La regla debe cubrir exactamente las columnas del campo.');
                    }
                    if ($rule['representation'] === 'option_columns') {
                        $codes = array_column($field['options'], 'code');
                        foreach ($rule['options'] as $option) {
                            if (! in_array($option['option_code'], $codes, true)) {
                                throw new ApiProblem('unknown_option', 'Opción múltiple desconocida.');
                            }
                        }
                        foreach ($rule['selected_values'] as $value) {
                            if (in_array($value, $rule['unselected_values'], true)) {
                                throw new ApiProblem('ambiguous_selection', 'Los valores seleccionados/no seleccionados se solapan.');
                            }
                        }
                    }
                    $rules[$id] = $rule;
                }
                foreach ($fields as $id => $indexes) {
                    if ($this->catalog->field($data['survey_type'], $id, $locked->dictionary_version)['type'] === 'multiselect' && ! isset($rules[$id])) {
                        throw new ApiProblem('missing_multiselect_rule', 'Confirme la representación de cada selección múltiple.');
                    }
                }
                if (! empty($data['import_profile_id'])) {
                    $profile = ImportProfile::findOrFail($data['import_profile_id']);
                    if ($profile->dictionary_version !== $locked->dictionary_version || $profile->survey_type !== $data['survey_type']) {
                        throw new ApiProblem('profile_incompatible', 'Perfil incompatible con encuesta/catálogo.');
                    }
                }
                $header = RawRow::where('import_sheet_id', $sheet->id)->where('row_number', $data['header_row'])->firstOrFail();
                $headers = array_map(fn ($c) => ['column_index' => $c['column_index'], 'header' => $c['value'] === null ? null : (string) $c['value']], $header->original_cells);
                $sheet->update(['selected' => true, 'survey_type' => $data['survey_type'], 'header_row' => $data['header_row'], 'data_region' => $region, 'mapping' => $data['columns'], 'multiselect_rules' => $data['multiselect_rules'], 'headers' => $headers, 'import_profile_id' => $data['import_profile_id'] ?? null]);
                $missing = array_values(array_diff(array_column($this->catalog->fields($data['survey_type'], $locked->dictionary_version), 'id'), array_keys($fields)));
            } else {
                $sheet->update(['selected' => false]);
            }
            $locked->update(['configuration_revision' => $locked->configuration_revision + 1, 'status' => 'configured']);

            return ['configuration_revision' => $locked->configuration_revision, 'sheet' => $sheet->fresh(), 'unavailable_fields' => $missing];
        });
    }

    public function editable(SurveyImport $import): void
    {
        if (! in_array($import->status, ['awaiting_configuration', 'configured', 'needs_review', 'ready_to_commit'], true)) {
            throw new ApiProblem('invalid_import_state', 'La importación no admite cambios en su estado actual.', 409);
        }
    }

    public function validateImport(SurveyImport $import, int $revision): ValidationRun
    {
        return DB::transaction(function () use ($import, $revision) {
            $locked = SurveyImport::lockForUpdate()->findOrFail($import->id);
            $this->editable($locked);
            Api::revision($locked->configuration_revision, $revision);
            $sheets = ImportSheet::where('import_id', $import->id)->where('selected', true)->get();
            if ($sheets->isEmpty()) {
                throw new ApiProblem('no_selected_sheets', 'Seleccione al menos una hoja.');
            }
            $run = ValidationRun::create(['import_id' => $import->id, 'configuration_revision' => $revision, 'dictionary_version' => $locked->dictionary_version, 'normalization_version' => Normalizer::VERSION, 'configuration_snapshot' => $sheets->toArray(), 'status' => 'running', 'started_at' => now()]);
            $counts = self::counts();
            foreach ($sheets as $sheet) {
                $r = $sheet->data_region;
                $rows = RawRow::where('import_sheet_id', $sheet->id)->whereBetween('row_number', [$r['first_data_row'], $r['last_data_row'] ?? $sheet->row_count])->orderBy('row_number')->cursor();
                $fields = $this->catalog->fields($sheet->survey_type, $locked->dictionary_version);
                $mapping = collect($sheet->mapping)->where('role', 'field')->groupBy('field_id');
                $rules = collect($sheet->multiselect_rules)->keyBy('field_id');
                foreach ($rows as $raw) {
                    $counts['read_rows']++;
                    $cells = collect($raw->original_cells)->keyBy('column_index');
                    $regionValues = $cells->filter(fn ($c) => $c['column_index'] >= $r['first_column_index'] && $c['column_index'] <= $r['last_column_index'])->pluck('value');
                    if ($regionValues->every(fn ($v) => $v === null || $v === '')) {
                        $counts['empty_rows']++;

                        continue;
                    }
                    $answers = [];
                    $issues = [];
                    $useful = false;
                    foreach ($fields as $field) {
                        $columns = $mapping->get($field['id'], collect())->pluck('column_index')->all();
                        $original = count($columns) ? ($cells->get($columns[0])['value'] ?? null) : null;
                        if ($field['type'] === 'multiselect' && $columns) {
                            $rule = $rules[$field['id']];
                            if ($rule['representation'] === 'delimited_cell') {
                                $original = $original === null || trim((string) $original) === '' ? null : array_map('trim', explode($rule['delimiter'], (string) $original));
                            } else {
                                $original = [];
                                $unknown = false;
                                foreach ($rule['options'] as $option) {
                                    $v = $cells->get($option['column_index'])['value'] ?? null;
                                    if (in_array($v, $rule['selected_values'], true)) {
                                        $original[] = $option['option_code'];
                                    } elseif (! in_array($v, $rule['unselected_values'], true)) {
                                        $unknown = true;
                                    }
                                }
                                if ($unknown) {
                                    $original = ['__uninterpretable_selection__'];
                                } elseif (! $original) {
                                    $original = null;
                                }
                            }
                        }
                        $answer = $this->normalizer->field($field, $original, count($columns) > 0, $columns);
                        foreach ($columns as $index) {
                            if ($cells->get($index)['pdf_text_clipped'] ?? false) {
                                $answer['state'] = 'indeterminada';
                                $answer['value'] = null;
                                $issues[] = [$field['id'], 'pdf_text_clipped', 'Texto posiblemente recortado o superpuesto en el PDF; no se usa como respuesta válida.'];
                            }
                            if (($cells->get($index)['type'] ?? '') === 'formula' && ($cells->get($index)['value'] ?? null) === null) {
                                $answer['state'] = 'indeterminada';
                                $answer['value'] = null;
                                $issues[] = [$field['id'], 'formula_without_cached_value', 'Fórmula sin resultado almacenado; no se ejecutó.'];
                            }
                        }
                        if ($answer['state'] === 'invalida') {
                            $issues[] = [$field['id'], 'invalid_value', 'Valor fuera del dominio o formato interpretable.'];
                        }
                        if ($answer['state'] === 'respondida') {
                            if ($field['type'] !== 'contact') {
                                $useful = true;
                            }
                            if ($field['id'] === 'T13') {
                                $issues[] = [$field['id'], 'unknown_unit', 'Unidad de desempleo no confirmada; no habilita comparaciones numéricas.'];
                            }
                            if (in_array($field['id'], ['T27', 'T29']) && count($answer['value']) !== 5) {
                                $issues[] = [$field['id'], 'selection_count', 'La instrucción de la pregunta solicita cinco selecciones.'];
                            }
                        }
                        $answers[$field['id']] = $answer;
                    }
                    $answers = $this->normalizer->conditional($answers);
                    $decision = RowDecision::where('raw_row_id', $raw->id)->where('configuration_revision', '<=', $revision)->orderByDesc('configuration_revision')->first();
                    $included = $useful && $decision?->action !== 'exclude';
                    $reason = ! $useful ? 'no_interpretable_answers' : ($included ? null : $decision?->reason);
                    PreparedRow::create(['validation_run_id' => $run->id, 'raw_row_id' => $raw->id, 'prepared_fields' => array_values($answers), 'prepared_contacts' => [], 'proposed_inclusion' => $included, 'exclusion_reason' => $reason, 'effective_decision' => $decision?->toArray()]);
                    $counts['prepared_rows']++;
                    $counts[$included ? 'included_rows' : 'excluded_rows']++;
                    foreach ($issues as [$fieldId,$code,$message]) {
                        QualityIssue::create(['import_id' => $import->id, 'validation_run_id' => $run->id, 'raw_row_id' => $raw->id, 'field_id' => $fieldId, 'code' => $code, 'severity' => 'warning', 'message' => $message]);
                        $counts['warning_issues']++;
                    }
                }
            }
            if ($counts['included_rows'] === 0) {
                QualityIssue::create(['import_id' => $import->id, 'validation_run_id' => $run->id, 'code' => 'no_useful_rows', 'severity' => 'blocking', 'message' => 'No hay respuestas incorporables en la revisión.']);
                $counts['blocking_issues']++;
            }
            $run->update(['status' => 'completed', 'counts' => $counts, 'finished_at' => now()]);
            $locked->update(['status' => $counts['blocking_issues'] ? 'needs_review' : 'ready_to_commit', 'counts' => $counts]);

            return $run;
        });
    }

    public function commit(SurveyImport $import, array $data, string $key, int $actor): array
    {
        $data['policy']['replace_import_id'] ??= null;
        $hash = hash('sha256', Api::canonical($data));

        return DB::transaction(function () use ($import, $data, $key, $actor, $hash) {
            $dataset = Dataset::lockForUpdate()->findOrFail($import->dataset_id);
            if ($dataset->status !== 'active') {
                throw new ApiProblem('dataset_closed', 'El estudio no está activo.', 409);
            }
            $locked = SurveyImport::lockForUpdate()->findOrFail($import->id);
            $prior = ImportCommitRequest::where('import_id', $import->id)->where('actor_id', $actor)->where('idempotency_key', $key)->first();
            if ($prior) {
                if ($prior->request_hash !== $hash) {
                    throw new ApiProblem('idempotency_key_reused', 'Clave reutilizada con otro contenido.', 409);
                }

                return $prior->response_body;
            }
            if ($locked->status === 'committed') {
                $done = ImportCommitRequest::where('import_id', $import->id)->firstOrFail();
                if ($done->request_hash !== $hash) {
                    throw new ApiProblem('import_already_committed', 'La importación ya se incorporó con otra petición.', 409);
                }

                return $done->response_body;
            }
            Api::revision($locked->configuration_revision, $data['configuration_revision']);
            $run = ValidationRun::where('import_id', $import->id)->findOrFail($data['validation_run_id']);
            if ($run->status !== 'completed' || $run->configuration_revision !== $locked->configuration_revision || ($run->counts['blocking_issues'] ?? 1) > 0 || $locked->status !== 'ready_to_commit') {
                throw new ApiProblem('validation_not_current', 'La validación no está vigente o tiene bloqueos.', 409);
            }
            $replace = $data['policy']['replace_import_id'];
            if ($data['policy']['mode'] === 'replace') {
                $previous = SurveyImport::where('dataset_id', $dataset->id)->where('status', 'committed')->where('id', '!=', $import->id)->lockForUpdate()->findOrFail($replace);
                // Scope must match survey kinds; operator explicitly declares complete replacement.
                $oldKinds = ImportSheet::where('import_id', $previous->id)->where('selected', true)->pluck('survey_type')->unique()->sort()->values()->all();
                $newKinds = ImportSheet::where('import_id', $import->id)->where('selected', true)->pluck('survey_type')->unique()->sort()->values()->all();
                if ($oldKinds !== $newKinds) {
                    throw new ApiProblem('replacement_scope_mismatch', 'La sustitución debe cubrir los mismos tipos de encuesta.');
                }
                $oldIds = RawRow::whereIn('import_sheet_id', ImportSheet::where('import_id', $previous->id)->select('id'))->select('id');
                foreach (SurveyResponse::whereIn('raw_row_id', $oldIds)->where('included', true)->cursor() as $old) {
                    ChangeLog::create(['response_id' => $old->id, 'actor_id' => $actor, 'before' => ['included' => true], 'after' => ['included' => false], 'reason' => 'Sustitución por importación '.$import->id]);
                    $old->update(['included' => false, 'exclusion_reason' => 'superseded_by:'.$import->id, 'response_revision' => $old->response_revision + 1]);
                }
            } elseif ($replace !== null) {
                throw new ApiProblem('invalid_policy', 'append no admite replace_import_id.');
            }
            if (SurveyImport::where('dataset_id', $dataset->id)->where('sha256', $locked->sha256)->where('status', 'committed')->where('id', '!=', $replace ?? $import->id)->exists()) {
                throw new ApiProblem('duplicate_file', 'Este archivo ya está incorporado en el estudio.', 409);
            }
            foreach (PreparedRow::where('validation_run_id', $run->id)->cursor() as $prepared) {
                $raw = RawRow::findOrFail($prepared->raw_row_id);
                $sheet = ImportSheet::findOrFail($raw->import_sheet_id);
                $this->writer->create(['dataset_id' => $dataset->id, 'source_kind' => 'import', 'raw_row_id' => $raw->id, 'validation_run_id' => $run->id, 'survey_type' => $sheet->survey_type, 'dictionary_version' => $locked->dictionary_version, 'included' => $prepared->proposed_inclusion, 'exclusion_reason' => $prepared->exclusion_reason], $prepared->prepared_fields);
            }
            $dataset->increment('data_version');
            $locked->update(['status' => 'committed', 'committed_at' => now()]);
            $result = ['import_id' => $import->id, 'validation_run_id' => $run->id, 'configuration_revision' => $locked->configuration_revision, 'status' => 'committed', 'data_version' => $dataset->data_version, 'counts' => $run->counts];
            ImportCommitRequest::create(['import_id' => $import->id, 'actor_id' => $actor, 'idempotency_key' => $key, 'request_hash' => $hash, 'request_body' => $data, 'response_status' => 200, 'response_body' => $result, 'completed_at' => now()]);

            return $result;
        }, 3);
    }
}

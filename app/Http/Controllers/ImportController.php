<?php

namespace App\Http\Controllers;

use App\Domain\Imports\ImportService;
use App\Domain\Imports\SheetAssessment;
use App\Domain\Surveys\ResponseWriter;
use App\Models\ImportSheet;
use App\Models\PreparedRow;
use App\Models\RawRow;
use App\Models\RowDecision;
use App\Models\SurveyImport;
use App\Models\ValidationRun;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    public function __construct(private ImportService $service) {}

    public function index(Request $r)
    {
        $filters = $r->validate(['dataset_id' => 'sometimes|required|uuid|exists:datasets,id']);
        $query = SurveyImport::latest();
        if (isset($filters['dataset_id'])) {
            $query->where('dataset_id', $filters['dataset_id']);
        }

        return Api::page($query, $r);
    }

    public function store(Request $r)
    {
        Api::imports($r);
        $v = $r->validate(['file' => 'required|file|max:'.config('surveys.max_file_kb', 10240), 'dataset_id' => 'required|uuid|exists:datasets,id', 'dictionary_version' => 'sometimes|string', 'source_label' => 'nullable|string|max:255', 'csv_delimiter' => 'nullable|in:",",;,'."\t", 'csv_encoding' => 'sometimes|in:UTF-8,Windows-1252']);

        return Api::data($this->service->detail($this->service->upload($r->file('file'), $v, $r->user()->id)), 201);
    }

    public function show(SurveyImport $import)
    {
        return Api::data($this->service->detail($import));
    }

    public function preview(Request $r, SurveyImport $import, string $sheet)
    {
        Api::imports($r);
        $s = $this->service->sheet($import, $sheet);

        return Api::page(RawRow::where('import_sheet_id', $s->id)->orderBy('row_number'), $r);
    }

    public function assessment(Request $r, SurveyImport $import, string $sheet, SheetAssessment $assessment)
    {
        Api::imports($r);
        $s = $this->service->sheet($import, $sheet);
        $v = $r->validate(['survey_type' => 'required|in:titulados,empleadores', 'header_row' => 'sometimes|integer|min:1|max:'.max(1, $s->row_count)]);

        return Api::data($assessment->assess($s, $v['survey_type'], isset($v['header_row']) ? (int) $v['header_row'] : null));
    }

    public function configure(Request $r, SurveyImport $import, string $sheet)
    {
        Api::imports($r);
        $v = $r->validate(['expected_configuration_revision' => 'required|integer|min:1', 'selected' => 'required|boolean', 'survey_type' => 'required_if:selected,true|in:titulados,empleadores', 'dictionary_version' => 'required_if:selected,true|string', 'header_row' => 'required_if:selected,true|integer|min:1', 'data_region' => 'required_if:selected,true|array:first_data_row,last_data_row,first_column_index,last_column_index', 'data_region.first_data_row' => 'required_if:selected,true|integer|min:2', 'data_region.last_data_row' => 'nullable|integer|min:2', 'data_region.first_column_index' => 'required_if:selected,true|integer|min:0', 'data_region.last_column_index' => 'required_if:selected,true|integer|min:0', 'columns' => 'required_if:selected,true|array|min:1', 'columns.*.column_index' => 'required|integer|min:0', 'columns.*.role' => 'required|in:field,ignore', 'columns.*.field_id' => 'required_if:columns.*.role,field|string', 'multiselect_rules' => 'present|array', 'multiselect_rules.*.field_id' => 'required|string', 'multiselect_rules.*.representation' => 'required|in:delimited_cell,option_columns', 'multiselect_rules.*.column_index' => 'required_if:multiselect_rules.*.representation,delimited_cell|integer|min:0', 'multiselect_rules.*.delimiter' => 'required_if:multiselect_rules.*.representation,delimited_cell|string|min:1|max:5', 'multiselect_rules.*.options' => 'required_if:multiselect_rules.*.representation,option_columns|array|min:1', 'multiselect_rules.*.options.*.column_index' => 'required|integer|min:0', 'multiselect_rules.*.options.*.option_code' => 'required|string', 'multiselect_rules.*.selected_values' => 'required_if:multiselect_rules.*.representation,option_columns|array|min:1', 'multiselect_rules.*.unselected_values' => 'required_if:multiselect_rules.*.representation,option_columns|array|min:1', 'import_profile_id' => 'nullable|uuid|exists:import_profiles,id']);

        return Api::data($this->service->configure($import, $this->service->sheet($import, $sheet), $v));
    }

    public function validateImport(Request $r, SurveyImport $import)
    {
        $v = $r->validate(['expected_configuration_revision' => 'required|integer|min:1']);

        return Api::data($this->service->validateImport($import, $v['expected_configuration_revision']));
    }

    public function prepared(Request $r, SurveyImport $import, string $validation)
    {
        ValidationRun::where('import_id', $import->id)->findOrFail($validation);

        return Api::page(PreparedRow::where('validation_run_id', $validation)->orderBy('id'), $r, function ($row) use ($r) {
            $out = $row->toArray();
            if (! $r->user()->can_view_contacts) {
                foreach ($out['prepared_fields'] as &$field) {
                    if (isset(ResponseWriter::CONTACT_FIELDS[$field['field_id']])) {
                        $field['value'] = null;
                    }
                }
            }

            return $out;
        });
    }

    public function issues(Request $r, SurveyImport $import)
    {
        $v = $r->validate(['validation_run_id' => 'required|uuid'] + ImportReviewController::filterRules());
        ValidationRun::where('import_id', $import->id)->findOrFail($v['validation_run_id']);

        return Api::page(ImportReviewController::filteredIssues($v['validation_run_id'], $v)->orderBy('id'), $r);
    }

    public function decisions(Request $r, SurveyImport $import)
    {
        $v = $r->validate(['expected_configuration_revision' => 'required|integer|min:1', 'decisions' => 'required|array|min:1|max:1000', 'decisions.*.raw_row_id' => 'required|integer|min:1|distinct', 'decisions.*.action' => 'required|in:include,exclude', 'decisions.*.reason' => 'required|string|min:3|max:1000']);

        return Api::data(DB::transaction(function () use ($r, $import, $v) {
            $i = SurveyImport::lockForUpdate()->findOrFail($import->id);
            $this->service->editable($i);
            Api::revision($i->configuration_revision, $v['expected_configuration_revision']);
            $revision = $i->configuration_revision + 1;
            foreach ($v['decisions'] as $d) {
                RawRow::whereIn('import_sheet_id', ImportSheet::where('import_id', $i->id)->select('id'))->findOrFail($d['raw_row_id']);
                RowDecision::create($d + ['import_id' => $i->id, 'actor_id' => $r->user()->id, 'configuration_revision' => $revision]);
            }
            $i->update(['configuration_revision' => $revision, 'status' => 'configured']);

            return ['configuration_revision' => $revision];
        }));
    }

    public function commit(Request $r, SurveyImport $import)
    {
        validator(['key' => $r->header('Idempotency-Key')], ['key' => 'required|uuid'])->validate();
        $v = $r->validate(['configuration_revision' => 'required|integer|min:1', 'validation_run_id' => 'required|uuid', 'policy' => 'required|array:mode,replace_import_id', 'policy.mode' => 'required|in:append,replace', 'policy.replace_import_id' => 'required_if:policy.mode,replace|nullable|uuid']);

        return Api::data($this->service->commit($import, $v, $r->header('Idempotency-Key'), $r->user()->id));
    }
}

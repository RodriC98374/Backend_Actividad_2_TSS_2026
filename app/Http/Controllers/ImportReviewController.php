<?php

namespace App\Http\Controllers;

use App\Domain\Imports\ImportService;
use App\Models\PreparedRow;
use App\Models\QualityIssue;
use App\Models\RowDecision;
use App\Models\SurveyImport;
use App\Models\ValidationRun;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportReviewController extends Controller
{
    public static function filterRules(): array
    {
        return ['code' => 'sometimes|string|max:100', 'field_id' => 'sometimes|string|max:64', 'severity' => 'sometimes|in:warning,blocking'];
    }

    public static function filteredIssues(string $run, array $filters)
    {
        $query = QualityIssue::where('validation_run_id', $run);
        foreach (['code', 'field_id', 'severity'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        return $query;
    }

    public function summary(Request $r, SurveyImport $import)
    {
        $v = $r->validate(['validation_run_id' => 'required|uuid'] + self::filterRules());
        $run = ValidationRun::where('import_id', $import->id)->findOrFail($v['validation_run_id']);
        $query = self::filteredIssues($run->id, $v);
        // Groups are paginated too: unknown field/issue counts must not grow the response unboundedly.
        $response = Api::page((clone $query)->selectRaw('code, field_id, severity, count(*)::int as issue_count, count(distinct raw_row_id)::int as affected_rows')->groupBy('code', 'field_id', 'severity')->orderBy('severity')->orderBy('code')->orderBy('field_id'), $r);
        $body = $response->getData(true);
        $body['summary'] = ['validation_run_id' => $run->id, 'configuration_revision' => $run->configuration_revision, 'current_configuration_revision' => $import->configuration_revision, 'is_current' => $run->configuration_revision === $import->configuration_revision, 'validation_counts' => $run->counts, 'matching_issues' => (clone $query)->count(), 'matching_rows' => (clone $query)->whereNotNull('raw_row_id')->distinct()->count('raw_row_id'), 'selectable_rows' => $this->selectedRows($run->id, $v)->count()];

        return response()->json($body);
    }

    public function exclude(Request $r, SurveyImport $import, ImportService $service)
    {
        $v = $r->validate(['validation_run_id' => 'required|uuid', 'expected_configuration_revision' => 'required|integer|min:1', 'expected_affected_rows' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:1000', 'selection' => 'required|array:code,field_id,severity|min:1', 'selection.code' => 'sometimes|string|max:100', 'selection.field_id' => 'sometimes|string|max:64', 'selection.severity' => 'sometimes|in:warning,blocking']);

        return Api::data(DB::transaction(function () use ($r, $import, $service, $v) {
            $locked = SurveyImport::lockForUpdate()->findOrFail($import->id);
            $service->editable($locked);
            Api::revision($locked->configuration_revision, $v['expected_configuration_revision']);
            $run = ValidationRun::where('import_id', $locked->id)->findOrFail($v['validation_run_id']);
            if ($run->status !== 'completed' || $run->configuration_revision !== $locked->configuration_revision) {
                throw new ApiProblem('validation_not_current', 'Valide la revisión vigente antes de aplicar decisiones por grupo.', 409);
            }
            $rows = $this->selectedRows($run->id, $v['selection']);
            $count = (clone $rows)->count();
            if ($count !== (int) $v['expected_affected_rows']) {
                throw new ApiProblem('review_selection_changed', 'El número de filas no coincide con la selección revisada.', 409);
            }
            $revision = $locked->configuration_revision + 1;
            $rows->chunkById(500, function ($chunk) use ($locked, $r, $v, $revision) {
                $decisions = [];
                foreach ($chunk as $row) {
                    $decisions[] = ['import_id' => $locked->id, 'raw_row_id' => $row->raw_row_id, 'actor_id' => $r->user()->id, 'configuration_revision' => $revision, 'action' => 'exclude', 'reason' => $v['reason'], 'created_at' => now()];
                }
                RowDecision::insert($decisions);
            });
            $locked->update(['configuration_revision' => $revision, 'status' => 'configured']);

            return ['configuration_revision' => $revision, 'affected_rows' => $count, 'requires_validation' => true];
        }));
    }

    private function selectedRows(string $run, array $filters)
    {
        return PreparedRow::where('validation_run_id', $run)->whereIn('raw_row_id', self::filteredIssues($run, $filters)->whereNotNull('raw_row_id')->select('raw_row_id'));
    }
}

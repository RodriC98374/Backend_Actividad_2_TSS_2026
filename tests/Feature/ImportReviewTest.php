<?php

namespace Tests\Feature;

use App\Models\QualityIssue;
use App\Models\RowDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('umss_testing', DB::connection()->getDatabaseName());
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(['is_admin' => true, 'can_view_contacts' => true]), ['admin']);
    }

    private function prepared(int $rows): array
    {
        $dataset = $this->postJson('/api/v1/datasets', ['name' => 'Revisión'])->assertCreated()->json('data.id');
        $file = UploadedFile::fake()->createWithContent('datos.csv', "T12,T03\n".str_repeat("inválido,Sí\n", $rows)."5,No\n");
        $import = $this->postJson('/api/v1/imports', ['dataset_id' => $dataset, 'file' => $file, 'csv_delimiter' => ','])->assertCreated()->json('data');
        $this->putJson('/api/v1/imports/'.$import['id'].'/sheets/'.$import['sheets'][0]['id'], ['expected_configuration_revision' => 1, 'selected' => true, 'survey_type' => 'titulados', 'dictionary_version' => config('surveys.dictionary_version'), 'header_row' => 1, 'data_region' => ['first_data_row' => 2, 'last_data_row' => null, 'first_column_index' => 0, 'last_column_index' => 1], 'columns' => [['column_index' => 0, 'role' => 'field', 'field_id' => 'T12'], ['column_index' => 1, 'role' => 'field', 'field_id' => 'T03']], 'multiselect_rules' => []])->assertOk();
        $run = $this->postJson('/api/v1/imports/'.$import['id'].'/validate', ['expected_configuration_revision' => 2])->assertOk()->json('data.id');

        return [$import['id'], $run];
    }

    public function test_review_over_a_thousand_rows_is_grouped_paginated_and_excluded_without_client_id_lists(): void
    {
        [$import, $run] = $this->prepared(1005);
        $url = "/api/v1/imports/$import";
        $this->getJson("$url/review-summary?validation_run_id=$run&per_page=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.matching_rows', 1005)->assertJsonPath('summary.selectable_rows', 1005)->assertJsonPath('data.0.issue_count', 1005);
        $this->getJson("$url/issues?validation_run_id=$run&field_id=T12&per_page=100&page=11")->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 1005);
        $body = ['validation_run_id' => $run, 'expected_configuration_revision' => 2, 'expected_affected_rows' => 1005, 'selection' => ['field_id' => 'T12', 'code' => 'invalid_value'], 'reason' => 'Excluir este grupo revisado'];
        $this->postJson("$url/review-exclusions", $body)->assertOk()->assertJsonPath('data.affected_rows', 1005)->assertJsonPath('data.configuration_revision', 3)->assertJsonPath('data.requires_validation', true);
        $this->assertSame(1005, RowDecision::where('import_id', $import)->count());
        $this->postJson("$url/review-exclusions", $body)->assertConflict();
        $this->getJson("$url/review-summary?validation_run_id=$run")->assertOk()->assertJsonPath('summary.is_current', false);
        $this->postJson("$url/validate", ['expected_configuration_revision' => 3])->assertOk()->assertJsonPath('data.counts.included_rows', 1)->assertJsonPath('data.counts.excluded_rows', 1005);
    }

    public function test_counts_are_unique_rows_and_bulk_decisions_reject_wrong_scope_count_or_revision(): void
    {
        [$import, $run] = $this->prepared(2);
        $issue = QualityIssue::where('validation_run_id', $run)->firstOrFail();
        QualityIssue::create(['import_id' => $import, 'validation_run_id' => $run, 'raw_row_id' => $issue->raw_row_id, 'field_id' => 'T12', 'code' => 'another_warning', 'severity' => 'warning', 'message' => 'Otra incidencia en la misma fila']);
        $url = "/api/v1/imports/$import";
        $this->getJson("$url/review-summary?validation_run_id=$run")->assertOk()->assertJsonPath('summary.matching_issues', 3)->assertJsonPath('summary.matching_rows', 2);
        $body = ['validation_run_id' => $run, 'expected_configuration_revision' => 2, 'expected_affected_rows' => 3, 'selection' => ['severity' => 'warning'], 'reason' => 'Revisión de calidad'];
        $this->postJson("$url/review-exclusions", $body)->assertConflict();
        $this->assertDatabaseCount('row_decisions', 0);
        $body['selection'] = [];
        $this->postJson("$url/review-exclusions", $body)->assertUnprocessable();
        [$other, $otherRun] = $this->prepared(1);
        $this->getJson("$url/review-summary?validation_run_id=$otherRun")->assertNotFound();
        $body['selection'] = ['code' => 'invalid_value'];
        $body['validation_run_id'] = $otherRun;
        $this->postJson("$url/review-exclusions", $body)->assertNotFound();
    }
}

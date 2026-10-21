<?php

namespace Tests\Feature;

use App\Domain\Catalog\Catalog;
use App\Models\FormInvitation;
use App\Models\RawRow;
use App\Models\ResponseAnswer;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SurveyPlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('umss_testing', DB::connection()->getDatabaseName());
        Storage::fake('local');
        $this->admin = User::factory()->create(['is_admin' => true, 'can_view_contacts' => true]);
        Sanctum::actingAs($this->admin, ['admin']);
    }

    private function dataset(): string
    {
        return $this->postJson('/api/v1/datasets', ['name' => 'Estudio de prueba'])->assertCreated()->json('data.id');
    }

    public function test_paginated_pdf_requires_confirmation_and_counts_people_in_statistics(): void
    {
        $dataset = $this->dataset();
        $file = UploadedFile::fake()->createWithContent('empleadores.pdf', file_get_contents(base_path('tests/Fixtures/employers-paginated.pdf')));
        $import = $this->postJson('/api/v1/imports', ['dataset_id' => $dataset, 'file' => $file])->assertCreated()->json('data');
        $id = $import['id'];
        $sheet = $import['sheets'][0]['id'];
        $this->assertSame(5, $import['sheets'][0]['row_count']);
        $this->assertSame(32, $import['sheets'][0]['column_count']);
        $this->assertDatabaseCount('survey_responses', 0);
        $raw = RawRow::where('import_sheet_id', $sheet)->where('row_number', 4)->firstOrFail();
        $this->assertSame(['page' => 32, 'table_row' => 1], $raw->original_cells[31]['source']);
        $cells = $raw->original_cells;
        $cells[14]['pdf_text_clipped'] = true;
        $raw->update(['original_cells' => $cells]);
        $this->getJson("/api/v1/imports/$id/sheets/$sheet/assessment?survey_type=empleadores&header_row=1")
            ->assertOk()->assertJsonPath('data.columns.14.field_candidates.0', 'P01');
        $this->putJson("/api/v1/imports/$id/sheets/$sheet", [
            'expected_configuration_revision' => 1, 'selected' => true, 'survey_type' => 'empleadores',
            'dictionary_version' => config('surveys.dictionary_version'), 'header_row' => 1,
            'data_region' => ['first_data_row' => 2, 'last_data_row' => null, 'first_column_index' => 14, 'last_column_index' => 15],
            'columns' => [['column_index' => 14, 'role' => 'field', 'field_id' => 'P01'], ['column_index' => 15, 'role' => 'field', 'field_id' => 'P02']], 'multiselect_rules' => [],
        ])->assertOk();
        $this->validateAndCommit($id);
        $this->getJson("/api/v1/datasets/$dataset/analytics/overview?survey_type=empleadores")
            ->assertOk()->assertJsonPath('data.total_responses', 4)
            ->assertJsonPath('data.results.0.result.denominator', 3)
            ->assertJsonPath('data.results.1.result.denominator', 4);
        $this->assertDatabaseHas('quality_issues', ['import_id' => $id, 'code' => 'pdf_text_clipped']);
    }

    public function test_invalid_pdf_does_not_publish_responses(): void
    {
        $this->postJson('/api/v1/imports', ['dataset_id' => $this->dataset(),
            'file' => UploadedFile::fake()->createWithContent('invalid.pdf', 'not a PDF'),
        ])->assertUnprocessable()->assertJsonPath('code', 'unsupported_pdf');
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_automatic_overview_preserves_population_versions_and_missing_answers(): void
    {
        $dataset = $this->dataset();
        [$import] = $this->upload($dataset, "Posgrado,Interes,Tiempo,Nivel\nSí,No,2,\nNo,Sí,3,\n", ['T03', 'T08', 'T13', 'T04']);
        $this->validateAndCommit($import);
        $path = "/api/v1/datasets/$dataset/analytics/overview?survey_type=titulados";
        $result = $this->getJson($path)->assertOk()->assertJsonPath('data.total_responses', 2)
            ->assertJsonPath('data.filtered_responses', 2)->assertJsonCount(2, 'data.results')->json('data');
        $this->assertSame(['T03', 'T08'], array_column(array_column($result['results'], 'field'), 'id'));
        foreach ($result['results'] as $entry) {
            $this->assertSame($result['data_version'], $entry['result']['data_version']);
            $this->assertSame(2, $entry['result']['denominator']);
        }
        $filters = [['field_id' => 'T03', 'operator' => 'eq', 'value' => false]];
        $filtered = $this->getJson($path.'&filters='.urlencode(json_encode($filters)))
            ->assertOk()->assertJsonPath('data.total_responses', 2)->assertJsonPath('data.filtered_responses', 1)
            ->assertJsonCount(2, 'data.results')->json('data');
        $this->assertSame(1, $filtered['results'][0]['result']['denominator']);
        $filters[] = ['field_id' => 'T08', 'operator' => 'eq', 'value' => false];
        $empty = $this->getJson($path.'&filters='.urlencode(json_encode($filters)))
            ->assertOk()->assertJsonPath('data.filtered_responses', 0)->assertJsonCount(2, 'data.results')->json('data');
        $this->assertSame(0, $empty['results'][0]['result']['denominator']);
        $this->assertNull($empty['results'][0]['result']['items'][0]['percentage']);
        $other = $this->dataset();
        $this->getJson("/api/v1/datasets/$other/analytics/overview?survey_type=titulados")
            ->assertOk()->assertJsonPath('data.total_responses', 0)->assertJsonCount(0, 'data.results');
    }

    private function upload(string $dataset, string $csv = "Repetido,Repetido\nSí,No\nNo,Sí\n", array $fields = ['T03', 'T08']): array
    {
        $i = $this->postJson('/api/v1/imports', ['file' => UploadedFile::fake()->createWithContent('encuestas.csv', $csv), 'dataset_id' => $dataset, 'csv_delimiter' => ','])->assertCreated()->json('data');
        $sheet = $i['sheets'][0]['id'];
        $map = [];
        foreach ($fields as $n => $f) {
            $map[] = ['column_index' => $n, 'role' => 'field', 'field_id' => $f];
        }
        $config = ['expected_configuration_revision' => 1, 'selected' => true, 'survey_type' => 'titulados', 'dictionary_version' => config('surveys.dictionary_version'), 'header_row' => 1, 'data_region' => ['first_data_row' => 2, 'last_data_row' => null, 'first_column_index' => 0, 'last_column_index' => count($fields) - 1], 'columns' => $map, 'multiselect_rules' => []];
        $this->putJson('/api/v1/imports/'.$i['id'].'/sheets/'.$sheet, $config)->assertOk();

        return [$i['id'], $sheet, $config];
    }

    private function validateAndCommit(string $id, int $revision = 2): array
    {
        $run = $this->postJson("/api/v1/imports/$id/validate", ['expected_configuration_revision' => $revision])->assertOk()->json('data');
        $body = ['configuration_revision' => $revision, 'validation_run_id' => $run['id'], 'policy' => ['mode' => 'append']];
        $key = (string) Str::uuid();
        $result = $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/imports/$id/commit", $body)->assertOk()->json('data');

        return [$result, $body, $key];
    }

    public function test_import_list_filters_before_pagination(): void
    {
        $first = $this->dataset();
        $second = $this->dataset();
        [$id] = $this->upload($first);
        for ($n = 0; $n < 6; $n++) {
            $this->upload($second);
        }
        $this->getJson("/api/v1/imports?dataset_id=$first&per_page=5")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/imports?per_page=5')->assertOk()->assertJsonPath('meta.total', 7);
        $this->getJson('/api/v1/imports?dataset_id=invalid')->assertUnprocessable();
    }

    public function test_import_commit_replay_and_distribution(): void
    {
        $dataset = $this->dataset();
        [$id,$sheet] = $this->upload($dataset);
        $this->getJson("/api/v1/imports/$id/sheets/$sheet/preview")->assertOk()->assertJsonPath('data.0.original_cells.0.value', 'Repetido')->assertJsonPath('data.0.original_cells.1.value', 'Repetido');
        [$result,$body,$key] = $this->validateAndCommit($id);
        $this->assertSame(1, $result['data_version']);
        $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/imports/$id/commit", $body)->assertOk()->assertJsonPath('data.data_version', 1);
        $body['configuration_revision'] = 99;
        $this->postJson("/api/v1/imports/$id/commit", $body)->assertConflict();
        $this->assertDatabaseCount('survey_responses', 2);
        $this->getJson("/api/v1/datasets/$dataset/analytics/distribution?survey_type=titulados&field_id=T03")->assertOk()->assertJsonPath('data.denominator', 2)->assertJsonPath('data.items.0.percentage', 50);
        $this->assertIsBool(ResponseAnswer::where('field_id', 'T03')->first()->value);
    }

    public function test_decisions_survive_revalidation_and_stale_revision_rejected(): void
    {
        $dataset = $this->dataset();
        [$id,$sheet] = $this->upload($dataset);
        $old = $this->postJson("/api/v1/imports/$id/validate", ['expected_configuration_revision' => 2])->assertOk()->json('data.id');
        $row = RawRow::where('import_sheet_id', $sheet)->where('row_number', 2)->value('id');
        $this->postJson("/api/v1/imports/$id/row-decisions", ['expected_configuration_revision' => 2, 'decisions' => [['raw_row_id' => $row, 'action' => 'exclude', 'reason' => 'Prueba incompleta']]])->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/imports/$id/commit", ['configuration_revision' => 2, 'validation_run_id' => $old, 'policy' => ['mode' => 'append']])->assertConflict();
        $this->validateAndCommit($id, 3);
        $this->assertDatabaseHas('survey_responses', ['raw_row_id' => $row, 'included' => false]);
        $this->assertSame(1, SurveyResponse::where('included', true)->count());
    }

    public function test_region_change_only_publishes_current_prepared_rows(): void
    {
        $dataset = $this->dataset();
        [$id,$sheet,$config] = $this->upload($dataset);
        $this->postJson("/api/v1/imports/$id/validate", ['expected_configuration_revision' => 2])->assertOk();
        $config['expected_configuration_revision'] = 2;
        $config['data_region']['first_data_row'] = 3;
        $this->putJson("/api/v1/imports/$id/sheets/$sheet", $config)->assertOk();
        $this->validateAndCommit($id, 3);
        $this->assertDatabaseCount('raw_rows', 3);
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_filters_numeric_summary_empty_denominator_and_exports(): void
    {
        $dataset = $this->dataset();
        [$id] = $this->upload($dataset, "Trabajo,Meses\nSí,10\nNo,20\nSí,30\n", ['T08', 'T12']);
        $this->validateAndCommit($id);
        $filter = json_encode([['field_id' => 'T08', 'operator' => 'eq', 'value' => true]]);
        $query = http_build_query(['survey_type' => 'titulados', 'field_id' => 'T12', 'filters' => $filter]);
        $this->getJson("/api/v1/datasets/$dataset/analytics/numeric-summary?$query")->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('data.mean', 20);
        $this->getJson("/api/v1/datasets/$dataset/analytics/distribution?survey_type=titulados&field_id=T03")->assertOk()->assertJsonPath('data.denominator', 0)->assertJsonPath('data.items.0.percentage', null);
        $body = ['expected_data_version' => 1, 'format' => 'csv', 'kind' => 'distribution', 'survey_type' => 'titulados', 'field_id' => 'T08'];
        $export = $this->postJson("/api/v1/datasets/$dataset/exports", $body)->assertCreated()->json('data.id');
        $this->getJson("/api/v1/exports/$export/download")->assertOk();
        $body['format'] = 'xlsx';
        $this->postJson("/api/v1/datasets/$dataset/exports", $body)->assertCreated();
        $body['expected_data_version'] = 0;
        $this->postJson("/api/v1/datasets/$dataset/exports", $body)->assertConflict();
    }

    public function test_correction_history_and_exclusion_change_statistics(): void
    {
        $dataset = $this->dataset();
        [$id] = $this->upload($dataset);
        $this->validateAndCommit($id);
        $response = SurveyResponse::first();
        $body = ['expected_response_revision' => 1, 'reason' => 'Corrección documentada', 'answers' => [['field_id' => 'T03', 'value' => true]], 'included' => false];
        $this->patchJson('/api/v1/responses/'.$response->id, $body)->assertOk()->assertJsonPath('data.data_version', 2);
        $this->patchJson('/api/v1/responses/'.$response->id, $body)->assertConflict();
        $this->assertDatabaseCount('change_log', 2);
        $this->getJson("/api/v1/datasets/$dataset/analytics/distribution?survey_type=titulados&field_id=T03")->assertOk()->assertJsonPath('data.denominator', 1);
    }

    public function test_capture_is_single_use_and_keeps_source_distinct(): void
    {
        $dataset = $this->dataset();
        $inv = $this->postJson("/api/v1/datasets/$dataset/invitations", [])->assertCreated()->json('data');
        $fields = collect(app(Catalog::class)->fields('titulados'))->keyBy('id');
        $body = ['consent' => true, 'answers' => ['X01' => 2020, 'X03' => 'sin_trabajo', 'X05' => 'maestria', 'X06' => $fields['X06']['options'][0]['code'], 'X07' => ['recursos_propios'], 'T08' => false]];
        $this->withHeader('Authorization', 'Bearer '.$inv['token'])->getJson('/api/v1/capture/schema')->assertOk();
        $one = $this->postJson('/api/v1/capture/submissions', $body)->assertCreated()->json('data');
        $this->postJson('/api/v1/capture/submissions', $body)->assertCreated()->assertJsonPath('data.response_id', $one['response_id']);
        $body['answers']['X01'] = 2021;
        $this->postJson('/api/v1/capture/submissions', $body)->assertConflict();
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseHas('survey_responses', ['source_kind' => 'direct', 'raw_row_id' => null]);
        FormInvitation::where('id', $inv['invitation']['id'])->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/capture/schema')->assertUnauthorized();
    }

    public function test_contacts_require_explicit_permission(): void
    {
        $dataset = $this->dataset();
        [$id,$sheet] = $this->upload($dataset, "Acepta,Correo\nSí,persona@example.test\n", ['T31', 'T32']);
        $run = $this->postJson("/api/v1/imports/$id/validate", ['expected_configuration_revision' => 2])->assertOk()->json('data.id');
        $this->admin->forceFill(['can_view_contacts' => false])->save();
        Sanctum::actingAs($this->admin->fresh(), ['admin']);
        $this->getJson("/api/v1/imports/$id/sheets/$sheet/preview")->assertForbidden();
        $json = $this->getJson("/api/v1/imports/$id/validations/$run/rows")->assertOk()->getContent();
        $this->assertStringNotContainsString('persona@example.test', $json);
    }

    public function test_unknown_operator_and_field_are_rejected(): void
    {
        $dataset = $this->dataset();
        $filter = json_encode([['field_id' => 'T08', 'operator' => 'raw_sql', 'value' => '1=1']]);
        $query = http_build_query(['survey_type' => 'titulados', 'field_id' => 'T08', 'filters' => $filter]);
        $this->getJson("/api/v1/datasets/$dataset/analytics/distribution?$query")->assertUnprocessable();
        $this->getJson("/api/v1/datasets/$dataset/analytics/numeric-summary?survey_type=titulados&field_id=T13")->assertUnprocessable();
    }

    public function test_multiselect_denominator_filters_and_profiles(): void
    {
        $dataset = $this->dataset();
        $i = $this->postJson('/api/v1/imports', ['file' => UploadedFile::fake()->createWithContent('multiple.csv', "Fuentes,Trabaja\nrecursos_propios;beca,Sí\nbeca,No\n"), 'dataset_id' => $dataset, 'csv_delimiter' => ','])->assertCreated()->json('data');
        $id = $i['id'];
        $sheet = $i['sheets'][0]['id'];
        $this->putJson("/api/v1/imports/$id/sheets/$sheet", [
            'expected_configuration_revision' => 1, 'selected' => true, 'survey_type' => 'titulados', 'dictionary_version' => config('surveys.dictionary_version'), 'header_row' => 1,
            'data_region' => ['first_data_row' => 2, 'first_column_index' => 0, 'last_column_index' => 1],
            'columns' => [['column_index' => 0, 'role' => 'field', 'field_id' => 'X07'], ['column_index' => 1, 'role' => 'field', 'field_id' => 'T08']],
            'multiselect_rules' => [['field_id' => 'X07', 'representation' => 'delimited_cell', 'column_index' => 0, 'delimiter' => ';']],
        ])->assertOk();
        $this->postJson('/api/v1/import-profiles', ['name' => 'CSV múltiple', 'sheet_id' => $sheet])->assertCreated()->assertJsonPath('data.profile_version', 1);
        $this->validateAndCommit($id);
        $stats = $this->getJson("/api/v1/datasets/$dataset/analytics/distribution?survey_type=titulados&field_id=X07")->assertOk()->json('data');
        $this->assertSame(2, $stats['denominator']);
        $this->assertEquals(150, array_sum(array_column($stats['items'], 'percentage')));
        $query = http_build_query(['survey_type' => 'titulados', 'field_id' => 'T08', 'filters' => json_encode([['field_id' => 'X07', 'operator' => 'contains_all', 'value' => ['recursos_propios', 'beca']]])]);
        $this->getJson("/api/v1/datasets/$dataset/analytics/distribution?$query")->assertOk()->assertJsonPath('data.denominator', 1)->assertJsonPath('data.items.0.percentage', 100);
        $this->getJson("/api/v1/datasets/$dataset/summary")->assertOk()->assertJsonPath('data.data_version', 1);
    }

    public function test_crosstab_excludes_missing_pairs_and_rejects_duplicate_file(): void
    {
        $dataset = $this->dataset();
        $csv = "Posgrado,Trabajo\nSí,No\nNo,Sí\nSí,\n";
        [$id] = $this->upload($dataset, $csv);
        $this->validateAndCommit($id);
        $this->getJson("/api/v1/datasets/$dataset/analytics/crosstab?survey_type=titulados&field_id=T03&column_field_id=T08")->assertOk()->assertJsonPath('data.denominator', 2)->assertJsonPath('data.total_responses', 3);
        [$other] = $this->upload($dataset, $csv);
        $run = $this->postJson("/api/v1/imports/$other/validate", ['expected_configuration_revision' => 2])->assertOk()->json('data.id');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/imports/$other/commit", ['configuration_revision' => 2, 'validation_run_id' => $run, 'policy' => ['mode' => 'append']])->assertConflict()->assertJsonPath('code', 'duplicate_file');
        $this->assertDatabaseCount('survey_responses', 3);
        $empty = $this->dataset();
        $this->getJson("/api/v1/datasets/$empty/analytics/distribution?survey_type=titulados&field_id=T08")->assertOk()->assertJsonPath('data.denominator', 0);
    }
}

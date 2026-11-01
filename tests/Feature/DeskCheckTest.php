<?php

namespace Tests\Feature;

use App\Models\DataExport;
use App\Models\RawRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Fixed desk check compared with an independently calculated Python reference. */
class DeskCheckTest extends TestCase
{
    use RefreshDatabase;

    private array $reference;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('umss_testing', DB::connection()->getDatabaseName());
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(['is_admin' => true, 'can_view_contacts' => true]), ['admin']);
        $this->reference = json_decode(file_get_contents(base_path('tests/Fixtures/desk-check/resultados-esperados.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($this->reference['source_sha256'], hash_file('sha256', base_path('tests/Fixtures/desk-check/encuestas-controladas.csv')));
    }

    private function analyticsQuery(array $spec): string
    {
        if (isset($spec['filters'])) {
            $spec['filters'] = json_encode($spec['filters']);
        }

        return http_build_query(['survey_type' => 'titulados'] + $spec);
    }

    private function assertNumber(mixed $expected, mixed $actual): void
    {
        if ($expected === null) {
            $this->assertNull($actual);
        } else {
            $this->assertIsNumeric($actual);
            $this->assertEqualsWithDelta($expected, $actual, 1e-9);
        }
    }

    private function assertScenario(string $dataset, string $scenario, int $version, array $filters = []): void
    {
        $expected = $this->reference['scenarios'][$scenario];
        foreach ($expected['fields'] as $field => $values) {
            $kind = $field === 'T12' ? 'numeric-summary' : 'distribution';
            $query = $this->analyticsQuery(['field_id' => $field, 'filters' => $filters]);
            $actual = $this->getJson("/api/v1/datasets/$dataset/analytics/$kind?$query")->assertOk()
                ->assertJsonPath('data.data_version', $version)
                ->assertJsonPath('data.total_responses', $expected['total_responses'])
                ->assertJsonPath('data.denominator', $values['denominator'])->json('data');
            $this->assertSame($values['states'], $actual['states']);
            if ($kind === 'numeric-summary') {
                foreach (['count', 'mean', 'min', 'max', 'sample_standard_deviation'] as $key) {
                    $this->assertNumber($values[$key], $actual[$key]);
                }
            } else {
                foreach ($actual['items'] as $item) {
                    $this->assertSame($values['items'][$item['code']]['count'], $item['count']);
                    $this->assertNumber($values['items'][$item['code']]['percentage'], $item['percentage']);
                }
                $this->assertCount(count($values['items']), $actual['items']);
            }
        }
        $query = $this->analyticsQuery(['field_id' => 'T03', 'column_field_id' => 'T08', 'filters' => $filters]);
        $actual = $this->getJson("/api/v1/datasets/$dataset/analytics/crosstab?$query")->assertOk()
            ->assertJsonPath('data.data_version', $version)
            ->assertJsonPath('data.denominator', $expected['crosstab']['denominator'])->json('data');
        $this->assertCount(4, $actual['cells']);
        foreach ($actual['cells'] as $cell) {
            $expectedCell = $expected['crosstab']['cells'][$cell['row_code'].'/'.$cell['column_code']];
            $this->assertSame($expectedCell['count'], $cell['count']);
            $this->assertNumber($expectedCell['percentage'], $cell['percentage']);
        }
        $query = $this->analyticsQuery(['filters' => $filters]);
        $overview = $this->getJson("/api/v1/datasets/$dataset/analytics/overview?$query")->assertOk()
            ->assertJsonPath('data.data_version', $version)
            ->assertJsonPath('data.filtered_responses', $expected['total_responses'])->json('data');
        foreach ($overview['results'] as $entry) {
            $this->assertSame($version, $entry['result']['data_version']);
        }
    }

    /** Compare actual cells in both file formats, including metadata, against golden values. */
    private function assertExports(string $dataset, array $filters = [], string $scenario = 'initial'): void
    {
        foreach ([['distribution', 'T08'], ['numeric-summary', 'T12'], ['crosstab', 'T03']] as [$kind, $field]) {
            foreach (['csv', 'xlsx'] as $format) {
                $spec = ['expected_data_version' => 1, 'format' => $format, 'kind' => $kind,
                    'survey_type' => 'titulados', 'field_id' => $field, 'filters' => $filters];
                if ($kind === 'crosstab') {
                    $spec['column_field_id'] = 'T08';
                }
                $id = $this->postJson("/api/v1/datasets/$dataset/exports", $spec)->assertCreated()->json('data.id');
                $export = DataExport::findOrFail($id);
                $this->getJson("/api/v1/exports/$id/download")->assertOk();
                $path = Storage::disk('local')->path($export->storage_path);
                if ($format === 'csv') {
                    $handle = fopen($path, 'rb');
                    $headers = fgetcsv($handle, escape: '');
                    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
                    $rows = [];
                    while (($row = fgetcsv($handle, escape: '')) !== false) {
                        $rows[] = $row;
                    }
                    fclose($handle);
                } else {
                    $book = IOFactory::load($path);
                    $rows = $book->getActiveSheet()->toArray();
                    $headers = array_shift($rows);
                    $book->disconnectWorksheets();
                }
                $expected = $this->reference['scenarios'][$scenario];
                $this->assertCount($kind === 'crosstab' ? 4 : ($kind === 'distribution' ? 2 : 1), $rows);
                foreach ($rows as $values) {
                    $row = array_combine($headers, $values);
                    $this->assertSame($dataset, $row['dataset_id']);
                    $this->assertSame(1, (int) $row['data_version']);
                    $this->assertSame($filters, json_decode($row['filters'], true));
                    $this->assertSame('normalization-v1', $row['rules_version']);
                    $base = $kind === 'crosstab' ? $expected['crosstab'] : $expected['fields'][$field];
                    $this->assertSame($base['denominator'], (int) $row['denominator']);
                    if ($kind === 'numeric-summary') {
                        foreach (['count', 'mean', 'min', 'max', 'sample_standard_deviation'] as $key) {
                            $this->assertNumber($base[$key], $row[$key] === '' ? null : $row[$key]);
                        }
                    } else {
                        $cell = $kind === 'crosstab' ? $base['cells'][$row['row_code'].'/'.$row['column_code']] : $base['items'][$row['code']];
                        $this->assertSame($cell['count'], (int) $row['count']);
                        $this->assertNumber($cell['percentage'], $row['percentage']);
                    }
                }
            }
        }
    }

    public function test_desk_check_from_original_cells_to_statistics_exports_and_audit(): void
    {
        $dataset = $this->postJson('/api/v1/datasets', ['name' => 'Prueba de escritorio controlada'])->assertCreated()->json('data.id');
        $import = $this->postJson('/api/v1/imports', ['dataset_id' => $dataset, 'csv_delimiter' => ',',
            'file' => UploadedFile::fake()->createWithContent('encuestas-controladas.csv', file_get_contents(base_path('tests/Fixtures/desk-check/encuestas-controladas.csv'))),
        ])->assertCreated()->json('data');
        $path = '/api/v1/imports/'.$import['id'];
        $sheet = $import['sheets'][0];
        $this->assertSame(13, $sheet['row_count']);
        $this->assertSame(5, $sheet['column_count']);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->putJson($path.'/sheets/'.$sheet['id'], [
            'expected_configuration_revision' => 1, 'selected' => true, 'survey_type' => 'titulados',
            'dictionary_version' => config('surveys.dictionary_version'), 'header_row' => 1,
            'data_region' => ['first_data_row' => 2, 'last_data_row' => 13, 'first_column_index' => 0, 'last_column_index' => 4],
            'columns' => [['column_index' => 0, 'role' => 'ignore'],
                ...array_map(fn ($field, $index) => ['column_index' => $index + 1, 'role' => 'field', 'field_id' => $field], ['T03', 'T08', 'T12', 'X07'], range(0, 3))],
            'multiselect_rules' => [['field_id' => 'X07', 'representation' => 'delimited_cell', 'column_index' => 4, 'delimiter' => ';']],
        ])->assertOk()->assertJsonPath('data.configuration_revision', 2);
        $oldRun = $this->postJson($path.'/validate', ['expected_configuration_revision' => 2])->assertOk()
            ->assertJsonPath('data.counts.prepared_rows', 12)->assertJsonPath('data.counts.warning_issues', 3)->json('data.id');
        $prepared = $this->getJson($path.'/validations/'.$oldRun.'/rows')->assertOk()->json('data');
        $rawRows = RawRow::where('import_sheet_id', $sheet['id'])->get()->keyBy('id');
        $responsesByPerson = [];
        foreach ($prepared as $row) {
            $raw = $rawRows[$row['raw_row_id']];
            $expected = $this->reference['rows'][$raw->row_number - 2];
            $answers = collect($row['prepared_fields'])->keyBy('field_id');
            foreach ($expected['answers'] as $field => $answer) {
                $this->assertSame($answer['state'], $answers[$field]['state']);
                if ($field === 'T12') {
                    $this->assertNumber($answer['value'], $answers[$field]['value']);
                } else {
                    $this->assertSame($answer['value'], $answers[$field]['value']);
                }
                $this->assertNotEmpty($answers[$field]['provenance']['rule_codes']);
                $this->assertNotEmpty($answers[$field]['provenance']['column_indices']);
            }
        }
        $decisions = [];
        foreach ($this->reference['rows'] as $row) {
            if (! $row['included']) {
                $raw = $rawRows->firstWhere('row_number', $row['file_row']);
                $decisions[] = ['raw_row_id' => $raw->id, 'action' => 'exclude', 'reason' => $row['exclusion_reason']];
            }
        }
        $this->postJson($path.'/row-decisions', ['expected_configuration_revision' => 2, 'decisions' => $decisions])
            ->assertOk()->assertJsonPath('data.configuration_revision', 3);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($path.'/commit', [
            'configuration_revision' => 3, 'validation_run_id' => $oldRun, 'policy' => ['mode' => 'append'],
        ])->assertConflict();
        $this->assertDatabaseCount('survey_responses', 0);
        $run = $this->postJson($path.'/validate', ['expected_configuration_revision' => 3])->assertOk()
            ->assertJsonPath('data.counts.included_rows', 10)->assertJsonPath('data.counts.excluded_rows', 2)->json('data.id');
        $payload = ['configuration_revision' => 3, 'validation_run_id' => $run, 'policy' => ['mode' => 'append']];
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson($path.'/commit', $payload)->assertOk()->assertJsonPath('data.data_version', 1);
        $this->withHeader('Idempotency-Key', $key)->postJson($path.'/commit', $payload)->assertOk()->assertJsonPath('data.data_version', 1);
        $this->assertDatabaseCount('survey_responses', 12);
        $this->assertDatabaseCount('row_decisions', 2);
        $list = $this->getJson("/api/v1/datasets/$dataset/responses?survey_type=titulados&included=1")->assertOk()
            ->assertJsonPath('meta.total', 10)->json('data');
        foreach ($list as $response) {
            $responsesByPerson[$rawRows[$response['raw_row_id']]->row_number - 2] = $response;
        }
        $this->assertScenario($dataset, 'initial', 1);
        $filters = [['field_id' => 'T03', 'operator' => 'eq', 'value' => true]];
        $this->assertScenario($dataset, 'T03_yes', 1, $filters);
        $this->assertScenario($dataset, 'empty', 1, [...$filters, ['field_id' => 'T03', 'operator' => 'eq', 'value' => false]]);
        $this->getJson("/api/v1/datasets/$dataset/analytics/distribution?survey_type=titulados&field_id=T01")
            ->assertOk()->assertJsonPath('data.denominator', 0)->assertJsonPath('data.states.no_disponible', 10)->assertJsonPath('data.items.0.percentage', null);
        $this->assertExports($dataset);
        $this->assertExports($dataset, $filters, 'T03_yes');

        $person = $responsesByPerson[0];
        $change = ['expected_response_revision' => 1, 'reason' => 'Comprobación manual P01: 0 a 2 años',
            'answers' => [['field_id' => 'T12', 'value' => 2]]];
        $this->patchJson('/api/v1/responses/'.$person['id'], $change)->assertOk()->assertJsonPath('data.data_version', 2);
        $this->patchJson('/api/v1/responses/'.$person['id'], $change)->assertConflict();
        $this->getJson('/api/v1/responses/'.$person['id'].'/history')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reason', $change['reason'])->assertJsonPath('data.0.before.value', 0)
            ->assertJsonPath('data.0.after.value', 2);
        $this->assertScenario($dataset, 'corrected_P01_T12_2', 2);
        $this->postJson("/api/v1/datasets/$dataset/exports", [
            'expected_data_version' => 1, 'format' => 'csv', 'kind' => 'numeric-summary', 'survey_type' => 'titulados', 'field_id' => 'T12',
        ])->assertConflict();
        $this->patchJson('/api/v1/responses/'.$responsesByPerson[1]['id'], [
            'expected_response_revision' => 1, 'reason' => 'Exclusión sintética P02 después de corrección P01', 'included' => false,
        ])->assertOk()->assertJsonPath('data.data_version', 3);
        $this->assertScenario($dataset, 'excluded_P02_after_correction', 3);
    }
}

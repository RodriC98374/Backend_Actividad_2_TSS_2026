<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SheetAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('umss_testing', DB::connection()->getDatabaseName());
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(['is_admin' => true, 'can_view_contacts' => true]), ['admin']);
    }

    private function upload(string $content): array
    {
        $dataset = $this->postJson('/api/v1/datasets', ['name' => 'Formatos variables'])->assertCreated()->json('data.id');

        return $this->postJson('/api/v1/imports', ['dataset_id' => $dataset, 'file' => UploadedFile::fake()->createWithContent('datos.csv', $content), 'csv_delimiter' => ','])->assertCreated()->json('data');
    }

    private function url(array $import): string
    {
        return '/api/v1/imports/'.$import['id'].'/sheets/'.$import['sheets'][0]['id'].'/assessment?survey_type=titulados';
    }

    public function test_preamble_reordered_duplicate_and_unknown_columns_remain_reviewable(): void
    {
        $import = $this->upload("Informe institucional\n\nT08, ANIOS_VIDA_PROFESIONAL ,T08,Extra,T27\nSí,3,No,abc,\nNo,4,Sí,def,\n");
        $url = $this->url($import);
        $this->getJson($url)->assertOk()->assertJsonPath('data.header_candidates.0.row_number', 3)->assertJsonPath('data.columns', [])->assertJsonPath('data.requires_confirmation', true);
        $data = $this->getJson($url.'&header_row=3')->assertOk()->json('data');
        $this->assertSame(2, $data['profiled_rows']);
        $this->assertSame(['T08'], $data['columns'][0]['field_candidates']);
        $this->assertSame(['T12'], $data['columns'][1]['field_candidates']);
        $this->assertSame(['T08'], $data['columns'][2]['field_candidates']);
        $this->assertSame([], $data['columns'][3]['field_candidates']);
        $this->assertSame(2, $data['columns'][3]['distinct_count']);
        $this->assertTrue($data['columns'][4]['requires_multiselect_rule']);
        $this->assertSame(2, $data['columns'][4]['empty_count']);
        $this->assertDatabaseHas('imports', ['id' => $import['id'], 'configuration_revision' => 1, 'status' => 'awaiting_configuration']);
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_employer_headers_suggest_correct_survey_without_changing_import(): void
    {
        $import = $this->upload("Marca temporal,1)Nombre de la Empresa o Institución\n2026-01-01,Empresa de ejemplo\n");
        $data = $this->getJson($this->url($import).'&header_row=1')->assertOk()->json('data');
        $suggestions = array_column($data['survey_suggestions'], 'matched_columns', 'survey_type');
        $this->assertSame(0, $suggestions['titulados']);
        $this->assertSame(1, $suggestions['empleadores']);
        $this->assertSame([], $data['columns'][0]['field_candidates']);
        $this->assertDatabaseHas('imports', ['id' => $import['id'], 'configuration_revision' => 1]);
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_ragged_csv_missing_cells_and_distinct_cap(): void
    {
        $content = "T12\n";
        for ($i = 0; $i < 105; $i++) {
            $content .= "$i,value$i\n";
        }
        $content .= "106\n";
        $import = $this->upload($content);
        $data = $this->getJson($this->url($import).'&header_row=1')->assertOk()->json('data');
        $this->assertCount(2, $data['columns']);
        $this->assertNull($data['columns'][1]['header']);
        $this->assertSame(1, $data['columns'][1]['empty_count']);
        $this->assertSame(100, $data['columns'][0]['distinct_count']);
        $this->assertTrue($data['columns'][0]['distinct_count_is_lower_bound']);
        $this->assertSame(50, $data['header_rows_scanned']);
    }

    public function test_permission_bounds_and_sheet_ownership(): void
    {
        $first = $this->upload("T08\nSí\n");
        $second = $this->upload("T08\nNo\n");
        $this->getJson($this->url($first).'&header_row=3')->assertUnprocessable();
        $this->getJson(str_replace($first['sheets'][0]['id'], $second['sheets'][0]['id'], $this->url($first)))->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true, 'can_view_contacts' => false]), ['admin']);
        $this->getJson($this->url($first))->assertForbidden();
    }

    public function test_xlsx_types_blank_cells_and_formula_cache_are_profiled_without_execution(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['T12', 'anios_vida_profesional', 'Extra'], null, 'A1');
        $sheet->setCellValue('A2', 0);
        $sheet->setCellValue('B2', '=1+1');
        $sheet->setCellValue('C2', false);
        $sheet->setCellValue('A3', 2);
        $path = Storage::disk('local')->path('formatos.xlsx');
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();
        $dataset = Dataset::create(['name' => 'Tipos', 'created_by' => auth()->id()]);
        $import = $this->postJson('/api/v1/imports', ['dataset_id' => $dataset->id, 'file' => new UploadedFile($path, 'formatos.xlsx', null, null, true)])->assertCreated()->json('data');
        $data = $this->getJson($this->url($import).'&header_row=1')->assertOk()->json('data');
        $this->assertSame(['T12'], $data['columns'][1]['field_candidates']);
        $this->assertSame(2, $data['columns'][0]['types']['number']);
        $this->assertSame(1, $data['columns'][2]['types']['boolean']);
        $this->assertSame(1, $data['columns'][2]['empty_count']);
        $this->assertDatabaseCount('validation_runs', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Imports\WorkbookReader;
use App\Http\Controllers\ExportController;
use App\Models\Dataset;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SecurityAndWorkbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_authorization_and_token_revocation(): void
    {
        $this->getJson('/api/v1/datasets')->assertUnauthorized();
        $user = User::factory()->create(['email' => 'admin@example.test', 'password' => 'Test-password-123', 'is_admin' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnauthorized();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Test-password-123'])->assertOk()->json('data.access_token');
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/datasets')->assertUnauthorized();
        $ordinary = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$ordinary->createToken('test', ['admin'])->plainTextToken)->getJson('/api/v1/datasets')->assertForbidden();
    }

    public function test_xlsx_preserves_sheets_positions_booleans_and_does_not_evaluate_formulas(): void
    {
        Storage::fake('local');
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Titulados');
        $sheet->setCellValue('A1', 'Repetido');
        $sheet->setCellValue('B1', 'Repetido');
        $sheet->setCellValueExplicit('A2', false, DataType::TYPE_BOOL);
        $sheet->setCellValue('B2', '=1+1');
        $book->createSheet()->setTitle('Empleadores')->setCellValue('A1', 'Otro');
        $path = Storage::disk('local')->path('test.xlsx');
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();
        $sheets = app(WorkbookReader::class)->read($path, 'xlsx');
        $this->assertCount(2, $sheets);
        $this->assertSame('Repetido', $sheets[0]['rows'][0][1]['value']);
        $this->assertFalse($sheets[0]['rows'][1][0]['value']);
        $this->assertSame('formula', $sheets[0]['rows'][1][1]['type']);
        $this->assertSame('=1+1', $sheets[0]['rows'][1][1]['formula']);
        $this->assertNotSame(2, $sheets[0]['rows'][1][1]['value']);
    }

    public function test_database_rejects_response_without_an_origin(): void
    {
        $user = User::factory()->create();
        $d = Dataset::create(['name' => 'Test', 'created_by' => $user->id]);
        $this->expectException(QueryException::class);
        SurveyResponse::create(['dataset_id' => $d->id, 'source_kind' => 'direct', 'survey_type' => 'titulados', 'dictionary_version' => 'test', 'normalization_version' => 'test', 'included' => true]);
    }

    public function test_formula_injection_is_neutralized_in_csv(): void
    {
        $this->assertSame("'=SUM(A1)", ExportController::safeCsv('=SUM(A1)'));
        $this->assertSame(-10, ExportController::safeCsv(-10));
    }
}

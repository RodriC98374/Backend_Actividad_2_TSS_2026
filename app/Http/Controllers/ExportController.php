<?php

namespace App\Http\Controllers;

use App\Domain\Surveys\Analytics;
use App\Domain\Surveys\Normalizer;
use App\Models\DataExport;
use App\Models\Dataset;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportController extends Controller
{
    public function store(Request $r, Dataset $dataset, Analytics $analytics)
    {
        $v = $r->validate(['expected_data_version' => 'required|integer|min:0', 'format' => 'required|in:csv,xlsx', 'kind' => 'required|in:distribution,numeric-summary,crosstab', 'survey_type' => 'required|in:titulados,empleadores', 'field_id' => 'required|string', 'column_field_id' => 'required_if:kind,crosstab|string', 'filters' => 'sometimes|array']);
        [$export,$result] = DB::transaction(function () use ($r, $dataset, $analytics, $v) {
            $d = Dataset::sharedLock()->findOrFail($dataset->id);
            Api::revision($d->data_version, $v['expected_data_version']);
            $result = $analytics->calculate($d, $v, $v['kind']);
            $export = DataExport::create(['dataset_id' => $d->id, 'requested_by' => $r->user()->id, 'data_version' => $d->data_version, 'rules_version' => Normalizer::VERSION, 'specification' => $v, 'status' => 'processing', 'expires_at' => now()->addDays(7)]);

            return [$export, $result];
        });
        try {
            $rows = $result['items'] ?? $result['cells'] ?? [array_intersect_key($result, array_flip(['count', 'mean', 'min', 'max', 'sample_standard_deviation']))];
            $meta = ['dataset_id' => $dataset->id, 'data_version' => $result['data_version'], 'denominator' => $result['denominator'], 'filters' => json_encode($result['filters'], JSON_UNESCAPED_UNICODE), 'rules_version' => Normalizer::VERSION];
            $rows = array_map(fn ($row) => $row + $meta, $rows);
            $path = 'exports/'.$export->id.'.'.$v['format'];
            Storage::disk('local')->makeDirectory('exports');
            $absolute = Storage::disk('local')->path($path);
            if ($v['format'] === 'csv') {
                $handle = fopen($absolute, 'wb');
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, array_keys($rows[0]), ',', '"', '');
                foreach ($rows as $row) {
                    fputcsv($handle, array_map(self::safeCsv(...), array_values($row)), ',', '"', '');
                }fclose($handle);
            } else {
                $book = new Spreadsheet;
                $sheet = $book->getActiveSheet();
                $all = [array_keys($rows[0]), ...array_map('array_values', $rows)];
                foreach ($all as $ri => $row) {
                    foreach ($row as $ci => $value) {
                        $sheet->setCellValueExplicit([$ci + 1, $ri + 1], $value ?? '', is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }(new Xlsx($book))->save($absolute);
                $book->disconnectWorksheets();
            }
            $export->update(['status' => 'ready', 'storage_path' => $path]);
        } catch (\Throwable $e) {
            $export->update(['status' => 'failed', 'failure_code' => 'export_failed']);
            report($e);
            throw new ApiProblem('export_failed', 'No se pudo generar el archivo.', 500);
        }

        return Api::data($export->fresh(), 201);
    }

    public static function safeCsv(mixed $v): mixed
    {
        return is_string($v) && preg_match('/^[\s]*[=+\-@]/u', $v) ? "'".$v : $v;
    }

    private function access(Request $r, DataExport $export): void
    {
        if ($export->requested_by !== $r->user()->id) {
            throw new ApiProblem('forbidden', 'La exportación pertenece a otro administrador.', 403);
        }
    }

    public function show(Request $r, DataExport $export)
    {
        $this->access($r, $export);

        return Api::data($export);
    }

    public function download(Request $r, DataExport $export)
    {
        $this->access($r, $export);
        if ($export->status !== 'ready' || $export->expires_at->isPast()) {
            throw new ApiProblem('export_unavailable', 'Exportación no disponible o vencida.', 409);
        }

        return Storage::disk('local')->download($export->storage_path, 'encuestas-'.$export->id.'.'.$export->specification['format']);
    }
}

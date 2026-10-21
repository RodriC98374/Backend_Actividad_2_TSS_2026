<?php

namespace App\Domain\Imports;

use App\Support\ApiProblem;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use ZipArchive;
use Symfony\Component\Process\Process;

class WorkbookReader
{
    public function read(string $path, string $extension, ?string $delimiter = null, string $encoding = 'UTF-8'): array
    {
        return match ($extension) {
            'csv' => $this->csv($path, $delimiter, $encoding),
            'pdf' => $this->pdf($path),
            'xlsx' => $this->xlsx($path),
            default => throw new ApiProblem('unsupported_format', 'Selecciona XLSX, CSV o PDF de respuestas.'),
        };
    }

    private function pdf(string $path): array
    {
        $process = new Process([config('surveys.pdf_python'), '-I', base_path('scripts/read-survey-pdf.py'), $path,
            (string) config('surveys.max_pdf_pages'), (string) config('surveys.max_rows'), (string) config('surveys.max_cells')]);
        $process->setTimeout(45);
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            throw new ApiProblem('pdf_timeout', 'El PDF tardó demasiado en leerse. Usa el Excel original o un archivo más pequeño.');
        }
        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! isset($result['sheets'])) {
            throw new ApiProblem('unsupported_pdf', $result['error'] ?? 'El lector PDF no está disponible. Actualiza el contenedor del backend.');
        }

        return $result['sheets'];
    }

    private function csv(string $path, ?string $delimiter, string $encoding): array
    {
        $text = file_get_contents($path);
        if ($encoding === 'Windows-1252') {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        } elseif (! mb_check_encoding($text, 'UTF-8')) {
            throw new ApiProblem('encoding_ambiguous', 'CSV no es UTF-8. Confirme csv_encoding=Windows-1252 si corresponde.');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if ($delimiter === null) {
            $candidates = [];
            foreach ([',', ';', "\t"] as $d) {
                $h = fopen('php://temp', 'r+');
                fwrite($h, $text);
                rewind($h);
                $counts = [];
                for ($i = 0; $i < 5 && ($r = fgetcsv($h, null, $d, '"', '')) !== false; $i++) {
                    if ($r !== [null]) {
                        $counts[] = count($r);
                    }
                }
                fclose($h);
                if ($counts && min($counts) > 1 && count(array_unique($counts)) === 1) {
                    $candidates[] = $d;
                }
            }
            if (count($candidates) !== 1) {
                throw new ApiProblem('delimiter_ambiguous', 'Confirme csv_delimiter: coma, punto y coma o tabulación.');
            }
            $delimiter = $candidates[0];
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $text);
        rewind($h);
        $rows = [];
        $width = 0;
        try {
            while (($values = fgetcsv($h, null, $delimiter, '"', '')) !== false) {
                $this->limits(count($rows) + 1, count($values));
                $width = max($width, count($values));
                if ((count($rows) + 1) * $width > config('surveys.max_cells')) {
                    throw new ApiProblem('table_too_large', 'El archivo supera el límite de celdas.', 413);
                }
                $rows[] = array_map(fn ($v, $i) => ['column_index' => $i, 'type' => $v === null ? 'empty' : 'string', 'value' => $v, 'display_text' => $v], $values, array_keys($values));
            }
        } finally {
            fclose($h);
        }

        return [['name' => 'CSV', 'sheet_index' => 0, 'row_count' => count($rows), 'column_count' => $width, 'rows' => $rows]];
    }

    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ApiProblem('invalid_file', 'El libro XLSX no es válido.');
        }
        $size = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];
                if ($size > config('surveys.max_uncompressed_bytes')) {
                    throw new ApiProblem('workbook_too_large', 'El contenido descomprimido excede el límite.', 413);
                }
            }
            if ($zip->locateName('xl/vbaProject.bin') !== false) {
                throw new ApiProblem('unsupported_macros', 'No se admiten libros con macros.');
            }
        } finally {
            $zip->close();
        }
        $reader = new Xlsx;
        $reader->setReadDataOnly(false);
        $info = $reader->listWorksheetInfo($path);
        if (count($info) > config('surveys.max_sheets')) {
            throw new ApiProblem('too_many_sheets', 'El libro excede el límite de hojas.', 413);
        }
        $total = 0;
        $cells = 0;
        foreach ($info as $sheet) {
            $total += $sheet['totalRows'];
            $cells += $sheet['totalRows'] * $sheet['totalColumns'];
            $this->limits($total, $sheet['totalColumns']);
        }
        if ($cells > config('surveys.max_cells')) {
            throw new ApiProblem('table_too_large', 'El libro supera el límite de celdas.', 413);
        }
        $book = $reader->load($path);
        $result = [];
        try {
            foreach ($book->getWorksheetIterator() as $index => $sheet) {
                $last = $sheet->getHighestDataRow();
                $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
                $rows = [];
                for ($r = 1; $r <= $last; $r++) {
                    $cells = [];
                    for ($c = 1; $c <= $width; $c++) {
                        $cell = $sheet->getCell([$c, $r]);
                        $value = $cell->getValue();
                        $type = match ($cell->getDataType()) {
                            DataType::TYPE_FORMULA => 'formula',DataType::TYPE_BOOL => 'boolean',DataType::TYPE_NUMERIC => 'number',DataType::TYPE_ERROR => 'error',DataType::TYPE_NULL => 'empty',default => 'string'
                        };
                        $originalFormula = null;
                        if ($type === 'formula') {
                            $originalFormula = $value;
                            $value = $cell->getOldCalculatedValue();
                        }
                        if ($value instanceof RichText) {
                            $value = $value->getPlainText();
                        }
                        $display = $value === null ? null : (string) $value;
                        if ($type === 'number' && Date::isDateTime($cell)) {
                            $type = 'date';
                            $display = Date::excelToDateTimeObject($value)->format('Y-m-d\TH:i:s');
                        }
                        $cells[] = ['column_index' => $c - 1, 'type' => $type, 'value' => $value, 'display_text' => $display, 'formula' => $originalFormula];
                    }
                    $rows[] = $cells;
                }
                $result[] = ['name' => $sheet->getTitle(), 'sheet_index' => $index, 'row_count' => $last, 'column_count' => $width, 'rows' => $rows];
            }
        } finally {
            $book->disconnectWorksheets();
        }

        return $result;
    }

    private function limits(int $rows, int $columns): void
    {
        if ($rows > config('surveys.max_rows') || $columns > config('surveys.max_columns')) {
            throw new ApiProblem('table_too_large', 'La tabla excede los límites de filas/columnas configurados.', 413);
        }
    }
}

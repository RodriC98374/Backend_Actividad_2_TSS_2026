<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Catalog;
use App\Support\Api;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request, Catalog $catalog)
    {
        $v = $request->validate(['survey_type' => 'required|in:titulados,empleadores', 'dictionary_version' => 'sometimes|string']);

        return Api::data(['dictionary_version' => $catalog->version($v['dictionary_version'] ?? null), 'survey_type' => $v['survey_type'], 'fields' => array_map($catalog->publicField(...), $catalog->fields($v['survey_type'], $v['dictionary_version'] ?? null))]);
    }

    public function capabilities()
    {
        return Api::data(['formats' => ['xlsx', 'csv'], 'dictionary_version' => config('surveys.dictionary_version'), 'limits' => ['max_bytes' => config('surveys.max_file_kb') * 1024, 'max_rows' => config('surveys.max_rows'), 'max_columns' => config('surveys.max_columns'), 'max_sheets' => config('surveys.max_sheets'), 'max_cells' => config('surveys.max_cells'), 'max_uncompressed_bytes' => config('surveys.max_uncompressed_bytes')], 'processing_modes' => ['sync'], 'csv' => ['encodings' => ['UTF-8', 'Windows-1252'], 'delimiters' => [',', ';', "\t"]]]);
    }
}

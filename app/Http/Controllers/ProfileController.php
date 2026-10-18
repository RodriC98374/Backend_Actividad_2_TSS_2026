<?php

namespace App\Http\Controllers;

use App\Models\ImportProfile;
use App\Models\ImportSheet;
use App\Models\SurveyImport;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function index(Request $r)
    {
        return Api::page(ImportProfile::latest(), $r);
    }

    public function show(ImportProfile $profile)
    {
        return Api::data($profile);
    }

    public function store(Request $r)
    {
        Api::contacts($r);
        $v = $r->validate(['name' => 'required|string|max:255', 'sheet_id' => 'required|uuid|exists:import_sheets,id', 'previous_profile_id' => 'nullable|uuid|exists:import_profiles,id']);

        return Api::data(DB::transaction(function () use ($r, $v) {
            $sheet = ImportSheet::findOrFail($v['sheet_id']);
            SurveyImport::lockForUpdate()->findOrFail($sheet->import_id);
            $sheet->refresh();
            if (! $sheet->selected || ! $sheet->data_region) {
                throw new ApiProblem('sheet_not_configured', 'Configure y seleccione la hoja primero.');
            }
            $previous = empty($v['previous_profile_id']) ? null : ImportProfile::findOrFail($v['previous_profile_id']);
            if ($previous && ($previous->survey_type !== $sheet->survey_type || $previous->dictionary_version !== $sheet->dictionary_version)) {
                throw new ApiProblem('profile_incompatible', 'La versión anterior debe usar la misma encuesta y catálogo.');
            }

            return ImportProfile::create(['name' => $v['name'], 'survey_type' => $sheet->survey_type, 'dictionary_version' => $sheet->dictionary_version, 'profile_version' => ($previous?->profile_version ?? 0) + 1, 'previous_profile_id' => $previous?->id, 'created_by' => $r->user()->id, 'settings' => ['header_row' => $sheet->header_row, 'data_region' => $sheet->data_region, 'columns' => $sheet->mapping, 'multiselect_rules' => $sheet->multiselect_rules]]);
        }), 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\SurveyResponse;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatasetController extends Controller
{
    public function index(Request $request)
    {
        return Api::page(Dataset::orderByDesc('created_at'), $request);
    }

    public function show(Dataset $dataset)
    {
        return Api::data($dataset);
    }

    public function summary(Dataset $dataset)
    {
        return Api::data(DB::transaction(function () use ($dataset) {
            $d = Dataset::sharedLock()->findOrFail($dataset->id);
            $counts = SurveyResponse::where('dataset_id', $d->id)->selectRaw('survey_type, source_kind, included, count(*) as count')->groupBy('survey_type', 'source_kind', 'included')->get();

            return ['dataset_id' => $d->id, 'data_version' => $d->data_version, 'counts' => $counts];
        }));
    }

    public function store(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'reference_period' => 'nullable|string|max:255']);

        return Api::data(Dataset::create($v + ['created_by' => $request->user()->id])->fresh(), 201);
    }
}

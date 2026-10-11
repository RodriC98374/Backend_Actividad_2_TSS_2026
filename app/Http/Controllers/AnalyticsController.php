<?php

namespace App\Http\Controllers;

use App\Domain\Surveys\Analytics;
use App\Models\Dataset;
use App\Support\Api;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function show(Request $r, Dataset $dataset, string $kind, Analytics $analytics)
    {
        $v = $r->validate(['survey_type' => 'required|in:titulados,empleadores', 'field_id' => 'required|string', 'column_field_id' => ($kind === 'crosstab' ? 'required' : 'sometimes').'|string', 'filters' => 'sometimes|string|max:10000']);

        return Api::data($analytics->calculate($dataset, $v, $kind));
    }
}

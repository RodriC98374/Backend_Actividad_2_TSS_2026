<?php

namespace App\Http\Controllers;

use App\Domain\Surveys\Analytics;
use App\Models\Dataset;
use App\Support\Api;
use Illuminate\Http\Request;
use App\Domain\Catalog\Catalog;
use App\Models\ResponseAnswer;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function overview(Request $r, Dataset $dataset, Analytics $analytics, Catalog $catalog)
    {
        $v = $r->validate(['survey_type' => 'required|in:titulados,empleadores', 'filters' => 'sometimes|string|max:10000']);
        return Api::data(DB::transaction(function () use ($dataset, $analytics, $catalog, $v) {
            $d = Dataset::sharedLock()->findOrFail($dataset->id);
            $survey = $v['survey_type'];
            $filters = $analytics->filters($v['filters'] ?? [], $survey);
            $all = $analytics->query($d, $survey, []);
            // Filters must not hide available charts when the selected population is empty.
            $available = ResponseAnswer::whereIn('response_id', (clone $all)->select('id'))
                ->whereIn('state', ['respondida', 'invalida'])->distinct()->pluck('field_id')->all();
            $results = [];
            foreach ($catalog->fields($survey) as $raw) {
                $f = $catalog->publicField($raw);
                if (!in_array($f['id'], $available, true)) continue;
                $kind = in_array('distribution', $f['analytics'], true) ? 'distribution'
                    : (in_array('numeric_summary', $f['analytics'], true) ? 'numeric-summary' : null);
                if ($kind === null) continue;
                $results[] = ['field' => $f, 'kind' => $kind, 'result' => $analytics->calculate($d,
                    ['survey_type' => $survey, 'field_id' => $f['id'], 'filters' => $filters], $kind)];
            }
            return ['dataset_id' => $d->id, 'data_version' => $d->data_version, 'survey_type' => $survey,
                'filters' => $filters, 'total_responses' => $all->count(),
                'filtered_responses' => $analytics->query($d, $survey, $filters)->count(), 'results' => $results];
        }));
    }

    public function show(Request $r, Dataset $dataset, string $kind, Analytics $analytics)
    {
        $v = $r->validate(['survey_type' => 'required|in:titulados,empleadores', 'field_id' => 'required|string', 'column_field_id' => ($kind === 'crosstab' ? 'required' : 'sometimes').'|string', 'filters' => 'sometimes|string|max:10000']);

        return Api::data($analytics->calculate($dataset, $v, $kind));
    }
}

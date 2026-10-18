<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Catalog;
use App\Domain\Surveys\Analytics;
use App\Domain\Surveys\Normalizer;
use App\Domain\Surveys\ResponseWriter;
use App\Models\ChangeLog;
use App\Models\ContactDetail;
use App\Models\Dataset;
use App\Models\ResponseAnswer;
use App\Models\SurveyResponse;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResponseController extends Controller
{
    public function index(Request $r, Dataset $dataset, Analytics $analytics, ResponseWriter $writer)
    {
        $v = $r->validate(['survey_type' => 'required|in:titulados,empleadores', 'filters' => 'sometimes|string|max:10000', 'included' => 'sometimes|boolean']);
        $q = $analytics->query($dataset, $v['survey_type'], $analytics->filters($v['filters'] ?? [], $v['survey_type']), $r->boolean('included', true));

        return Api::page($q->orderBy('id'), $r, $writer->serialize(...));
    }

    public function show(SurveyResponse $response, ResponseWriter $writer)
    {
        return Api::data($writer->serialize($response));
    }

    public function contacts(Request $r, SurveyResponse $response)
    {
        Api::contacts($r);

        return Api::data(ContactDetail::where('response_id', $response->id)->first());
    }

    public function history(Request $r, SurveyResponse $response)
    {
        return Api::page(ChangeLog::where('response_id', $response->id)->orderByDesc('id'), $r);
    }

    public function update(Request $r, SurveyResponse $response, Catalog $catalog, Normalizer $normalizer, ResponseWriter $writer)
    {
        $v = $r->validate(['expected_response_revision' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:1000', 'included' => 'sometimes|boolean', 'answers' => 'sometimes|array|min:1|max:100', 'answers.*.field_id' => 'required|string|distinct', 'answers.*.value' => 'present', 'answers.*.state' => 'sometimes|in:respondida,sin_respuesta,no_aplica,indeterminada']);
        if (! array_key_exists('included', $v) && empty($v['answers'])) {
            throw new ApiProblem('empty_change', 'Indique una modificación.');
        }

        return Api::data(DB::transaction(function () use ($r, $response, $v, $catalog, $normalizer, $writer) {
            $dataset = Dataset::lockForUpdate()->findOrFail($response->dataset_id);
            $s = SurveyResponse::lockForUpdate()->findOrFail($response->id);
            Api::revision($s->response_revision, $v['expected_response_revision']);
            if ($dataset->status !== 'active') {
                throw new ApiProblem('dataset_closed', 'El estudio no está activo.', 409);
            }
            foreach ($v['answers'] ?? [] as $edit) {
                $f = $catalog->field($s->survey_type, $edit['field_id'], $s->dictionary_version);
                if ($f['type'] === 'contact') {
                    throw new ApiProblem('contact_edit_not_supported', 'La edición de contactos requiere un flujo específico aún pendiente.');
                }
                $next = $normalizer->field($f, $edit['value']);
                if (isset($edit['state']) && $edit['state'] !== 'respondida') {
                    if ($edit['value'] !== null) {
                        throw new ApiProblem('invalid_answer_state', 'Los estados sin respuesta requieren value null.');
                    }
                    $next['state'] = $edit['state'];
                }
                if ($next['state'] === 'invalida' || (($edit['state'] ?? null) === 'respondida' && $next['state'] !== 'respondida')) {
                    throw new ApiProblem('invalid_answer', 'La corrección no es válida.');
                }
                $old = ResponseAnswer::where('response_id', $s->id)->where('field_id', $f['id'])->first();
                ChangeLog::create(['response_id' => $s->id, 'actor_id' => $r->user()->id, 'field_id' => $f['id'], 'before' => $old?->only(['state', 'value']) ?? ['state' => 'no_disponible', 'value' => null], 'after' => array_intersect_key($next, array_flip(['state', 'value'])), 'reason' => $v['reason']]);
                ResponseAnswer::updateOrCreate(['response_id' => $s->id, 'field_id' => $f['id']], ['state' => $next['state'], 'value' => $next['value'], 'provenance' => ['rule_codes' => ['manual_correction'], 'original_provenance' => $old?->provenance]]);
            }
            if (array_key_exists('included', $v)) {
                ChangeLog::create(['response_id' => $s->id, 'actor_id' => $r->user()->id, 'before' => ['included' => $s->included], 'after' => ['included' => $v['included']], 'reason' => $v['reason']]);
                $s->included = $v['included'];
                $s->exclusion_reason = $v['included'] ? null : $v['reason'];
            }
            $s->response_revision++;
            $s->save();
            $dataset->increment('data_version');

            return $writer->serialize($s) + ['data_version' => $dataset->data_version];
        }));
    }
}

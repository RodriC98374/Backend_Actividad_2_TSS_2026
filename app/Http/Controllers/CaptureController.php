<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Catalog;
use App\Domain\Surveys\Normalizer;
use App\Domain\Surveys\ResponseWriter;
use App\Models\Dataset;
use App\Models\DirectSubmission;
use App\Models\FormInvitation;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CaptureController extends Controller
{
    public function invite(Request $r, Dataset $dataset)
    {
        $v = $r->validate(['expires_in_hours' => 'sometimes|integer|min:1|max:720']);
        $token = bin2hex(random_bytes(32));
        $i = DB::transaction(function () use ($r, $dataset, $v, $token) {
            $d = Dataset::lockForUpdate()->findOrFail($dataset->id);
            if ($d->status !== 'active') {
                throw new ApiProblem('dataset_closed', 'El estudio no está activo.', 409);
            }

            return FormInvitation::create(['dataset_id' => $d->id, 'created_by' => $r->user()->id, 'survey_type' => 'titulados', 'dictionary_version' => config('surveys.dictionary_version'), 'questionnaire_version' => config('surveys.questionnaire_version'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours($v['expires_in_hours'] ?? 48)]);
        });

        return Api::data(['invitation' => $i, 'token' => $token], 201);
    }

    public function revoke(FormInvitation $invitation)
    {
        DB::transaction(function () use ($invitation) {
            $i = FormInvitation::lockForUpdate()->findOrFail($invitation->id);
            $i->update(['revoked_at' => now()]);
        });

        return response()->noContent();
    }

    public function schema(Request $r, Catalog $catalog)
    {
        $i = $r->attributes->get('invitation');

        return Api::data(['survey_type' => $i->survey_type, 'dictionary_version' => $i->dictionary_version, 'questionnaire_version' => $i->questionnaire_version, 'used' => $i->used_at !== null, 'fields' => array_map($catalog->publicField(...), $catalog->fields($i->survey_type, $i->dictionary_version)), 'required_fields' => ['X01', 'X03', 'X05', 'X06', 'X07'], 'provisional' => true]);
    }

    public function submit(Request $r, Catalog $catalog, Normalizer $normalizer, ResponseWriter $writer)
    {
        $v = $r->validate(['consent' => 'required|accepted', 'answers' => 'required|array|min:1|max:100', 'answers.*' => 'nullable']);
        $hash = hash('sha256', Api::canonical($v));
        $invitation = $r->attributes->get('invitation');

        return Api::data(DB::transaction(function () use ($v, $hash, $invitation, $catalog, $normalizer, $writer) {
            $dataset = Dataset::lockForUpdate()->findOrFail($invitation->dataset_id);
            $i = FormInvitation::lockForUpdate()->findOrFail($invitation->id);
            if ($i->revoked_at || $i->expires_at->isPast()) {
                throw new ApiProblem('invalid_capture_access', 'Acceso vencido o revocado.', 401);
            }
            $prior = DirectSubmission::where('form_invitation_id', $i->id)->first();
            if ($prior) {
                if ($prior->request_hash !== $hash) {
                    throw new ApiProblem('invitation_already_used', 'Este acceso ya envió otra respuesta.', 409);
                }

                return $prior->publication_result;
            }
            if ($dataset->status !== 'active') {
                throw new ApiProblem('dataset_closed', 'El estudio no está activo.', 409);
            }
            $fields = $catalog->fields($i->survey_type, $i->dictionary_version);
            $ids = array_column($fields, 'id');
            foreach (array_keys($v['answers']) as $id) {
                if (! in_array($id, $ids, true)) {
                    throw new ApiProblem('unknown_field', 'Pregunta desconocida.', 422, ['answers' => [$id]]);
                }
            }
            $answers = [];
            $errors = [];
            foreach ($fields as $f) {
                $a = $normalizer->field($f, $v['answers'][$f['id']] ?? null);
                $answers[$f['id']] = $a;
                if ($a['state'] === 'invalida') {
                    $errors['answers.'.$f['id']] = ['Valor no admitido.'];
                }
            }
            foreach (['X01', 'X03', 'X05', 'X06', 'X07'] as $id) {
                if ($answers[$id]['state'] !== 'respondida') {
                    $errors['answers.'.$id] = ['Campo obligatorio.'];
                }
            }
            if (($answers['X02']['value'] ?? null) !== null && $answers['X02']['value'] < $answers['X01']['value']) {
                $errors['answers.X02'] = ['La titulación no puede preceder al egreso.'];
            }
            if (in_array($answers['X03']['value'], ['empleado', 'independiente', 'emprendimiento']) && $answers['X04']['state'] !== 'respondida') {
                $errors['answers.X04'] = ['Indique el sector laboral.'];
            }
            foreach (['T32', 'T33', 'T34'] as $id) {
                if ($answers[$id]['value'] !== null && $answers['T31']['value'] !== true) {
                    $errors['answers.'.$id] = ['Se requiere consentimiento de contacto en T31.'];
                }
            }
            if ($errors) {
                throw new ApiProblem('invalid_answers', 'Revise las respuestas.', 422, $errors);
            }
            $submission = DirectSubmission::create(['form_invitation_id' => $i->id, 'original_payload' => $v, 'request_hash' => $hash, 'publication_result' => [], 'received_at' => now()]);
            $response = $writer->create(['dataset_id' => $dataset->id, 'source_kind' => 'direct', 'direct_submission_id' => $submission->id, 'survey_type' => $i->survey_type, 'dictionary_version' => $i->dictionary_version, 'included' => true], array_values($normalizer->conditional($answers)));
            $dataset->increment('data_version');
            $i->update(['used_at' => now()]);
            $result = ['response_id' => $response->id, 'status' => 'published', 'data_version' => $dataset->data_version];
            $submission->update(['publication_result' => $result]);

            return $result;
        }), 201);
    }
}

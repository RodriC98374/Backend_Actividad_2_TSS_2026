<?php

namespace App\Domain\Surveys;

use App\Models\ContactDetail;
use App\Models\ResponseAnswer;
use App\Models\SurveyResponse;

class ResponseWriter
{
    public const CONTACT_FIELDS = ['T32' => 'email', 'T33' => 'phone', 'T34' => 'linkedin', 'E00' => 'email'];

    /** Caller owns the publication transaction and dataset lock. */
    public function create(array $attributes, array $answers): SurveyResponse
    {
        $response = SurveyResponse::create($attributes + ['normalization_version' => Normalizer::VERSION, 'response_revision' => 1]);
        $contacts = [];
        $records = [];
        foreach ($answers as $answer) {
            if (isset(self::CONTACT_FIELDS[$answer['field_id']])) {
                $contacts[self::CONTACT_FIELDS[$answer['field_id']]] = $answer['value'];
                $answer['value'] = null;
            }
            $records[] = ['response_id' => $response->id, 'field_id' => $answer['field_id'], 'state' => $answer['state'], 'value' => $answer['value'] === null ? null : json_encode($answer['value'], JSON_THROW_ON_ERROR), 'provenance' => json_encode($answer['provenance'], JSON_THROW_ON_ERROR)];
        }
        foreach (array_chunk($records, 100) as $chunk) {
            ResponseAnswer::insert($chunk);
        }
        if ($contacts) {
            ContactDetail::create(['response_id' => $response->id] + $contacts);
        }

        return $response;
    }

    public function serialize(SurveyResponse $response): array
    {
        $data = $response->toArray();
        $data['answers'] = ResponseAnswer::where('response_id', $response->id)->orderBy('field_id')->get(['field_id', 'state', 'value', 'provenance'])->toArray();

        return $data;
    }
}

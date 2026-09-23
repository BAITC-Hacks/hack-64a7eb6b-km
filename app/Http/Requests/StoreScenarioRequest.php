<?php

namespace App\Http\Requests;

class StoreScenarioRequest extends PreviewScenarioRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [...parent::rules(),
            'title' => ['required', 'string', 'max:160'],
            'request_key' => ['required', 'uuid'],
        ];
    }
}

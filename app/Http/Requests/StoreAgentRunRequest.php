<?php

namespace App\Http\Requests;

use App\Models\AgentRun;
use Illuminate\Foundation\Http\FormRequest;

class StoreAgentRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AgentRun::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['input' => ['required', 'string', 'max:6000'], 'request_key' => ['required', 'uuid']];
    }
}

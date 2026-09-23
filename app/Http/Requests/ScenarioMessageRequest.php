<?php

namespace App\Http\Requests;

use App\Models\AgentRun;
use App\Models\SimulationScenario;
use Illuminate\Foundation\Http\FormRequest;

class ScenarioMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scenario = $this->route('scenario');

        $user = $this->user();

        return $scenario instanceof SimulationScenario && $user !== null && $user->can('view', $scenario) && $user->can('create', AgentRun::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['input' => ['required', 'string', 'max:2000'], 'request_key' => ['required', 'uuid']];
    }
}

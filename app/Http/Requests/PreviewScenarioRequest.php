<?php

namespace App\Http\Requests;

use App\Models\SimulationDataset;
use App\Models\SimulationScenario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class PreviewScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SimulationScenario::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'selections' => ['required', 'array', 'max:5'],
            'source_scenario_id' => ['nullable', 'string', 'ulid'],
        ];
    }

    public function sourceScenario(): ?SimulationScenario
    {
        if (! $this->filled('source_scenario_id')) {
            return null;
        }
        $source = SimulationScenario::query()->findOrFail($this->string('source_scenario_id')->toString());
        Gate::authorize('view', $source);

        return $source;
    }

    public function dataset(): SimulationDataset
    {
        $source = $this->sourceScenario();

        return $source ? $source->dataset : SimulationDataset::current();
    }
}

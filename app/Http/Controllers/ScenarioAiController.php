<?php

namespace App\Http\Controllers;

use App\Actions\AgentRuns\CreateScenarioRun;
use App\Http\Requests\ScenarioMessageRequest;
use App\Models\AgentRun;
use App\Models\SimulationScenario;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScenarioAiController extends Controller
{
    public function analysis(Request $request, SimulationScenario $scenario, CreateScenarioRun $create): RedirectResponse
    {
        Gate::authorize('view', $scenario);
        Gate::authorize('create', AgentRun::class);
        $request->validate(['request_key' => ['required', 'uuid'], 'return_to' => ['nullable', 'in:map,scenario']]);
        /** @var User $user */
        $user = $request->user();
        $create->handle($user, $scenario, 'scenario_analysis', 'Объясни результат сценария, его сильные стороны, риски, компромиссы и проверенные улучшения.', $request->string('request_key')->toString());

        return $request->input('return_to') === 'map'
            ? to_route('map', ['scenario' => $scenario->id])
            : to_route('scenarios.show', $scenario);
    }

    public function message(ScenarioMessageRequest $request, SimulationScenario $scenario, CreateScenarioRun $create): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $create->handle($user, $scenario, 'scenario_chat', $request->string('input')->toString(), $request->string('request_key')->toString());

        return $request->input('return_to') === 'map'
            ? to_route('map', ['scenario' => $scenario->id])
            : to_route('scenarios.show', $scenario);
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Simulations\CalculateScenario;
use App\Actions\Simulations\CompareScenarios;
use App\Actions\Simulations\CreateScenario;
use App\Actions\Simulations\ValidateScenario;
use App\Http\Requests\PreviewScenarioRequest;
use App\Http\Requests\StoreScenarioRequest;
use App\Models\AgentRun;
use App\Models\SimulationDataset;
use App\Models\SimulationScenario;
use App\Models\ToolApproval;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SimulationScenarioController extends Controller
{
    public function dashboard(Request $request, CalculateScenario $calculate): Response
    {
        if (Gate::denies('viewAny', SimulationScenario::class)) {
            return Inertia::render('access-denied');
        }

        return $this->index($request, $calculate);
    }

    public function index(Request $request, CalculateScenario $calculate): Response
    {
        Gate::authorize('viewAny', SimulationScenario::class);
        $dataset = SimulationDataset::query()->where('version', 'astana-v1')->first();

        return Inertia::render('scenarios/index', [
            'dataset' => $dataset?->data,
            'baseline' => $dataset ? $calculate->handle($dataset->data, []) : null,
            'scenarios' => SimulationScenario::query()->where('user_id', $request->user()?->id)->orderByDesc('created_at')->orderByDesc('id')->paginate(12)->through(fn (SimulationScenario $scenario): array => $this->summary($scenario)),
            'can' => Inertia::always(fn (): array => ['create' => Gate::allows('create', SimulationScenario::class)]),
        ]);
    }

    public function create(Request $request, CalculateScenario $calculate): Response
    {
        Gate::authorize('create', SimulationScenario::class);
        $request->validate(['source' => ['nullable', 'ulid'], 'alternative' => ['nullable', 'integer', 'min:0', 'max:2']]);
        $source = $request->filled('source') ? SimulationScenario::query()->findOrFail($request->string('source')->toString()) : null;
        if ($source) {
            Gate::authorize('view', $source);
        }
        $dataset = $source ? $source->dataset : SimulationDataset::current();
        $selections = $source ? $source->selections : [];
        if ($request->filled('alternative')) {
            abort_unless($source && isset($source->alternatives[$request->integer('alternative')]), 404);
            $selections = $source->alternatives[$request->integer('alternative')]['selections'];
        }

        return Inertia::render('scenarios/create', [
            'dataset' => $dataset->data, 'baseline' => $calculate->handle($dataset->data, []),
            'initialSelections' => $selections, 'sourceId' => $source?->id,
        ]);
    }

    public function preview(PreviewScenarioRequest $request, ValidateScenario $validate, CalculateScenario $calculate): JsonResponse
    {
        $dataset = $request->dataset();
        $selections = $validate->handle($dataset->data, $request->validated('selections'));

        return response()->json(['result' => $calculate->handle($dataset->data, $selections)]);
    }

    public function store(StoreScenarioRequest $request, CreateScenario $create): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $scenario = $create->handle($user, $request->dataset(), $request->validated('selections'), $request->string('title')->toString(), $request->string('request_key')->toString(), $request->sourceScenario());

        return to_route('scenarios.show', $scenario);
    }

    public function show(SimulationScenario $scenario, CalculateScenario $calculate): Response
    {
        Gate::authorize('view', $scenario);

        return Inertia::render('scenarios/show', [
            'scenario' => $this->summary($scenario) + ['selections' => $scenario->selections, 'alternatives' => $scenario->alternatives],
            'dataset' => $scenario->dataset->data,
            'baseline' => $calculate->handle($scenario->dataset->data, [], $scenario->calculator_version),
            'runs' => fn () => $scenario->runs()->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()->reverse()->values()->map(fn (AgentRun $run): array => $run->only(['id', 'kind', 'input', 'output', 'output_data', 'status', 'error', 'driver', 'created_at'])),
            'approvals' => fn () => ToolApproval::query()->whereHas('run', fn ($query) => $query->where('simulation_scenario_id', $scenario->id))->with(['run:id,status', 'scenario:id,tool_approval_id'])->orderByDesc('id')->limit(20)->get()->map(fn (ToolApproval $approval): array => $approval->only(['id', 'tool', 'arguments', 'status']) + ['run_status' => $approval->run->status, 'scenario_id' => $approval->scenario?->id]),
            'can' => Inertia::always(fn (): array => [
                'create' => Gate::allows('create', SimulationScenario::class),
                'analyze' => Gate::allows('create', AgentRun::class),
                'cancel' => auth()->user()?->can('workspace.runs.cancel') === true,
                'approve' => Gate::allows('create', SimulationScenario::class) && auth()->user()?->can('workspace.approvals.resolve') === true,
            ]),
        ]);
    }

    public function compare(Request $request, CompareScenarios $compare): Response
    {
        $request->validate(['left' => ['required', 'ulid'], 'right' => ['required', 'ulid']]);
        $left = SimulationScenario::query()->findOrFail($request->string('left')->toString());
        $right = SimulationScenario::query()->findOrFail($request->string('right')->toString());
        /** @var User $user */
        $user = $request->user();
        $comparison = $compare->handle($user, $left, $right);

        return Inertia::render('scenarios/compare', [
            'left' => $this->summary($left) + ['selections' => $left->selections],
            'right' => $this->summary($right) + ['selections' => $right->selections],
            'dataset' => $left->dataset->data, 'comparison' => $comparison,
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(SimulationScenario $scenario): array
    {
        return $scenario->only(['id', 'title', 'result', 'simulation_dataset_id', 'calculator_version', 'source_scenario_id', 'created_at']);
    }
}

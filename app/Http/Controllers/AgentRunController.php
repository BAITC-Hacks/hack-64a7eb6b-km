<?php

namespace App\Http\Controllers;

use App\Actions\AgentRuns\CreateRun;
use App\Actions\AgentRuns\StopRun;
use App\Http\Requests\StoreAgentRunRequest;
use App\Models\AgentRun;
use App\Models\Note;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgentRunController extends Controller
{
    public function dashboard(Request $request): Response
    {
        if (Gate::denies('viewAny', AgentRun::class)) {
            return Inertia::render('access-denied');
        }

        return $this->index($request);
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AgentRun::class);

        return Inertia::render('runs/index', [
            'runs' => AgentRun::query()->where('user_id', $request->user()?->id)->latest()->paginate(15)
                ->through(fn (AgentRun $run): array => $run->only(['id', 'input', 'status', 'driver', 'created_at'])),
            'notes' => Note::query()->where('user_id', $request->user()?->id)->latest()->limit(10)->get(['id', 'title', 'body']),
            'runtime' => [
                'driver' => config('agents.driver'),
                'provider' => config('agents.provider'),
                'model' => config('agents.model'),
                'max_steps' => config('agents.max_steps'),
                'max_tool_calls' => config('agents.max_tool_calls'),
            ],
        ]);
    }

    public function store(StoreAgentRunRequest $request, CreateRun $create): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $run = $create->handle($user, $request->string('input')->toString(), $request->string('request_key')->toString());

        return to_route('runs.show', $run);
    }

    public function show(AgentRun $run): Response
    {
        Gate::authorize('view', $run);

        return Inertia::render('runs/show', [
            'run' => $run,
            'events' => $run->events()->orderBy('id')->get(['id', 'type', 'data', 'created_at']),
            'approvals' => $run->approvals()->oldest()->get(['id', 'tool', 'arguments', 'status']),
            'can' => Inertia::always(fn (): array => [
                'cancel' => Gate::allows('cancel', $run),
                'resolveApprovals' => Gate::allows('resolveApprovals', $run),
            ]),
        ]);
    }

    public function cancel(AgentRun $run, StopRun $stop): RedirectResponse
    {
        Gate::authorize('cancel', $run);
        $stop->handle($run->id);

        return back();
    }
}

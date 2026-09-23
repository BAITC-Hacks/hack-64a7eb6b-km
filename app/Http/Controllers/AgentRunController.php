<?php

namespace App\Http\Controllers;

use App\Actions\AgentRuns\CreateRun;
use App\Actions\AgentRuns\StopRun;
use App\Http\Requests\StoreAgentRunRequest;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AgentRunController extends Controller
{
    public function store(StoreAgentRunRequest $request, CreateRun $create): Response
    {
        /** @var User $user */
        $user = $request->user();
        $create->handle($user, $request->string('input')->toString(), $request->string('request_key')->toString());

        return response()->noContent();
    }

    public function cancel(AgentRun $run, StopRun $stop): RedirectResponse
    {
        Gate::authorize('cancel', $run);
        $stop->handle($run->id);

        return back();
    }
}

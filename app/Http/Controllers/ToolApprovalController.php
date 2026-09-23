<?php

namespace App\Http\Controllers;

use App\Actions\AgentRuns\ResolveApproval;
use App\Models\ToolApproval;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ToolApprovalController extends Controller
{
    public function update(Request $request, ToolApproval $approval, ResolveApproval $resolve): RedirectResponse
    {
        $request->validate(['decision' => ['required', 'in:approve,reject']]);
        /** @var User $user */
        $user = $request->user();
        $resolve->handle($user, $approval, $request->string('decision')->toString() === 'approve');

        return back();
    }
}

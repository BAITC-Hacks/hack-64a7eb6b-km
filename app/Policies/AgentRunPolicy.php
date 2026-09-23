<?php

namespace App\Policies;

use App\Enums\AccessPermission;
use App\Models\AgentRun;
use App\Models\User;

class AgentRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->can(AccessPermission::WorkspaceView);
    }

    public function view(User $user, AgentRun $run): bool
    {
        return $this->viewAny($user) && $user->id === $run->user_id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can(AccessPermission::WorkspaceRunsCreate);
    }

    public function cancel(User $user, AgentRun $run): bool
    {
        return $this->view($user, $run) && $user->can(AccessPermission::WorkspaceRunsCancel);
    }

    public function resolveApprovals(User $user, AgentRun $run): bool
    {
        return $this->view($user, $run) && $user->can(AccessPermission::WorkspaceApprovalsResolve);
    }
}

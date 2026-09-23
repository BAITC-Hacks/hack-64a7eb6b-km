<?php

namespace App\Policies;

use App\Enums\AccessPermission;
use App\Models\SimulationScenario;
use App\Models\User;

class SimulationScenarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->can(AccessPermission::WorkspaceView);
    }

    public function view(User $user, SimulationScenario $scenario): bool
    {
        return $this->viewAny($user) && $user->id === $scenario->user_id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can(AccessPermission::WorkspaceRunsCreate);
    }
}

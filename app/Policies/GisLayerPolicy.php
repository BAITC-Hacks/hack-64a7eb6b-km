<?php

namespace App\Policies;

use App\Models\GisLayer;
use App\Models\User;

class GisLayerPolicy
{
    public function view(?User $user, GisLayer $layer): bool
    {
        return $layer->user_id === null || ($user !== null && $user->id === $layer->user_id && $user->hasVerifiedEmail() && $user->can('workspace.view'));
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->can('workspace.view') && $user->can('workspace.runs.create');
    }

    public function update(User $user, GisLayer $layer): bool
    {
        return $layer->user_id === $user->id && $this->create($user);
    }
}

<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;

class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function view(User $user, Permission $permission): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Permission $permission): bool
    {
        return false;
    }

    public function delete(User $user, Permission $permission): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

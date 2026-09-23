<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->create($user) && ! $role->isSuperAdmin();
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->update($user, $role) && ! $role->isDefaultRole() && ! $role->users()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

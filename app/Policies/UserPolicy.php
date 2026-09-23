<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function view(User $user, User $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $record): bool
    {
        return $this->viewAny($user) && ! $record->isDemoAdministrator();
    }

    public function delete(User $user, User $record): bool
    {
        return $this->viewAny($user)
            && ! $user->is($record)
            && ! $record->isDemoAdministrator()
            && ! $record->isLastSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

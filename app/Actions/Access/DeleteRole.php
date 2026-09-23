<?php

namespace App\Actions\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteRole
{
    public function handle(User $actor, Role $record): bool
    {
        return DB::transaction(function () use ($actor, $record): bool {
            Role::lockSuperAdmin();
            $role = Role::query()->lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('delete', $role);

            return (bool) $role->delete();
        });
    }
}

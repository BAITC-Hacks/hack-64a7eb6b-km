<?php

namespace App\Actions\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    public function handle(User $actor, User $record, bool $ownProfile = false): bool
    {
        return DB::transaction(function () use ($actor, $record, $ownProfile): bool {
            Role::lockSuperAdmin();
            $user = User::query()->lockForUpdate()->findOrFail($record->id);

            if ($ownProfile) {
                abort_unless($actor->is($user), 403);
            } else {
                Gate::forUser($actor)->authorize('delete', $user);
            }

            if ($user->isDemoAdministrator() || $user->isLastSuperAdmin()) {
                throw ValidationException::withMessages(['password' => 'Нельзя удалить демо-аккаунт или последнего администратора.']);
            }

            return (bool) $user->delete();
        });
    }
}

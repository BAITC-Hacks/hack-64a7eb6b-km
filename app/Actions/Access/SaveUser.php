<?php

namespace App\Actions\Access;

use App\Enums\AccessPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SaveUser
{
    /** @param array<string, mixed> $data */
    public function handle(User $actor, ?User $record, array $data): User
    {
        return DB::transaction(function () use ($actor, $record, $data): User {
            $administrator = Role::lockSuperAdmin();
            $user = $record ? User::query()->lockForUpdate()->findOrFail($record->id) : new User;
            Gate::forUser($actor)->authorize($record ? 'update' : 'create', $record ? $user : User::class);

            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
                'password' => [$record ? 'nullable' : 'required', 'string', Password::defaults(), 'max:255'],
                'verified' => ['required', 'boolean'],
                'role_ids' => ['sometimes', 'array'],
                'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
                'permission_ids' => ['sometimes', 'array'],
                'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')->where('guard_name', 'web')->whereIn('name', array_column(AccessPermission::cases(), 'value'))],
            ])->validate();

            $retainsAdmin = ! array_key_exists('role_ids', $validated)
                || in_array($administrator->id, $validated['role_ids'], false);

            if ($user->exists && $user->isLastSuperAdmin() && (! $retainsAdmin || ! $validated['verified'])) {
                throw ValidationException::withMessages(['data.role_ids' => 'Нельзя лишить доступа последнего администратора. Сначала назначьте другого.']);
            }

            $user->fill(Arr::only($validated, ['name', 'email']));
            if (filled($validated['password'] ?? null)) {
                $user->password = $validated['password'];
            }
            $user->email_verified_at = $validated['verified'] ? ($user->email_verified_at ?? now()) : null;
            $user->save();

            if (array_key_exists('role_ids', $validated)) {
                $user->syncRoles(Role::query()->whereIn('id', $validated['role_ids'])->get());
            } elseif (! $record) {
                $user->assignRole(Role::AKIM);
            }
            if (array_key_exists('permission_ids', $validated)) {
                $user->syncPermissions(Permission::query()->whereIn('id', $validated['permission_ids'])->get());
            }

            return $user;
        });
    }
}

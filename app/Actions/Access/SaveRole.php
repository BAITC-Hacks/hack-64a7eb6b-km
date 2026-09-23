<?php

namespace App\Actions\Access;

use App\Enums\AccessPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveRole
{
    /** @param array<string, mixed> $data */
    public function handle(User $actor, ?Role $record, array $data): Role
    {
        return DB::transaction(function () use ($actor, $record, $data): Role {
            Role::lockSuperAdmin();
            $role = $record ? Role::query()->lockForUpdate()->findOrFail($record->id) : new Role;
            Gate::forUser($actor)->authorize($record ? 'update' : 'create', $record ? $role : Role::class);
            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:255', Rule::unique('roles')->where('guard_name', 'web')->ignore($role), Rule::when($role->isDefaultMember(), [Rule::in([Role::MEMBER])])],
                'permission_ids' => ['present', 'array'],
                'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')->where('guard_name', 'web')->whereIn('name', array_column(AccessPermission::cases(), 'value'))],
            ])->validate();

            $role->fill(['name' => $validated['name'], 'guard_name' => 'web'])->save();
            $role->syncPermissions(Permission::query()->whereIn('id', $validated['permission_ids'])->get());

            return $role;
        });
    }
}

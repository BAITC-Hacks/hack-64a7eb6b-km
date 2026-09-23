<?php

namespace App\Models;

use App\Enums\AccessPermission;
use Spatie\Permission\Models\Permission as BasePermission;

class Permission extends BasePermission
{
    public function label(): string
    {
        return AccessPermission::tryFrom($this->name)?->label() ?? $this->name;
    }

    public function group(): string
    {
        return AccessPermission::tryFrom($this->name)?->group() ?? 'Другие';
    }

    /** @return array<int|string, string> */
    public static function options(): array
    {
        return static::query()->where('guard_name', 'web')
            ->whereIn('name', array_column(AccessPermission::cases(), 'value'))->orderBy('id')->get()
            ->mapWithKeys(fn (self $permission): array => [$permission->id => $permission->label()])
            ->all();
    }
}

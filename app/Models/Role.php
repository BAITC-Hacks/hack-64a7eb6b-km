<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as BaseRole;

class Role extends BaseRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const SUPER_ADMIN = 'super_admin';

    public const OBSERVER = 'Наблюдатель';

    public const MEMBER = 'Участник';

    public function isDefaultMember(): bool
    {
        return $this->name === self::MEMBER && $this->guard_name === 'web';
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN && $this->guard_name === 'web';
    }

    public static function lockSuperAdmin(): self
    {
        return static::query()->where('name', self::SUPER_ADMIN)->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
    }
}

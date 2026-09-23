<?php

namespace App\Actions;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EnsureDemoAdmin
{
    public const EMAIL = 'admin@hackalem.test';

    public const PASSWORD = 'admin';

    public function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('demo.admin_enabled');
    }

    public function handle(): ?User
    {
        if (! $this->enabled()) {
            return null;
        }

        app(AccessControlSeeder::class)->run();

        return DB::transaction(function (): User {
            Role::lockSuperAdmin();
            $user = User::query()->firstOrCreate(
                ['email' => self::EMAIL],
                ['name' => 'Demo Admin', 'password' => self::PASSWORD],
            );

            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if (! Hash::check(self::PASSWORD, $user->password)) {
                $user->password = self::PASSWORD;
            }

            if ($user->isDirty()) {
                $user->save();
            }

            $user->assignRole(Role::SUPER_ADMIN);

            return $user;
        });
    }
}

<?php

namespace App\Actions;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EnsureDemoUsers
{
    /** @var list<array{role: string, email: string, password: string}> */
    private const ACCOUNTS = [
        ['role' => Role::SUPER_ADMIN, 'email' => EnsureDemoAdmin::EMAIL, 'password' => EnsureDemoAdmin::PASSWORD],
        ['role' => Role::ANALYST, 'email' => 'analyst@hackalem.test', 'password' => 'analyst'],
        ['role' => Role::AKIM, 'email' => 'akim@hackalem.test', 'password' => 'akim'],
    ];

    public function handle(): void
    {
        if (! app(EnsureDemoAdmin::class)->enabled()) {
            return;
        }

        DB::transaction(function (): void {
            app(EnsureDemoAdmin::class)->handle();

            foreach (self::ACCOUNTS as $account) {
                $user = User::query()->firstOrCreate(
                    ['email' => $account['email']],
                    ['name' => $account['role'], 'password' => $account['password']],
                );

                if ($user->wasRecentlyCreated) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                    $user->assignRole($account['role']);
                }
            }
        });
    }

    /** @return list<array{role: string, email: string, password: string}> */
    public function credentials(): array
    {
        if (! app(EnsureDemoAdmin::class)->enabled()) {
            return [];
        }

        $users = User::query()->with('roles')
            ->whereIn('email', array_column(self::ACCOUNTS, 'email'))
            ->get()->keyBy('email');

        return array_values(array_filter(self::ACCOUNTS, function (array $account) use ($users): bool {
            $user = $users->get($account['email']);

            return $user !== null
                && $user->hasVerifiedEmail()
                && $user->hasRole($account['role'], 'web')
                && $user->two_factor_secret === null
                && Hash::check($account['password'], $user->password);
        }));
    }
}

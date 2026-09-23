<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateApplicationUser extends Command
{
    protected $signature = 'app:user {email} {--name=Developer} {--admin : Grant access to the Filament panel}';

    protected $description = 'Create a verified local user with an interactively entered password';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This bootstrap command is available only in APP_ENV=local.');

            return self::FAILURE;
        }
        $data = [
            'email' => $this->argument('email'),
            'name' => $this->option('name'),
            'password' => $this->secret('Password (at least 12 characters)'),
        ];
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::min(12)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $user = new User($validator->validated());
        $user->email_verified_at = now();
        $user->save();
        if ($this->option('admin')) {
            $user->assignRole(Role::SUPER_ADMIN);
        } else {
            $user->assignRole(Role::AKIM);
        }
        $this->info('Verified user created. Sign in at /login'.($user->isSuperAdmin() ? ' or /admin/login.' : '.'));

        return self::SUCCESS;
    }
}

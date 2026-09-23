<?php

namespace Database\Seeders;

use App\Actions\EnsureDemoUsers;
use Illuminate\Database\Seeder;

class DemoUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(EnsureDemoUsers::class)->handle();
    }
}

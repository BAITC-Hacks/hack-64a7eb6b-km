<?php

namespace Database\Seeders;

use App\Actions\EnsureDemoAdmin;
use Illuminate\Database\Seeder;

class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        app(EnsureDemoAdmin::class)->handle();
    }
}

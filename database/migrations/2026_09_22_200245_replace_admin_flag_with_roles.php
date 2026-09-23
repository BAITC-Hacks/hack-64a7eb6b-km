<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(AccessControlSeeder::class)->run();

        User::query()->where('is_admin', true)->eachById(function (User $user): void {
            $user->assignRole(Role::SUPER_ADMIN);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false);
        });

        User::role(Role::SUPER_ADMIN)->update(['is_admin' => true]);
    }
};

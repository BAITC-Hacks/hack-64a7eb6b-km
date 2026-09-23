<?php

use App\Actions\EnsureDemoUsers;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(EnsureDemoUsers::class)->handle();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Demo users may own application data. Remove them explicitly instead of rolling back.');
    }
};

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ApplicationDoctor extends Command
{
    protected $signature = 'app:doctor';

    protected $description = 'Check local application prerequisites without calling an AI provider';

    public function handle(): int
    {
        $checks = [
            'PostgreSQL driver' => extension_loaded('pdo_pgsql') && config('database.default') === 'pgsql',
            'Application key' => filled(config('app.key')),
            'Supported runtime' => in_array(config('agents.driver'), ['demo', 'laravel'], true),
            'Database queue' => config('queue.default') === 'database',
            'Queue retry_after > worker timeout' => config('queue.connections.database.retry_after') > 180,
        ];
        try {
            DB::select('SELECT 1');
            $checks['Database and migrations'] = Schema::hasTable('agent_runs') && Schema::hasTable('notes') && Schema::hasTable('jobs');
        } catch (Throwable) {
            $checks['Database and migrations'] = false;
        }
        $checks['AI key (not needed in demo)'] = config('agents.driver') === 'demo'
            || filled(config('ai.providers.'.config('agents.provider').'.key'));

        foreach ($checks as $name => $ok) {
            $this->line(($ok ? '<info>OK</info>  ' : '<error>FAIL</error> ').$name);
        }
        $this->line('PHP: '.PHP_VERSION.'. Runtime: '.config('agents.driver').'. No AI request was made.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}

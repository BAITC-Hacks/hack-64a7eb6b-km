<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        $this->withoutVite();
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'hackalem_testing') {
            throw new RuntimeException('Tests require the dedicated hackalem_testing database.');
        }
        Http::preventStrayRequests();

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

<?php

namespace App\Ai\Runtime;

class RuntimeConfiguration
{
    /** @return array{configured_driver: string, driver: string, reason: string|null} */
    public function resolve(): array
    {
        $configured = config('agents.driver');
        if (! in_array($configured, ['auto', 'demo', 'laravel'], true)) {
            throw new \LogicException('Unknown runtime driver.');
        }
        if (! is_array(config('ai.providers.'.config('agents.provider')))) {
            throw new \LogicException('Unknown AI provider.');
        }
        $demo = $configured === 'demo' || ($configured === 'auto' && ! $this->hasKey(config('agents.provider')));

        return [
            'configured_driver' => $configured,
            'driver' => $demo ? 'demo' : 'laravel',
            'reason' => $demo ? ($configured === 'demo' ? 'forced_demo' : 'missing_key') : null,
        ];
    }

    public function hasKey(string $provider): bool
    {
        $key = config('ai.providers.'.$provider.'.key');

        return is_string($key) && trim($key) !== '';
    }
}

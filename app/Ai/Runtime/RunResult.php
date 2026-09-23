<?php

namespace App\Ai\Runtime;

final readonly class RunResult
{
    /** @param array<string, int> $usage */
    public function __construct(public string $text, public array $usage = []) {}
}

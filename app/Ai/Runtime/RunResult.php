<?php

namespace App\Ai\Runtime;

final readonly class RunResult
{
    /**
     * @param  array<string, int>  $usage
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(public string $text, public array $usage = [], public ?array $data = null) {}
}

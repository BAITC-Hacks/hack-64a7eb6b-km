<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}

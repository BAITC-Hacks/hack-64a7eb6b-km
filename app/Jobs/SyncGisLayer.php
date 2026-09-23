<?php

namespace App\Jobs;

use App\Actions\Gis\DiscoverGisLayers;
use App\Actions\Gis\ImportGisLayer;
use App\Models\GisImport;
use App\Models\GisLayerVersion;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SyncGisLayer implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 170;

    public int $tries = 4;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return (string) $this->versionId;
    }

    public function __construct(public int $versionId)
    {
        $this->onQueue('gis');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('gis-version:'.$this->versionId))->dontRelease()->expireAfter(180)];
    }

    public function handle(ImportGisLayer $importer): void
    {
        $version = GisLayerVersion::query()->findOrFail($this->versionId);
        if ($version->status !== 'building') {
            return;
        }
        try {
            $done = $importer->step($version);
        } catch (Throwable $error) {
            if ($error instanceof ConnectionException || in_array($error->getCode(), [429, 500, 502, 503, 504])) {
                throw $error;
            }
            $this->failed($error);

            return;
        }
        if (! $done) {
            self::dispatch($this->versionId);
        } else {
            $this->finishImport($version);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $version = GisLayerVersion::query()->find($this->versionId);
        if (! $version || in_array($version->status, ['published', 'seeded'], true)) {
            return;
        }
        $message = $exception ? app(DiscoverGisLayers::class)->safeError($exception) : 'Задание импорта остановлено.';
        $version->update(['status' => 'failed', 'error' => $message]);
        $version->layer->update(['status' => 'error', 'error' => $message]);
        $this->finishImport($version);
    }

    private function finishImport(GisLayerVersion $version): void
    {
        if (! $version->gis_import_id) {
            return;
        }
        $versions = GisLayerVersion::query()->where('gis_import_id', $version->gis_import_id);
        if (! (clone $versions)->where('status', 'building')->exists()) {
            $run = GisImport::query()->findOrFail($version->gis_import_id);
            $run->update(['status' => (clone $versions)->where('status', 'failed')->exists() || $run->errors ? 'partial' : 'completed']);
        }
    }
}

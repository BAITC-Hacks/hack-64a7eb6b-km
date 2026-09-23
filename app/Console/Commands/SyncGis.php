<?php

namespace App\Console\Commands;

use App\Actions\Gis\DiscoverGisLayers;
use App\Jobs\SyncGisLayer;
use App\Models\GisImport;
use App\Models\GisLayerVersion;
use Illuminate\Console\Command;

class SyncGis extends Command
{
    protected $signature = 'gis:sync {--resume : Resume incomplete versions} {--vectors : Queue vector and table layers only}';

    protected $description = 'Discover and archive the public Astana GIS maps';

    public function handle(DiscoverGisLayers $discovery): int
    {
        $run = $this->option('resume') ? GisImport::query()->whereIn('status', ['running', 'partial'])->latest()->first() : null;
        $run ??= $discovery->handle();
        $run->update(['status' => 'running']);
        $discovery->ensureManifest($run);
        $query = GisLayerVersion::query()->where('gis_import_id', $run->id)->whereIn('status', $this->option('resume') ? ['building', 'failed'] : ['building']);
        if ($this->option('vectors')) {
            $query->whereHas('layer', fn ($q) => $q->whereIn('kind', ['vector', 'table']));
        }
        $versions = $query->with('layer')->get()->sortBy(fn (GisLayerVersion $version): int => $version->layer->source_url === config('gis.district_source') ? 0 : (in_array($version->layer->kind, ['raster', 'vector_tile']) ? 2 : 1));
        foreach ($versions as $version) {
            $version->update(['status' => 'building', 'error' => null]);
            SyncGisLayer::dispatch($version->id);
        }
        $this->info('Import '.$run->id.': queued '.$versions->count().' layers. Queue: gis.');

        return self::SUCCESS;
    }
}

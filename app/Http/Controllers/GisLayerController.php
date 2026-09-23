<?php

namespace App\Http\Controllers;

use App\Actions\Gis\GisArchive;
use App\Actions\Gis\GisFeatures;
use App\Actions\Gis\GisVectorTile;
use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class GisLayerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['at' => ['nullable', 'date']]);
        $layers = GisLayer::query()->where(function ($query) use ($request): void {
            $query->whereNull('user_id');
            if ($request->user()?->hasVerifiedEmail() && $request->user()->can('workspace.view')) {
                $query->orWhere('user_id', $request->user()->id);
            }
        })->orderBy('title')->get();
        $versions = GisLayerVersion::query()->whereIn('gis_layer_id', $layers->modelKeys())->whereIn('status', ['published', 'seeded'])
            ->when($request->filled('at'), fn ($q) => $q->where('published_at', '<=', $request->date('at')))
            ->selectRaw('DISTINCT ON (gis_layer_id) id, gis_layer_id, metadata, status, expected_count, feature_count, published_at, observed_at, coverage')->orderBy('gis_layer_id')->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")->orderByDesc('id')->get()->keyBy('gis_layer_id');

        $progress = GisLayerVersion::query()->whereIn('gis_layer_id', $layers->modelKeys())->selectRaw('DISTINCT ON (gis_layer_id) id, gis_layer_id, status, feature_count, expected_count, coverage, cursor, error')->orderBy('gis_layer_id')->orderByDesc('id')->get()->keyBy('gis_layer_id');
        $fileStats = DB::table('gis_version_assets as va')->join('gis_assets as a', 'a.hash', '=', 'va.asset_hash')->whereIn('va.version_id', $progress->pluck('id'))->groupBy('va.version_id')->selectRaw("va.version_id, count(*) AS files, sum(a.bytes) AS bytes, count(*) FILTER (WHERE va.name LIKE 'tiles/%') AS tiles")->get()->keyBy('version_id');
        foreach ($progress as $item) {
            $item->setAttribute('archive', $fileStats->get($item->id));
        }

        return response()->json(['layers' => $layers->map(function (GisLayer $layer) use ($versions, $progress): array {
            $version = $versions->get($layer->id);
            $metadata = $version->metadata ?? $layer->metadata;

            return ['id' => $layer->id, 'title' => $layer->title, 'kind' => $layer->kind, 'owned' => $layer->user_id !== null, 'can_edit' => Gate::allows('update', $layer), 'status' => $layer->status, 'progress' => $progress->get($layer->id), 'error' => $progress->get($layer->id)->error ?? ($progress->has($layer->id) ? null : $layer->error), 'source_url' => $layer->source_url, 'occurrences' => $metadata['occurrences'] ?? $layer->occurrences, 'group_path' => $this->groupPath($metadata), 'version_id' => $version?->id, 'published_at' => $version?->published_at, 'observed_at' => $version?->observed_at, 'count' => $version?->feature_count, 'complete' => $version?->status === 'published', 'expected_count' => $version?->expected_count, 'coverage' => $version?->coverage, 'fields' => $metadata['layer']['fields'] ?? [], 'drawing' => $metadata['layer']['drawingInfo'] ?? [], 'legend' => ['layers' => array_values(array_filter($metadata['legend']['layers'] ?? [], fn (array $entry): bool => $entry['layerId'] === ($metadata['layer']['id'] ?? null)))], 'min_scale' => $metadata['layer']['minScale'] ?? 0, 'max_scale' => $metadata['layer']['maxScale'] ?? 0, 'attribution' => $metadata['service']['copyrightText'] ?? '', 'description' => $metadata['layer']['description'] ?? '', 'is_district_boundary' => $layer->user_id === null && $layer->source_url === config('gis.district_source'), 'default_visible' => $layer->source_url === config('gis.district_source') || str_contains($layer->source_url ?? '', '/basemap_ast_')];
        }), 'can_create' => Gate::allows('create', GisLayer::class), 'can_sync' => $request->user()?->canAccessAdmin() === true]);
    }

    /** @param array<string, mixed> $metadata
     * @return list<string>
     */
    private function groupPath(array $metadata): array
    {
        $groups = [];
        $parent = $metadata['layer']['parentLayer']['id'] ?? -1;
        $layers = [];
        foreach ($metadata['service']['layers'] ?? [] as $definition) {
            $layers[$definition['id']] = $definition;
        }
        $visited = [];
        while ($parent >= 0 && isset($layers[$parent]) && ! isset($visited[$parent])) {
            $visited[$parent] = true;
            array_unshift($groups, $layers[$parent]['name']);
            $parent = $layers[$parent]['parentLayerId'] ?? -1;
        }

        return $groups;
    }

    public function metadata(Request $request, GisLayer $layer): JsonResponse
    {
        $request->validate(['version' => ['required', 'integer']]);
        $snapshot = $this->snapshot($layer, $request->integer('version'));

        return response()->json(['source_url' => $layer->source_url, 'observed_at' => $snapshot->observed_at, 'coverage' => $snapshot->coverage, 'metadata' => $snapshot->metadata])->header('Content-Disposition', 'attachment; filename="gis-metadata.json"')->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, GisFeatures $features): JsonResponse
    {
        Gate::authorize('create', GisLayer::class);
        $data = $request->validate(['title' => ['required', 'string', 'max:200']]);
        $layer = DB::transaction(function () use ($request, $data, $features): GisLayer {
            $layer = GisLayer::query()->create(['user_id' => $request->user()->id, 'title' => $data['title'], 'kind' => 'custom', 'metadata' => [], 'occurrences' => []]);
            $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'author_id' => $request->user()->id, 'metadata' => [], 'observed_at' => now()]);
            $features->publish($version);

            return $layer->refresh();
        });

        return response()->json(['id' => $layer->id, 'version_id' => $layer->active_version_id], 201);
    }

    public function versions(GisLayer $layer): JsonResponse
    {
        Gate::authorize('view', $layer);

        return response()->json($layer->versions()->whereIn('status', ['published', 'seeded'])->orderByDesc('id')->paginate(100, ['id', 'status', 'published_at', 'observed_at', 'feature_count', 'expected_count', 'author_id', 'cursor']));
    }

    public function tile(Request $request, GisLayer $layer, int $version, int $z, int $x, int $y, string $format, GisArchive $archive, GisVectorTile $vectorTile): Response
    {
        $snapshot = $this->snapshot($layer, $version);
        abort_unless($z >= 0 && $z <= 22 && $x >= 0 && $y >= 0 && $x < 2 ** $z && $y < 2 ** $z, 404);
        $headers = ['Cache-Control' => $layer->user_id ? 'private, no-store' : 'public, max-age=31536000, immutable'];
        if (in_array($layer->kind, ['vector', 'custom'])) {
            abort_unless($format === 'pbf', 404);
            $disk = Storage::disk(config('gis.disk'));
            $path = "gis/derived/{$snapshot->id}/features/$z/$x/$y.pbf";
            if ($disk->exists($path)) {
                return response($disk->get($path), 200, $headers + ['Content-Type' => 'application/x-protobuf']);
            }
            $tile = DB::selectOne("WITH bounds AS (SELECT ST_TileEnvelope(?, ?, ?) AS b), features AS (SELECT f.id AS content_id, f.source_id, f.properties, ST_AsMVTGeom(ST_Transform(f.geometry,3857), bounds.b,4096,64,true) AS geom FROM gis_feature_contents f JOIN gis_version_features vf ON vf.feature_id=f.id CROSS JOIN bounds WHERE vf.version_id=? AND f.geometry && ST_Transform(ST_Expand(bounds.b, (ST_XMax(bounds.b)-ST_XMin(bounds.b))/64),4326)) SELECT encode(ST_AsMVT(features, 'features',4096,'geom','content_id'),'base64') AS tile FROM features", [$z, $x, $y, $snapshot->id]);
            $body = base64_decode($tile->tile ?? '');
            $disk->put($path, $body);

            return response($body, 200, $headers + ['Content-Type' => 'application/x-protobuf']);
        }
        abort_unless(($layer->kind === 'raster' && $format === 'png') || ($layer->kind === 'vector_tile' && $format === 'pbf'), 404);
        if ($layer->kind === 'vector_tile') {
            return response($vectorTile->render($snapshot, $z, $x, $y), 200, $headers + ['Content-Type' => 'application/x-protobuf']);
        }
        $name = "tiles/$z/$x/$y.$format";
        if (! $archive->exists($snapshot, $name)) {
            return response('', 204, $headers);
        }

        return response($archive->read($snapshot, $name), 200, $headers + ['Content-Type' => $format === 'png' ? 'image/png' : 'application/x-protobuf']);
    }

    public function differenceTile(GisLayer $layer, int $left, int $right, int $z, int $x, int $y): Response
    {
        $before = $this->snapshot($layer, $left);
        $after = $this->snapshot($layer, $right);
        abort_unless($before->status === 'published' && $after->status === 'published', 422, 'Неполный снимок нельзя использовать для определения удалений.');
        abort_unless($z >= 0 && $z <= 22 && $x >= 0 && $y >= 0 && $x < 2 ** $z && $y < 2 ** $z, 404);
        $tile = DB::selectOne("WITH l AS (SELECT * FROM gis_version_features WHERE version_id=?), r AS (SELECT * FROM gis_version_features WHERE version_id=?), changes AS (SELECT COALESCE(r.feature_id,l.feature_id) AS feature_id, CASE WHEN l.feature_id IS NULL THEN 'added' WHEN r.feature_id IS NULL THEN 'removed' ELSE 'changed' END AS change, CASE WHEN r.feature_id IS NULL THEN ? ELSE ? END AS display_version FROM l FULL OUTER JOIN r ON l.source_id=r.source_id WHERE l.feature_id IS DISTINCT FROM r.feature_id), bounds AS (SELECT ST_TileEnvelope(?,?,?) AS b), features AS (SELECT f.id AS content_id, f.source_id, changes.change, changes.display_version, ST_AsMVTGeom(ST_Transform(ST_MakeValid(f.geometry),3857),bounds.b,4096,64,true) AS geom FROM changes JOIN gis_feature_contents f ON f.id=changes.feature_id CROSS JOIN bounds WHERE f.geometry && ST_Transform(ST_Expand(bounds.b,(ST_XMax(bounds.b)-ST_XMin(bounds.b))/64),4326)) SELECT encode(ST_AsMVT(features,'features',4096,'geom','content_id'),'base64') AS tile FROM features", [$before->id, $after->id, $before->id, $after->id, $z, $x, $y]);

        return response(base64_decode($tile->tile ?? ''), 200, ['Content-Type' => 'application/x-protobuf', 'Cache-Control' => $layer->user_id ? 'private, no-store' : 'public, max-age=31536000, immutable']);
    }

    public function style(GisLayer $layer, int $version, GisArchive $archive): JsonResponse
    {
        $snapshot = $this->snapshot($layer, $version);
        abort_unless($layer->kind === 'vector_tile', 404);
        $style = $archive->readJson($snapshot, 'style-original.json.gz');
        foreach ($style['sources'] ?? [] as $id => $source) {
            $style['sources'][$id] = ['type' => 'vector', 'tiles' => [str_replace(['GIS_TILE_Z', 'GIS_TILE_X', 'GIS_TILE_Y'], ['{z}', '{x}', '{y}'], route('gis.tiles', ['layer' => $layer->id, 'version' => $version, 'z' => 'GIS_TILE_Z', 'x' => 'GIS_TILE_X', 'y' => 'GIS_TILE_Y', 'format' => 'pbf']))], 'minzoom' => 0, 'maxzoom' => $snapshot->coverage['max_zoom']];
        }
        if (isset($style['sprite'])) {
            $style['sprite'] = route('gis.resource', ['layer' => $layer->id, 'version' => $version, 'name' => 'sprite']);
        }
        if (isset($style['glyphs'])) {
            $style['glyphs'] = str_replace(['GIS_FONTSTACK', 'GIS_RANGE'], ['{fontstack}', '{range}'], route('gis.resource', ['layer' => $layer->id, 'version' => $version, 'name' => 'fonts/GIS_FONTSTACK/GIS_RANGE.pbf']));
        }
        foreach ($style['layers'] ?? [] as $index => $definition) {
            foreach (['layout', 'paint', 'metadata'] as $property) {
                if (isset($definition[$property])) {
                    $style['layers'][$index][$property] = (object) $definition[$property];
                }
            }
        }

        return response()->json($style);
    }

    public function resource(GisLayer $layer, int $version, string $name, GisVectorTile $vectorTile): Response
    {
        $snapshot = $this->snapshot($layer, $version);
        $asset = DB::table('gis_version_assets as va')->join('gis_assets as a', 'a.hash', '=', 'va.asset_hash')->where('va.version_id', $snapshot->id)->where('va.name_hash', hash('sha256', $name))->first(['a.path', 'a.mime']);
        abort_unless($asset !== null, 404);

        $body = Storage::disk(config('gis.disk'))->get($asset->path);
        if (str_ends_with($name, '.pbf')) {
            $body = $vectorTile->inflate($body);
        }

        return response($body, 200, ['Content-Type' => $asset->mime, 'Content-Disposition' => str_starts_with($name, 'attachments/') || str_starts_with($name, 'related/') || str_starts_with($name, 'raw/') ? 'attachment' : 'inline', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $layer->user_id ? 'private, no-store' : 'public, max-age=31536000, immutable']);
    }

    public function sync(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        Artisan::queue('gis:sync', ['--resume' => true])->onQueue('gis');

        return response()->json(['queued' => true], 202);
    }

    public function snapshot(GisLayer $layer, int $version): GisLayerVersion
    {
        Gate::authorize('view', $layer);

        return $layer->versions()->whereKey($version)->whereIn('status', ['published', 'seeded'])->firstOrFail();
    }
}

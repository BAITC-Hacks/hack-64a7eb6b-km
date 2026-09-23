<?php

namespace App\Http\Controllers;

use App\Actions\Gis\EditGisFeature;
use App\Actions\Gis\GisFeatures;
use App\Http\Requests\StoreGisFeatureRequest;
use App\Models\GisLayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GisFeatureController extends Controller
{
    public function __construct(private GisFeatures $features) {}

    public function index(Request $request, GisLayer $layer): JsonResponse
    {
        Gate::authorize('view', $layer);
        $request->validate(['version' => ['required', 'integer'], 'after' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:1000'], 'bbox' => ['nullable', 'array', 'size:4'], 'bbox.*' => ['numeric', 'between:-180,180']]);
        $version = $layer->versions()->whereKey($request->integer('version'))->whereIn('status', ['published', 'seeded'])->firstOrFail();
        $query = $this->features->query($version)->where('f.id', '>', $request->integer('after'))->orderBy('f.id');
        if ($request->filled('bbox')) {
            $box = $request->input('bbox');
            abort_unless($box[0] < $box[2] && $box[1] < $box[3], 422);
            $query->whereRaw('f.geometry && ST_MakeEnvelope(?, ?, ?, ?,4326)', $box);
        }
        $limit = $request->integer('limit', 500);
        $rows = $query->limit($limit + 1)->get(['f.id', 'f.source_id', 'f.properties', DB::raw('ST_AsGeoJSON(f.geometry) AS geojson')]);
        $more = $rows->count() > $limit;
        $page = $rows->take($limit);

        return response()->json(['type' => 'FeatureCollection', 'features' => $page->map(fn (\stdClass $row): array => $this->features->feature($row))->values(), 'next' => $more ? $page->last()->id : null, 'version_id' => $version->id])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, GisLayer $layer): StreamedResponse
    {
        Gate::authorize('view', $layer);
        $request->validate(['version' => ['required', 'integer']]);
        $version = $layer->versions()->whereKey($request->integer('version'))->whereIn('status', ['published', 'seeded'])->firstOrFail();

        return response()->streamDownload(function () use ($version): void {
            echo '{"type":"FeatureCollection","features":[';
            $first = true;
            foreach ($this->features->query($version)->select(['f.id', 'f.source_id', 'f.properties', DB::raw('ST_AsGeoJSON(f.geometry) AS geojson')])->lazyById(500, 'f.id', 'id') as $row) {
                echo ($first ? '' : ',').json_encode($this->features->feature($row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $first = false;
            }
            echo ']}';
        }, 'gis-'.$layer->id.'-'.$version->id.'.geojson', ['Content-Type' => 'application/geo+json', 'Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, GisLayer $layer, string $feature): JsonResponse
    {
        Gate::authorize('view', $layer);
        $request->validate(['version' => ['required', 'integer']]);
        $version = $layer->versions()->whereKey($request->integer('version'))->whereIn('status', ['published', 'seeded'])->firstOrFail();
        $row = $this->features->query($version)->where('vf.source_id', $feature)->first(['f.id', 'f.source_id', 'f.properties', 'f.raw', DB::raw('ST_AsGeoJSON(f.geometry) AS geojson')]);
        abort_unless($row !== null, 404);
        $assets = DB::table('gis_version_assets')->where('version_id', $version->id)->where('feature_source_id', $feature)->get(['id', 'name']);

        return response()->json(['feature' => $this->features->feature($row), 'raw' => json_decode($row->raw ?? 'null', true), 'assets' => $assets, 'version_id' => $version->id, 'observed_at' => $version->observed_at, 'source_url' => $layer->source_url]);
    }

    public function history(GisLayer $layer, string $feature): JsonResponse
    {
        Gate::authorize('view', $layer);
        $rows = DB::table('gis_layer_versions as v')->leftJoin('gis_version_features as vf', function ($join) use ($feature): void {
            $join->on('vf.version_id', '=', 'v.id')->where('vf.source_id', '=', $feature);
        })->where('v.gis_layer_id', $layer->id)->whereIn('v.status', ['published', 'seeded'])->where(fn ($query) => $query->where('v.status', 'published')->orWhereNotNull('vf.feature_id'))->orderByDesc('v.id')->paginate(100, ['v.id', 'v.published_at', 'v.author_id', 'vf.feature_id as content_id']);

        return response()->json($rows);
    }

    public function compare(Request $request, GisLayer $layer): JsonResponse
    {
        Gate::authorize('view', $layer);
        $request->validate(['left' => ['required', 'integer'], 'right' => ['required', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
        $left = $layer->versions()->whereKey($request->integer('left'))->whereIn('status', ['published', 'seeded'])->firstOrFail();
        $right = $layer->versions()->whereKey($request->integer('right'))->whereIn('status', ['published', 'seeded'])->firstOrFail();
        abort_unless($left->status === 'published' && $right->status === 'published', 422, 'Неполный снимок нельзя использовать для определения удалений.');
        $sql = "WITH l AS (SELECT * FROM gis_version_features WHERE version_id=?), r AS (SELECT * FROM gis_version_features WHERE version_id=?) SELECT COALESCE(l.source_id,r.source_id) AS source_id, l.feature_id AS old_content, r.feature_id AS new_content, CASE WHEN l.feature_id IS NULL THEN 'added' WHEN r.feature_id IS NULL THEN 'removed' ELSE 'changed' END AS change FROM l FULL OUTER JOIN r ON l.source_id=r.source_id WHERE l.feature_id IS DISTINCT FROM r.feature_id";
        $counts = DB::select('SELECT change,count(*) AS count FROM ('.$sql.') diff GROUP BY change', [$left->id, $right->id]);
        $rows = DB::select($sql.' ORDER BY source_id LIMIT 101 OFFSET ?', [$left->id, $right->id, ($request->integer('page', 1) - 1) * 100]);

        return response()->json(['counts' => $counts, 'changes' => array_slice($rows, 0, 100), 'more' => count($rows) > 100]);
    }

    public function store(StoreGisFeatureRequest $request, GisLayer $layer, EditGisFeature $edit): JsonResponse
    {
        $version = $edit->handle($request->user(), $layer, $request->integer('version_id'), null, $request->validated('geometry'), $request->validated('properties'));

        return response()->json(['version_id' => $version->id], 201);
    }

    public function update(StoreGisFeatureRequest $request, GisLayer $layer, string $feature, EditGisFeature $edit): JsonResponse
    {
        $version = $edit->handle($request->user(), $layer, $request->integer('version_id'), $feature, $request->validated('geometry'), $request->validated('properties'));

        return response()->json(['version_id' => $version->id]);
    }

    public function destroy(Request $request, GisLayer $layer, string $feature, EditGisFeature $edit): JsonResponse
    {
        Gate::authorize('update', $layer);
        $request->validate(['version_id' => ['required', 'integer']]);
        $version = $edit->handle($request->user(), $layer, $request->integer('version_id'), $feature, null, delete: true);

        return response()->json(['version_id' => $version->id]);
    }

    public function restore(Request $request, GisLayer $layer, string $feature, EditGisFeature $edit): JsonResponse
    {
        Gate::authorize('update', $layer);
        $request->validate(['version_id' => ['required', 'integer'], 'content_id' => ['required', 'integer']]);
        $version = $edit->handle($request->user(), $layer, $request->integer('version_id'), $feature, null, restore: $request->integer('content_id'));

        return response()->json(['version_id' => $version->id]);
    }
}

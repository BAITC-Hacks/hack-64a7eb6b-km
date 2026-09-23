import { useHttp, usePage } from '@inertiajs/react';
import type { Geometry } from 'geojson';
import type {
    Map as MapLibreMap,
    StyleSpecification,
    LayerSpecification,
} from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import gisWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import type { TerraDraw } from 'terra-draw';
import * as FeatureApi from '@/actions/App/Http/Controllers/GisFeatureController';
import * as LayerApi from '@/actions/App/Http/Controllers/GisLayerController';
import * as StateApi from '@/actions/App/Http/Controllers/GisMapStateController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { featureLayers } from '@/lib/gis-map-style';
import {
    simulatorDistrict,
    simulatorDistrictLayers,
} from '@/lib/gis-simulator-districts';
import type { SimulationResult } from '@/types/simulation';
import type { Auth } from '@/types';
import type {
    FeatureDetail,
    FeatureHistory,
    GisCatalog,
    GisLayer,
    GisVersion,
    LayerSetting,
} from '@/types/gis';
import 'maplibre-gl/dist/maplibre-gl.css';

type Props = {
    selectedDistrict: string;
    onSelectDistrict: (id: string) => void;
    result: SimulationResult | null;
};
const emptyCatalog: GisCatalog = {
    layers: [],
    can_create: false,
    can_sync: false,
};
const dateText = (value: string | null) =>
    value ? new Date(value).toLocaleString('ru-RU') : 'Нет снимка';
const statusText: Record<string, string> = {
    discovered: 'Загрузка',
    ready: 'Готов',
    error: 'Ошибка обновления',
    unavailable: 'Недоступен',
    not_seeded: 'Не включён в seed',
};

function attributeText(value: unknown): string {
    if (typeof value === 'string') return value;
    if (typeof value === 'number' || typeof value === 'boolean')
        return String(value);
    return value == null ? '—' : JSON.stringify(value);
}

async function json<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal,
    });
    if (!response.ok)
        throw new Error(`Не удалось загрузить данные (${response.status}).`);
    return response.json() as Promise<T>;
}

export function GisMap({ selectedDistrict, onSelectDistrict, result }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const host = useRef<HTMLDivElement>(null);
    const draw = useRef<TerraDraw | null>(null);
    const [map, setMap] = useState<MapLibreMap | null>(null);
    const [catalog, setCatalog] = useState<GisCatalog>(emptyCatalog);
    const [settings, setSettings] = useState<LayerSetting[]>([]);
    const [loadedState, setLoadedState] = useState(false);
    const [reload, setReload] = useState(0);
    const [search, setSearch] = useState('');
    const [group, setGroup] = useState('');
    const [at, setAt] = useState('');
    const [compareAt, setCompareAt] = useState('');
    const [compareCatalog, setCompareCatalog] = useState<GisCatalog | null>(
        null,
    );
    const [compareMix, setCompareMix] = useState(0.5);
    const [sidebar, setSidebar] = useState(true);
    const [modelVisible, setModelVisible] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [selected, setSelected] = useState<{
        layer: GisLayer;
        detail: FeatureDetail;
    } | null>(null);
    const [history, setHistory] = useState<FeatureHistory[]>([]);
    const [historyPage, setHistoryPage] = useState(1);
    const [historyMore, setHistoryMore] = useState(false);
    const [versions, setVersions] = useState<GisVersion[]>([]);
    const [editLayer, setEditLayer] = useState('');
    const [editSource, setEditSource] = useState<string | null>(null);
    const [draftGeometry, setDraftGeometry] = useState<Geometry | null>(null);
    const [draftTitle, setDraftTitle] = useState('');
    const [draftDescription, setDraftDescription] = useState('');
    const [draftColor, setDraftColor] = useState('#de8544');
    const [draftAttributes, setDraftAttributes] = useState('{}');
    const [newLayerTitle, setNewLayerTitle] = useState('Мои объекты');
    const [editing, setEditing] = useState(false);
    const [comparison, setComparison] = useState<{
        counts: { change: string; count: number }[];
        changes: { source_id: string; change: string }[];
        more: boolean;
    } | null>(null);
    const [comparePage, setComparePage] = useState(1);
    const districtLayer = catalog.layers.find(
        (layer) => layer.is_district_boundary && layer.kind === 'vector',
    );
    const latest = useRef({ catalog, at, editing, onSelectDistrict, result });
    latest.current = { catalog, at, editing, onSelectDistrict, result };
    const http = useHttp<
        { title?: string; version_id?: number; content_id?: number },
        { id?: string; version_id?: number }
    >({});
    const settingsHttp = useHttp({ state: { layers: [] as LayerSetting[] } });

    function report(reason: unknown) {
        setError(
            reason instanceof Error
                ? reason.message
                : 'Не удалось выполнить действие.',
        );
    }
    async function mutate(
        method: 'post' | 'put' | 'delete',
        url: string,
        payload: Record<string, unknown>,
    ) {
        setError('');
        http.transform(() => payload);
        let failure = '';
        let result;
        try {
            result = await http[method](url, {
                onHttpException: (response) => {
                    setError(
                        response.status === 409
                            ? 'Слой изменён в другой вкладке. Обновите карту и повторите правку.'
                            : `Не удалось сохранить (${response.status}).`,
                    );
                },
                onError: (errors) => {
                    failure = Object.values(errors).flat().join(' ');
                },
            });
        } catch (reason) {
            if (
                typeof reason === 'object' &&
                reason &&
                'response' in reason &&
                (reason.response as { status?: number }).status === 409
            )
                throw new Error(
                    'Слой изменён в другой вкладке. Обновите карту и повторите правку.',
                );
            throw reason;
        }
        if (failure) throw new Error(failure);
        return result;
    }

    useEffect(() => {
        const controller = new AbortController();
        void json<GisCatalog>(
            LayerApi.index.url({
                query: at ? { at: new Date(at).toISOString() } : {},
            }),
            controller.signal,
        )
            .then((data) => {
                setCatalog(data);
                setSettings((previous) =>
                    data.layers.map(
                        (layer, index) =>
                            previous.find((entry) => entry.id === layer.id) ?? {
                                id: layer.id,
                                visible: layer.owned || layer.default_visible,
                                opacity: 1,
                                order: index,
                            },
                    ),
                );
            })
            .catch((reason) => {
                if (!controller.signal.aborted) report(reason);
            });
        return () => controller.abort();
    }, [at, reload]);

    useEffect(() => {
        const controller = new AbortController();
        if (!compareAt) {
            setCompareCatalog(null);
            setComparison(null);
            return;
        }
        void json<GisCatalog>(
            LayerApi.index.url({
                query: { at: new Date(compareAt).toISOString() },
            }),
            controller.signal,
        )
            .then(setCompareCatalog)
            .catch((reason) => {
                if (!controller.signal.aborted) report(reason);
            });
        return () => controller.abort();
    }, [compareAt, reload]);

    useEffect(() => {
        let disposed = false;
        const restore = async () => {
            try {
                const saved =
                    auth.user && auth.permissions.includes('workspace.view')
                        ? (
                              await json<{
                                  state: { layers?: LayerSetting[] };
                              }>(StateApi.show.url())
                          ).state
                        : (JSON.parse(
                              localStorage.getItem('astana-gis-state') ?? '{}',
                          ) as { layers?: LayerSetting[] });
                if (!disposed && Array.isArray(saved.layers))
                    setSettings((current) => [
                        ...saved.layers!,
                        ...current.filter(
                            (entry) =>
                                !saved.layers!.some(
                                    (item) => item.id === entry.id,
                                ),
                        ),
                    ]);
            } catch {
                /* A missing preference does not prevent displaying the map. */
            }
            if (!disposed) setLoadedState(true);
        };
        void restore();
        return () => {
            disposed = true;
        };
    }, [auth.user?.id, auth.permissions]);

    useEffect(() => {
        if (!loadedState || !settings.length) return;
        const timer = window.setTimeout(() => {
            const state = { layers: settings };
            if (auth.user && auth.permissions.includes('workspace.view')) {
                settingsHttp.transform(() => ({ state }));
                void settingsHttp
                    .put(StateApi.update.url())
                    .catch(() =>
                        setNotice('Не удалось сохранить настройки карты.'),
                    );
            } else
                localStorage.setItem('astana-gis-state', JSON.stringify(state));
        }, 700);
        return () => window.clearTimeout(timer);
    }, [settings, loadedState, auth.user, auth.permissions]);

    useEffect(() => {
        let instance: MapLibreMap | undefined;
        let disposed = false;
        let observer: ResizeObserver | undefined;
        void import('maplibre-gl')
            .then(async (lib) => {
                if (disposed || !host.current) return;
                lib.setWorkerUrl(gisWorkerUrl);
                instance = new lib.Map({
                    container: host.current,
                    transformRequest: (url) => ({
                        url: new URL(url, window.location.origin).href,
                    }),
                    center: [71.43, 51.145],
                    zoom: 10.4,
                    minZoom: 8,
                    maxZoom: 21,
                    style: {
                        version: 8,
                        sources: {},
                        layers: [
                            {
                                id: 'background',
                                type: 'background',
                                paint: { 'background-color': '#e9eee9' },
                            },
                        ],
                    },
                    attributionControl: {
                        customAttribution:
                            'Источник: Геопортал Астаны · esaulet.kz',
                    },
                });
                instance.addControl(new lib.NavigationControl(), 'top-right');
                instance.addControl(new lib.FullscreenControl(), 'top-right');
                observer = new ResizeObserver(() => instance?.resize());
                observer.observe(host.current);
                instance.on('error', (event) =>
                    setNotice(`Ошибка отображения: ${event.error.message}`),
                );
                instance.on('load', async () => {
                    if (disposed || !instance) return;
                    const [terra, adapter] = await Promise.all([
                        import('terra-draw'),
                        import('terra-draw-maplibre-gl-adapter'),
                    ]);
                    if (disposed || !instance) return;
                    const flags = {
                        feature: {
                            draggable: true,
                            coordinates: {
                                draggable: true,
                                deletable: true,
                                midpoints: true,
                            },
                        },
                    };
                    const editor = new terra.TerraDraw({
                        adapter: new adapter.TerraDrawMapLibreGLAdapter({
                            map: instance,
                            coordinatePrecision: 7,
                        }),
                        modes: [
                            new terra.TerraDrawPointMode(),
                            new terra.TerraDrawLineStringMode(),
                            new terra.TerraDrawPolygonMode(),
                            new terra.TerraDrawSelectMode({
                                flags: {
                                    point: flags,
                                    linestring: flags,
                                    polygon: flags,
                                },
                            }),
                        ],
                    });
                    editor.start();
                    editor.setMode('static');
                    editor.on('finish', () => {
                        const feature = editor
                            .getSnapshot()
                            .find(
                                (item) =>
                                    !item.properties.midPoint &&
                                    !item.properties.selectionPoint,
                            );
                        if (feature) setDraftGeometry(feature.geometry);
                        editor.setMode('select');
                    });
                    editor.on('change', () => {
                        const feature = editor
                            .getSnapshot()
                            .find(
                                (item) =>
                                    !item.properties.midPoint &&
                                    !item.properties.selectionPoint,
                            );
                        if (feature) setDraftGeometry(feature.geometry);
                    });
                    draw.current = editor;
                    setMap(instance);
                });
                instance.on('click', (event) => {
                    if (!instance || latest.current.editing) return;
                    const modelHit = instance.getLayer('model-zones-fill')
                        ? instance.queryRenderedFeatures(event.point, {
                              layers: ['model-zones-fill'],
                          })[0]
                        : undefined;
                    if (modelHit) {
                        const district = simulatorDistrict(
                            modelHit.properties,
                            latest.current.result?.districts ?? [],
                        );
                        if (district) {
                            latest.current.onSelectDistrict(district.id);
                            setNotice('');
                        } else {
                            setNotice(
                                `Для района «${attributeText(modelHit.properties.name_object)}» нет показателей в выбранной модели.`,
                            );
                        }
                        setSelected(null);
                        return;
                    }
                    const hit = instance
                        .queryRenderedFeatures(event.point)
                        .find(
                            (feature) =>
                                feature.layer.id.startsWith('gis:') ||
                                feature.layer.id.startsWith('difference:'),
                        );
                    if (!hit) return;
                    const layerId = hit.layer.id.split(':')[1];
                    const layer = latest.current.catalog.layers.find(
                        (entry) => entry.id === layerId,
                    );
                    if (!layer?.version_id) return;
                    if (layer.kind === 'vector_tile') {
                        setSelected({
                            layer,
                            detail: {
                                tile_only: true,
                                feature: {
                                    type: 'Feature',
                                    id: hit.id ?? hit.layer.id,
                                    content_id: 0,
                                    properties: hit.properties,
                                    geometry: hit.geometry,
                                },
                                assets: [],
                                version_id: layer.version_id,
                                observed_at: layer.observed_at ?? '',
                                source_url: layer.source_url,
                            },
                        });
                        setHistory([]);
                        setComparison(null);
                        return;
                    }
                    if (!hit.properties.source_id) return;
                    void json<FeatureDetail>(
                        FeatureApi.show.url(
                            {
                                layer: layerId,
                                feature: String(hit.properties.source_id),
                            },
                            {
                                query: {
                                    version: Number(
                                        hit.properties.display_version ??
                                            layer.version_id,
                                    ),
                                },
                            },
                        ),
                    )
                        .then((detail) => {
                            setSelected({ layer, detail });
                            setHistory([]);
                            setComparison(null);
                        })
                        .catch(report);
                });
            })
            .catch(report);
        return () => {
            disposed = true;
            observer?.disconnect();
            draw.current?.stop();
            draw.current = null;
            instance?.remove();
        };
    }, []);

    useEffect(() => {
        if (!map) return;
        let disposed = false;
        let labelFonts: string[] | null = null;
        const layersToRemove = map
            .getStyle()
            .layers.filter(
                (layer) =>
                    layer.id.startsWith('gis:') ||
                    layer.id.startsWith('compare:') ||
                    layer.id.startsWith('difference:'),
            );
        layersToRemove.reverse().forEach((layer) => map.removeLayer(layer.id));
        for (const source of Object.keys(map.getStyle().sources))
            if (
                source.startsWith('gis:') ||
                source.startsWith('compare:') ||
                source.startsWith('difference:')
            )
                map.removeSource(source);
        const addGisLayer = (spec: LayerSpecification) => {
            map.addLayer(
                spec,
                map.getLayer('model-zones-fill')
                    ? 'model-zones-fill'
                    : undefined,
            );
        };
        const mount = async (
            layer: GisLayer,
            setting: LayerSetting,
            prefix: string,
            mix: number,
        ) => {
            if (!layer.version_id || !setting.visible || layer.kind === 'table')
                return;
            const key = `${prefix}:${layer.id}`;
            const opacity = setting.opacity * mix;
            if (layer.kind === 'vector_tile') {
                const style = await json<StyleSpecification>(
                    LayerApi.style.url({
                        layer: layer.id,
                        version: layer.version_id,
                    }),
                );
                if (disposed) return;
                if (style.glyphs) map.setGlyphs(style.glyphs);
                if (typeof style.sprite === 'string') {
                    const spriteId = `${key}-${layer.version_id}`;
                    if (
                        !map
                            .getSprite()
                            ?.some((sprite) => sprite.id === spriteId)
                    )
                        map.addSprite(spriteId, style.sprite);
                }
                for (const [id, source] of Object.entries(style.sources))
                    map.addSource(`${key}:${id}`, source);
                for (const native of style.layers) {
                    if (
                        !('source' in native) ||
                        typeof native.source !== 'string'
                    )
                        continue;
                    const spec = {
                        ...native,
                        id: `${key}:${native.id}`,
                        source: `${key}:${native.source}`,
                    } as LayerSpecification;
                    if (
                        'layout' in spec &&
                        spec.type === 'symbol' &&
                        typeof spec.layout?.['icon-image'] === 'string'
                    )
                        spec.layout = {
                            ...spec.layout,
                            'icon-image': `${key}-${layer.version_id}:${spec.layout['icon-image']}`,
                        };
                    if (spec.type === 'fill')
                        spec.paint = { ...spec.paint, 'fill-opacity': opacity };
                    if (spec.type === 'line')
                        spec.paint = { ...spec.paint, 'line-opacity': opacity };
                    if (spec.type === 'symbol')
                        spec.paint = {
                            ...spec.paint,
                            'text-opacity': opacity,
                            'icon-opacity': opacity,
                        };
                    addGisLayer(spec);
                }
                return;
            }
            const format = layer.kind === 'raster' ? 'png' : 'pbf';
            const template = LayerApi.tile.url({
                layer: layer.id,
                version: layer.version_id,
                z: '{z}',
                x: '{x}',
                y: '{y}',
                format,
            });
            if (layer.kind === 'raster') {
                map.addSource(key, {
                    type: 'raster',
                    tiles: [template],
                    tileSize: 256,
                    maxzoom: layer.coverage?.max_zoom ?? 18,
                });
                addGisLayer({
                    id: `${key}:raster`,
                    type: 'raster',
                    source: key,
                    paint: { 'raster-opacity': opacity },
                });
            } else {
                map.addSource(key, {
                    type: 'vector',
                    tiles: [template],
                    maxzoom: 18,
                });
                for (const spec of featureLayers(
                    layer,
                    key,
                    key,
                    opacity,
                    labelFonts,
                ))
                    addGisLayer(spec);
            }
        };
        void (async () => {
            const glyphLayer = catalog.layers.find(
                (layer) => layer.kind === 'vector_tile' && layer.version_id,
            );
            if (glyphLayer?.version_id) {
                const glyphStyle = await json<StyleSpecification>(
                    LayerApi.style.url({
                        layer: glyphLayer.id,
                        version: glyphLayer.version_id,
                    }),
                );
                if (disposed) return;
                if (glyphStyle.glyphs) map.setGlyphs(glyphStyle.glyphs);
                const symbol = glyphStyle.layers.find(
                    (layer) =>
                        layer.type === 'symbol' &&
                        Array.isArray(layer.layout?.['text-font']),
                );
                if (
                    symbol?.type === 'symbol' &&
                    Array.isArray(symbol.layout?.['text-font']) &&
                    symbol.layout['text-font'].every(
                        (item) => typeof item === 'string',
                    )
                )
                    labelFonts = symbol.layout['text-font'] as string[];
            }
            for (const setting of [...settings].sort(
                (a, b) => a.order - b.order,
            )) {
                if (disposed) return;
                const layer = catalog.layers.find(
                    (entry) => entry.id === setting.id,
                );
                if (!layer) continue;
                const counterpart = compareCatalog?.layers.find(
                    (entry) => entry.id === layer.id,
                );
                await mount(
                    layer,
                    setting,
                    'gis',
                    counterpart?.version_id ? 1 - compareMix : 1,
                );
                if (counterpart?.version_id && !disposed) {
                    await mount(counterpart, setting, 'compare', compareMix);
                    if (
                        setting.visible &&
                        layer.version_id &&
                        layer.complete &&
                        counterpart.complete &&
                        ['vector', 'custom'].includes(layer.kind)
                    ) {
                        const key = `difference:${layer.id}`;
                        map.addSource(key, {
                            type: 'vector',
                            maxzoom: 18,
                            tiles: [
                                LayerApi.differenceTile.url({
                                    layer: layer.id,
                                    left: layer.version_id,
                                    right: counterpart.version_id,
                                    z: '{z}',
                                    x: '{x}',
                                    y: '{y}',
                                }),
                            ],
                        });
                        const changedLayer: GisLayer = {
                            ...layer,
                            owned: false,
                            drawing: {
                                renderer: {
                                    type: 'uniqueValue',
                                    field1: 'change',
                                    uniqueValueInfos: [
                                        {
                                            value: 'added',
                                            symbol: {
                                                color: [16, 160, 96, 255],
                                            },
                                        },
                                        {
                                            value: 'removed',
                                            symbol: {
                                                color: [215, 49, 65, 255],
                                            },
                                        },
                                        {
                                            value: 'changed',
                                            symbol: {
                                                color: [237, 158, 36, 255],
                                            },
                                        },
                                    ],
                                },
                            },
                        };
                        for (const spec of featureLayers(
                            changedLayer,
                            key,
                            key,
                            setting.opacity,
                        ))
                            addGisLayer(spec);
                    }
                }
            }
        })().catch((reason) => {
            if (!disposed) report(reason);
        });
        return () => {
            disposed = true;
        };
    }, [map, catalog, settings, compareCatalog, compareMix]);

    useEffect(() => {
        if (!map) return;
        if (map.getLayer('model-zones-fill'))
            map.removeLayer('model-zones-fill');
        if (map.getLayer('model-zones-line'))
            map.removeLayer('model-zones-line');
        if (map.getSource('model-zones')) map.removeSource('model-zones');
        if (!modelVisible || !districtLayer?.version_id) return;
        map.addSource('model-zones', {
            type: 'vector',
            maxzoom: 18,
            tiles: [
                LayerApi.tile.url({
                    layer: districtLayer.id,
                    version: districtLayer.version_id,
                    z: '{z}',
                    x: '{x}',
                    y: '{y}',
                    format: 'pbf',
                }),
            ],
        });
        for (const spec of simulatorDistrictLayers(
            result?.districts ?? [],
            selectedDistrict,
        ))
            map.addLayer(spec);
    }, [map, modelVisible, selectedDistrict, districtLayer, result]);

    useEffect(() => {
        if (!selected) return;
        const controller = new AbortController();
        void json<{ data: GisVersion[] }>(
            LayerApi.versions.url(selected.layer.id),
            controller.signal,
        )
            .then((data) => setVersions(data.data))
            .catch(() => {});
        return () => controller.abort();
    }, [selected]);

    function changeSetting(id: string, patch: Partial<LayerSetting>) {
        setSettings((current) =>
            current.map((entry) =>
                entry.id === id ? { ...entry, ...patch } : entry,
            ),
        );
    }
    function moveLayer(id: string, direction: number) {
        const ordered = [...settings].sort((a, b) => a.order - b.order);
        const index = ordered.findIndex((item) => item.id === id);
        const next = Math.max(
            0,
            Math.min(ordered.length - 1, index + direction),
        );
        [ordered[index], ordered[next]] = [ordered[next], ordered[index]];
        setSettings(ordered.map((entry, order) => ({ ...entry, order })));
    }
    function stopEditing() {
        draw.current?.clear();
        draw.current?.setMode('static');
        setEditing(false);
        setDraftGeometry(null);
        setEditSource(null);
    }
    function startDrawing(mode: 'point' | 'linestring' | 'polygon') {
        if (!editLayer || at) return;
        setSelected(null);
        setEditSource(null);
        setDraftGeometry(null);
        setEditing(true);
        setDraftTitle('');
        setDraftDescription('');
        setDraftAttributes('{}');
        draw.current?.clear();
        draw.current?.setMode(mode);
    }
    function editFeature(copy: boolean) {
        if (!selected?.detail.feature.geometry || at) return;
        if (
            !copy &&
            selected.detail.version_id !==
                catalog.layers.find((layer) => layer.id === selected.layer.id)
                    ?.version_id
        )
            return;
        const geometry = selected.detail.feature.geometry;
        const modes: Partial<Record<Geometry['type'], string>> = {
            Point: 'point',
            LineString: 'linestring',
            Polygon: 'polygon',
        };
        const mode = modes[geometry.type];
        if (!mode) {
            setError(
                'Редактор вершин поддерживает отдельную точку, линию или полигон. Для составной геометрии доступно копирование целиком.',
            );
            if (!copy) return;
        }
        const properties = selected.detail.feature.properties;
        setEditSource(copy ? null : String(selected.detail.feature.id));
        if (!copy) setEditLayer(selected.layer.id);
        setDraftTitle(
            attributeText(
                properties.title ??
                    properties.NAME ??
                    properties.name_object ??
                    selected.layer.title,
            ),
        );
        setDraftDescription(attributeText(properties.description ?? ''));
        setDraftColor(attributeText(properties.color ?? '#de8544'));
        const attributes = { ...properties };
        delete attributes.title;
        delete attributes.description;
        delete attributes.color;
        setDraftAttributes(JSON.stringify(attributes, null, 2));
        setDraftGeometry(geometry);
        setEditing(true);
        draw.current?.clear();
        if (mode) {
            draw.current?.addFeatures([
                {
                    type: 'Feature',
                    id: crypto.randomUUID(),
                    properties: { mode },
                    geometry: geometry as Extract<
                        Geometry,
                        { type: 'Point' | 'LineString' | 'Polygon' }
                    >,
                },
            ]);
            draw.current?.setMode('select');
        }
    }
    async function saveFeature() {
        try {
            const layer = catalog.layers.find(
                (entry) => entry.id === editLayer,
            );
            if (!layer?.version_id || !draftGeometry)
                throw new Error('Выберите личный слой и нарисуйте объект.');
            const attributes: unknown = JSON.parse(draftAttributes);
            if (
                !attributes ||
                typeof attributes !== 'object' ||
                Array.isArray(attributes)
            )
                throw new Error('Атрибуты должны быть JSON-объектом.');
            const payload = {
                version_id: layer.version_id,
                geometry: draftGeometry,
                properties: {
                    ...attributes,
                    title: draftTitle,
                    description: draftDescription,
                    color: draftColor,
                },
            };
            await mutate(
                editSource ? 'put' : 'post',
                editSource
                    ? FeatureApi.update.url({
                          layer: layer.id,
                          feature: editSource,
                      })
                    : FeatureApi.store.url(layer.id),
                payload,
            );
            stopEditing();
            setSelected(null);
            setReload((value) => value + 1);
            setNotice('Объект сохранён. Предыдущая версия доступна в истории.');
        } catch (reason) {
            report(reason);
        }
    }
    async function showHistory(page = 1) {
        if (!selected) return;
        try {
            const data = await json<{
                data: FeatureHistory[];
                next_page_url: string | null;
            }>(
                FeatureApi.history.url(
                    {
                        layer: selected.layer.id,
                        feature: String(selected.detail.feature.id),
                    },
                    { query: { page } },
                ),
            );
            setHistory((current) =>
                page === 1 ? data.data : [...current, ...data.data],
            );
            setHistoryPage(page);
            setHistoryMore(data.next_page_url !== null);
        } catch (reason) {
            report(reason);
        }
    }
    async function restoreFeature(contentId: number) {
        if (!selected) return;
        try {
            const current = await json<GisCatalog>(LayerApi.index.url());
            const versionId = current.layers.find(
                (layer) => layer.id === selected.layer.id,
            )?.version_id;
            if (!versionId)
                throw new Error('Текущий снимок личного слоя не найден.');
            await mutate(
                'post',
                FeatureApi.restore.url({
                    layer: selected.layer.id,
                    feature: String(selected.detail.feature.id),
                }),
                { version_id: versionId, content_id: contentId },
            );
            setAt('');
            setReload((value) => value + 1);
            setSelected(null);
            setNotice(
                'Объект восстановлен в текущем состоянии. Создана новая запись истории.',
            );
        } catch (reason) {
            report(reason);
        }
    }
    async function compareVersions(page = 1) {
        if (!selected || !compareCatalog) return;
        const comparisonLayer = compareCatalog.layers.find(
            (entry) => entry.id === selected.layer.id,
        );
        if (!selected.layer.complete || !comparisonLayer?.complete) {
            setError(
                'Для неполных снимков доступно визуальное сравнение. Определять добавления и удаления по ним нельзя.',
            );
            return;
        }
        const right = comparisonLayer.version_id;
        if (!right) {
            setError('На вторую дату снимок этого слоя отсутствует.');
            return;
        }
        try {
            setComparison(
                await json(
                    FeatureApi.compare.url(selected.layer.id, {
                        query: {
                            left: selected.detail.version_id,
                            right,
                            page,
                        },
                    }),
                ),
            );
            setComparePage(page);
        } catch (reason) {
            report(reason);
        }
    }
    function exportLayer(layer: GisLayer) {
        if (!layer.version_id) return;
        const link = document.createElement('a');
        link.href = FeatureApi.exportMethod.url(layer.id, {
            query: { version: layer.version_id },
        });
        link.download = `${layer.title}.geojson`;
        link.click();
        setNotice('Начата загрузка GeoJSON выбранного снимка.');
    }
    const ownLayers = catalog.layers.filter((layer) => layer.can_edit);
    const groups = [
        ...new Set(
            catalog.layers
                .map((layer) => layer.group_path.join(' / '))
                .filter(Boolean),
        ),
    ].sort();
    const visibleLayers = catalog.layers
        .filter((layer) => !group || layer.group_path.join(' / ') === group)
        .filter((layer) =>
            `${layer.title} ${layer.occurrences.map((entry) => entry.map_title).join(' ')}`
                .toLowerCase()
                .includes(search.toLowerCase()),
        )
        .sort(
            (a, b) =>
                (settings.find((entry) => entry.id === a.id)?.order ?? 0) -
                (settings.find((entry) => entry.id === b.id)?.order ?? 0),
        );

    return (
        <section
            className="relative border-b border-border"
            aria-label="GIS-карта Астаны"
        >
            <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setSidebar(!sidebar)}
                >
                    Слои · {catalog.layers.length}
                </Button>
                <strong className="mr-auto text-sm">Карта Астаны</strong>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        map?.flyTo({ center: [71.43, 51.145], zoom: 10.4 })
                    }
                >
                    Весь город
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setReload((value) => value + 1);
                        setNotice('Каталог обновлён.');
                    }}
                >
                    Обновить
                </Button>
            </div>
            <div className="flex flex-wrap items-end gap-3 bg-muted/40 px-4 py-2 text-xs">
                <label className="grid gap-1">
                    Состояние на дату
                    <input
                        aria-label="Состояние на дату"
                        type="datetime-local"
                        className="rounded border bg-background p-1.5"
                        value={at}
                        onChange={(event) => {
                            stopEditing();
                            setSelected(null);
                            setAt(event.target.value);
                        }}
                    />
                </label>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setAt('');
                        setSelected(null);
                    }}
                >
                    Сейчас
                </Button>
                <label className="grid gap-1">
                    Сравнить с датой
                    <input
                        aria-label="Сравнить с датой"
                        type="datetime-local"
                        className="rounded border bg-background p-1.5"
                        value={compareAt}
                        onChange={(event) => setCompareAt(event.target.value)}
                    />
                </label>
                {compareAt && (
                    <>
                        <input
                            aria-label="Наложение второго состояния"
                            type="range"
                            min="0"
                            max="1"
                            step="0.05"
                            value={compareMix}
                            onChange={(event) =>
                                setCompareMix(Number(event.target.value))
                            }
                        />
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setCompareAt('')}
                        >
                            Закрыть сравнение
                        </Button>
                    </>
                )}
            </div>
            {error && (
                <div
                    role="alert"
                    className="flex items-center gap-2 bg-destructive/10 px-4 py-2 text-sm text-destructive"
                >
                    <span className="flex-1">{error}</span>
                    <button
                        onClick={() => setError('')}
                        aria-label="Закрыть ошибку"
                    >
                        ×
                    </button>
                </div>
            )}
            {notice && (
                <div
                    role="status"
                    className="flex items-center gap-2 bg-muted px-4 py-2 text-xs"
                >
                    <span className="flex-1">{notice}</span>
                    <button
                        onClick={() => setNotice('')}
                        aria-label="Закрыть сообщение"
                    >
                        ×
                    </button>
                </div>
            )}
            {compareAt && (
                <p className="bg-muted px-4 py-2 text-xs">
                    <span className="text-emerald-700">● Добавлено</span> ·{' '}
                    <span className="text-amber-700">● Изменено</span> ·{' '}
                    <span className="text-red-700">● Удалено</span> — переход от
                    первого состояния ко второй дате. Ползунок сравнивает
                    изображения.
                </p>
            )}
            <div className="relative min-h-150">
                <div
                    ref={host}
                    style={{ position: 'absolute', inset: 0 }}
                    aria-label="Интерактивная карта Астаны"
                />
                {sidebar && (
                    <aside className="absolute inset-y-3 left-3 z-10 flex w-64 max-w-[72%] flex-col overflow-hidden rounded-xl border bg-background/95 shadow-lg">
                        <div className="border-b p-3">
                            <Input
                                aria-label="Поиск слоёв"
                                placeholder="Найти слой…"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <select
                                aria-label="Группа слоёв"
                                className="mt-2 w-full rounded border bg-background p-1 text-xs"
                                value={group}
                                onChange={(event) =>
                                    setGroup(event.target.value)
                                }
                            >
                                <option value="">Все группы</option>
                                {groups.map((name) => (
                                    <option key={name} value={name}>
                                        {name}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Полностью загружено{' '}
                                {
                                    catalog.layers.filter(
                                        (layer) => layer.complete,
                                    ).length
                                }{' '}
                                из {catalog.layers.length} · данные esaulet
                            </p>
                        </div>
                        <div className="flex-1 overflow-y-auto p-2">
                            {!catalog.layers.length && (
                                <p className="p-3 text-sm text-muted-foreground">
                                    Каталог загружается. Первый импорт может
                                    занять время.
                                </p>
                            )}
                            {visibleLayers.map((layer) => {
                                const setting = settings.find(
                                    (entry) => entry.id === layer.id,
                                );
                                return (
                                    <div
                                        key={layer.id}
                                        className="border-b border-border/60 p-2 last:border-0"
                                    >
                                        <label className="flex items-start gap-2 text-xs font-medium">
                                            <input
                                                className="mt-0.5"
                                                type="checkbox"
                                                checked={
                                                    setting?.visible ?? false
                                                }
                                                disabled={
                                                    !layer.version_id ||
                                                    layer.kind === 'table'
                                                }
                                                onChange={(event) =>
                                                    changeSetting(layer.id, {
                                                        visible:
                                                            event.target
                                                                .checked,
                                                    })
                                                }
                                            />
                                            <span>{layer.title}</span>
                                        </label>
                                        <p className="mt-1 pl-5 text-[10px] text-muted-foreground">
                                            {layer.owned
                                                ? 'Личный слой'
                                                : layer.occurrences[0]
                                                      ?.map_title}{' '}
                                            {layer.group_path.length > 0 &&
                                                ` / ${layer.group_path.join(' / ')}`}
                                            ·{' '}
                                            {layer.kind === 'raster'
                                                ? 'Растр'
                                                : layer.kind === 'vector_tile'
                                                  ? 'Тайлы'
                                                  : `${layer.count?.toLocaleString('ru-RU') ?? '—'} объектов`}
                                        </p>
                                        <p className="mt-1 pl-5 text-[10px] text-muted-foreground">
                                            {layer.progress?.status ===
                                                'building' && !at
                                                ? [
                                                      'raster',
                                                      'vector_tile',
                                                  ].includes(layer.kind)
                                                    ? `Тайлы: ${Number(layer.progress.archive?.tiles ?? 0).toLocaleString('ru-RU')} / ${layer.progress.coverage?.expected_tiles?.toLocaleString('ru-RU') ?? '…'}`
                                                    : `Загружено ${layer.progress.feature_count.toLocaleString('ru-RU')} / ${layer.progress.expected_count?.toLocaleString('ru-RU') ?? '…'}`
                                                : layer.version_id
                                                  ? dateText(layer.observed_at)
                                                  : at
                                                    ? 'Нет снимка на эту дату'
                                                    : (statusText[
                                                          layer.status
                                                      ] ?? layer.status)}
                                        </p>
                                        {layer.version_id &&
                                            !layer.complete && (
                                                <p className="mt-1 pl-5 text-[10px] text-amber-700 dark:text-amber-400">
                                                    Неполный seed ·{' '}
                                                    {[
                                                        'raster',
                                                        'vector_tile',
                                                    ].includes(layer.kind)
                                                        ? `${Number(layer.progress?.archive?.tiles ?? 0).toLocaleString('ru-RU')} / ${layer.coverage?.expected_tiles?.toLocaleString('ru-RU') ?? '…'} тайлов`
                                                        : `${layer.count?.toLocaleString('ru-RU')} / ${layer.expected_count?.toLocaleString('ru-RU') ?? '…'} объектов`}
                                                    . Пропуски не означают
                                                    удаление.
                                                </p>
                                            )}
                                        {layer.error &&
                                            layer.progress?.status !==
                                                'building' && (
                                                <p className="mt-1 pl-5 text-[10px] text-destructive">
                                                    {layer.error}
                                                </p>
                                            )}
                                        {setting?.visible && (
                                            <div className="mt-2 flex items-center gap-2 pl-5">
                                                <input
                                                    className="min-w-0 flex-1"
                                                    aria-label={`Прозрачность ${layer.title}`}
                                                    type="range"
                                                    min="0"
                                                    max="1"
                                                    step="0.05"
                                                    value={setting.opacity}
                                                    onChange={(event) =>
                                                        changeSetting(
                                                            layer.id,
                                                            {
                                                                opacity: Number(
                                                                    event.target
                                                                        .value,
                                                                ),
                                                            },
                                                        )
                                                    }
                                                />
                                                <button
                                                    aria-label={`Поднять ${layer.title}`}
                                                    onClick={() =>
                                                        moveLayer(layer.id, 1)
                                                    }
                                                >
                                                    ↑
                                                </button>
                                                <button
                                                    aria-label={`Опустить ${layer.title}`}
                                                    onClick={() =>
                                                        moveLayer(layer.id, -1)
                                                    }
                                                >
                                                    ↓
                                                </button>
                                            </div>
                                        )}
                                        {layer.version_id && (
                                            <details className="mt-1 pl-5 text-[10px]">
                                                <summary className="cursor-pointer text-muted-foreground">
                                                    Сведения и легенда
                                                </summary>
                                                <p className="my-1">
                                                    {layer.attribution}
                                                </p>
                                                <a
                                                    className="my-1 block underline"
                                                    href={LayerApi.metadata.url(
                                                        layer.id,
                                                        {
                                                            query: {
                                                                version:
                                                                    layer.version_id,
                                                            },
                                                        },
                                                    )}
                                                    download
                                                >
                                                    Метаданные, стили и система
                                                    координат
                                                </a>
                                                <p className="my-1">
                                                    {layer.kind === 'raster'
                                                        ? 'Локальный снимок изображения до z18.'
                                                        : layer.kind ===
                                                            'vector_tile'
                                                          ? 'Архив готовых векторных тайлов; отображение до z18. Исходные объекты сервиса недоступны через этот тип слоя.'
                                                          : 'Атрибуты и полная геометрия сохранены в архиве.'}
                                                </p>
                                                {(layer.occurrences.some(
                                                    (entry) =>
                                                        entry.map_id ===
                                                        '559c4ce50e8749fd857bbf9bd6c4cf50',
                                                ) ||
                                                    layer.title.includes(
                                                        '2026',
                                                    )) && (
                                                    <p>
                                                        Соответствие вектора
                                                        растру 2026 года не
                                                        подтверждено.
                                                    </p>
                                                )}
                                                {layer.legend.layers
                                                    ?.filter(
                                                        (item) =>
                                                            String(
                                                                item.layerId,
                                                            ) ===
                                                            layer.source_url
                                                                ?.split('/')
                                                                .pop(),
                                                    )
                                                    .flatMap(
                                                        (item) => item.legend,
                                                    )
                                                    .map((entry, index) => (
                                                        <div
                                                            className="my-1 flex items-center gap-1"
                                                            key={index}
                                                        >
                                                            <img
                                                                alt=""
                                                                width="20"
                                                                height="20"
                                                                src={`data:${entry.contentType};base64,${entry.imageData}`}
                                                            />
                                                            <span>
                                                                {entry.label}
                                                            </span>
                                                        </div>
                                                    ))}
                                                {[
                                                    'vector',
                                                    'custom',
                                                    'table',
                                                ].includes(layer.kind) && (
                                                    <button
                                                        className="mt-2 underline"
                                                        onClick={() =>
                                                            exportLayer(layer)
                                                        }
                                                    >
                                                        Скачать GeoJSON
                                                    </button>
                                                )}
                                            </details>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                        {catalog.can_sync && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="m-2"
                                disabled={http.processing}
                                onClick={() =>
                                    void mutate('post', LayerApi.sync.url(), {})
                                        .then(() =>
                                            setNotice(
                                                'Проверка источников поставлена в очередь.',
                                            ),
                                        )
                                        .catch(report)
                                }
                            >
                                Загрузить обновления
                            </Button>
                        )}
                    </aside>
                )}
                {selected && !editing && (
                    <aside className="absolute inset-y-3 right-3 z-20 w-80 max-w-[90%] overflow-y-auto rounded-xl border bg-background p-4 shadow-lg">
                        <div className="flex items-start gap-2">
                            <h3 className="flex-1 text-sm font-semibold">
                                {selected.layer.title} ·{' '}
                                {String(selected.detail.feature.id)}
                            </h3>
                            <button
                                aria-label="Закрыть карточку"
                                onClick={() => setSelected(null)}
                            >
                                ×
                            </button>
                        </div>
                        <p className="my-2 text-xs text-muted-foreground">
                            Снимок: {dateText(selected.detail.observed_at)}
                        </p>
                        {selected.detail.source_url && (
                            <a
                                className="text-xs underline"
                                href={selected.detail.source_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Источник ArcGIS
                            </a>
                        )}
                        <dl className="my-3 grid gap-2 text-xs">
                            {Object.entries(
                                selected.detail.feature.properties,
                            ).map(([name, value]) => {
                                const field = selected.layer.fields.find(
                                    (entry) => entry.name === name,
                                );
                                const label = field?.domain?.codedValues?.find(
                                    (entry) => entry.code === value,
                                )?.name;
                                return (
                                    <div key={name}>
                                        <dt className="text-muted-foreground">
                                            {field?.alias ?? name}
                                        </dt>
                                        <dd className="break-words">
                                            {label ??
                                                (value == null
                                                    ? '—'
                                                    : typeof value === 'object'
                                                      ? JSON.stringify(value)
                                                      : attributeText(value))}
                                        </dd>
                                    </div>
                                );
                            })}
                        </dl>
                        {selected.detail.assets.map((asset) => (
                            <a
                                key={asset.id}
                                className="mb-2 block text-xs underline"
                                href={LayerApi.resource.url({
                                    layer: selected.layer.id,
                                    version: selected.detail.version_id,
                                    name: asset.name,
                                })}
                                download
                            >
                                {asset.name}
                            </a>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            {!selected.detail.tile_only && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void showHistory()}
                                >
                                    История объекта
                                </Button>
                            )}
                            {selected.detail.tile_only && (
                                <p className="text-xs text-muted-foreground">
                                    Атрибуты и геометрия отображаемого фрагмента
                                    тайла. История доступна в снимках слоя.
                                </p>
                            )}
                            {compareCatalog && !selected.detail.tile_only && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void compareVersions()}
                                >
                                    Изменения слоя
                                </Button>
                            )}
                            {!at && ownLayers.length > 0 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => editFeature(true)}
                                >
                                    {selected.detail.tile_only
                                        ? 'Копировать фрагмент в личный слой'
                                        : 'Копировать в личный слой'}
                                </Button>
                            )}
                            {!at &&
                                selected.layer.can_edit &&
                                selected.detail.version_id ===
                                    catalog.layers.find(
                                        (layer) =>
                                            layer.id === selected.layer.id,
                                    )?.version_id && (
                                    <>
                                        <Button
                                            size="sm"
                                            onClick={() => editFeature(false)}
                                        >
                                            Редактировать
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={() =>
                                                void mutate(
                                                    'delete',
                                                    FeatureApi.destroy.url({
                                                        layer: selected.layer
                                                            .id,
                                                        feature: String(
                                                            selected.detail
                                                                .feature.id,
                                                        ),
                                                    }),
                                                    {
                                                        version_id:
                                                            selected.layer
                                                                .version_id,
                                                    },
                                                )
                                                    .then(() => {
                                                        setReload(
                                                            (value) =>
                                                                value + 1,
                                                        );
                                                        setNotice(
                                                            'Объект удалён из текущего состояния. Восстановление доступно в открытой истории.',
                                                        );
                                                        void showHistory();
                                                    })
                                                    .catch(report)
                                            }
                                        >
                                            Удалить
                                        </Button>
                                    </>
                                )}
                        </div>
                        {history.length > 0 && (
                            <div className="mt-4 grid gap-2 text-xs">
                                <strong>Наблюдения объекта</strong>
                                {history.map((entry) => (
                                    <div
                                        className="rounded border p-2"
                                        key={entry.id}
                                    >
                                        <p>
                                            {dateText(entry.published_at)} ·{' '}
                                            {entry.content_id
                                                ? 'Присутствует'
                                                : 'Отсутствует'}
                                        </p>
                                        {entry.content_id && (
                                            <div className="mt-1 flex gap-2">
                                                <button
                                                    className="underline"
                                                    onClick={() =>
                                                        void json<FeatureDetail>(
                                                            FeatureApi.show.url(
                                                                {
                                                                    layer: selected
                                                                        .layer
                                                                        .id,
                                                                    feature:
                                                                        String(
                                                                            selected
                                                                                .detail
                                                                                .feature
                                                                                .id,
                                                                        ),
                                                                },
                                                                {
                                                                    query: {
                                                                        version:
                                                                            entry.id,
                                                                    },
                                                                },
                                                            ),
                                                        )
                                                            .then((detail) =>
                                                                setSelected({
                                                                    ...selected,
                                                                    detail,
                                                                }),
                                                            )
                                                            .catch(report)
                                                    }
                                                >
                                                    Просмотреть
                                                </button>
                                                {selected.layer.can_edit && (
                                                    <button
                                                        className="underline"
                                                        onClick={() =>
                                                            void restoreFeature(
                                                                entry.content_id!,
                                                            )
                                                        }
                                                    >
                                                        Восстановить в текущем
                                                        состоянии
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                {historyMore && (
                                    <button
                                        className="underline"
                                        onClick={() =>
                                            void showHistory(historyPage + 1)
                                        }
                                    >
                                        Более ранняя история
                                    </button>
                                )}
                            </div>
                        )}
                        {comparison && (
                            <div className="mt-4 text-xs">
                                <strong>Изменения между снимками</strong>
                                {comparison.counts.map((entry) => (
                                    <p key={entry.change}>
                                        {
                                            {
                                                added: 'Добавлено',
                                                changed: 'Изменено',
                                                removed: 'Удалено',
                                            }[entry.change]
                                        }
                                        : {entry.count}
                                    </p>
                                ))}
                                {comparison.changes.map((entry) => (
                                    <p key={entry.source_id}>
                                        {entry.source_id} · {entry.change}
                                    </p>
                                ))}
                                <div className="mt-2 flex gap-2">
                                    {comparePage > 1 && (
                                        <button
                                            onClick={() =>
                                                void compareVersions(
                                                    comparePage - 1,
                                                )
                                            }
                                        >
                                            Назад
                                        </button>
                                    )}
                                    {comparison.more && (
                                        <button
                                            onClick={() =>
                                                void compareVersions(
                                                    comparePage + 1,
                                                )
                                            }
                                        >
                                            Далее
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}
                        {versions.length > 0 && (
                            <details className="mt-4 text-xs">
                                <summary>
                                    Снимки слоя ({versions.length})
                                </summary>
                                {versions.map((version) => (
                                    <button
                                        key={version.id}
                                        className="mt-2 block underline"
                                        onClick={() => {
                                            const date = new Date(
                                                version.published_at,
                                            );
                                            const local = new Date(
                                                date.getTime() -
                                                    date.getTimezoneOffset() *
                                                        60000,
                                            )
                                                .toISOString()
                                                .slice(0, 19);
                                            setAt(local);
                                            setSelected(null);
                                        }}
                                    >
                                        {dateText(version.published_at)} ·{' '}
                                        {version.feature_count} объектов
                                    </button>
                                ))}
                            </details>
                        )}
                    </aside>
                )}
                {editing && (
                    <aside className="absolute inset-y-3 right-3 z-20 w-72 max-w-[90%] overflow-y-auto rounded-xl border bg-background p-4 shadow-lg">
                        <h3 className="mb-3 text-sm font-semibold">
                            {editSource
                                ? 'Редактирование объекта'
                                : 'Новый объект'}
                        </h3>
                        <label className="grid gap-1 text-xs">
                            Личный слой
                            <select
                                className="rounded border bg-background p-2"
                                value={editLayer}
                                disabled={!!editSource}
                                onChange={(event) =>
                                    setEditLayer(event.target.value)
                                }
                            >
                                <option value="">Выберите слой</option>
                                {ownLayers.map((layer) => (
                                    <option key={layer.id} value={layer.id}>
                                        {layer.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="mt-3 grid gap-1 text-xs">
                            Название
                            <Input
                                value={draftTitle}
                                onChange={(event) =>
                                    setDraftTitle(event.target.value)
                                }
                            />
                        </label>
                        <label className="mt-3 grid gap-1 text-xs">
                            Описание
                            <textarea
                                className="rounded border bg-background p-2"
                                value={draftDescription}
                                onChange={(event) =>
                                    setDraftDescription(event.target.value)
                                }
                            />
                        </label>
                        <label className="mt-3 flex items-center gap-2 text-xs">
                            Цвет
                            <input
                                type="color"
                                value={draftColor}
                                onChange={(event) =>
                                    setDraftColor(event.target.value)
                                }
                            />
                        </label>
                        <label className="mt-3 grid gap-1 text-xs">
                            Дополнительные атрибуты (JSON)
                            <textarea
                                className="min-h-28 rounded border bg-background p-2 font-mono"
                                value={draftAttributes}
                                onChange={(event) =>
                                    setDraftAttributes(event.target.value)
                                }
                            />
                        </label>
                        <p className="my-3 text-xs text-muted-foreground">
                            Завершите линию или полигон двойным нажатием.
                            Выберите фигуру для перемещения её вершин.
                        </p>
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                disabled={
                                    http.processing ||
                                    !draftGeometry ||
                                    !draftTitle
                                }
                                onClick={() => void saveFeature()}
                            >
                                Сохранить
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={stopEditing}
                            >
                                Отмена
                            </Button>
                        </div>
                    </aside>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-3 border-t bg-background px-4 py-3 text-xs">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={modelVisible}
                        onChange={(event) =>
                            setModelVisible(event.target.checked)
                        }
                    />
                    Районы симулятора
                </label>
                {modelVisible && (
                    <span className="text-muted-foreground">
                        {districtLayer?.version_id
                            ? 'Границы слоя «Районы» · выбранный — оранжевый, вне модели — серый'
                            : 'Границы районов недоступны на выбранную дату'}
                    </span>
                )}
                <span className="ml-auto text-muted-foreground">
                    История начинается с первой загрузки
                </span>
            </div>
            {catalog.can_create ? (
                <div className="flex flex-wrap items-center gap-2 border-t px-4 py-3">
                    <Input
                        className="w-40"
                        aria-label="Название нового слоя"
                        value={newLayerTitle}
                        onChange={(event) =>
                            setNewLayerTitle(event.target.value)
                        }
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={http.processing || !!at}
                        onClick={() =>
                            void mutate('post', LayerApi.store.url(), {
                                title: newLayerTitle,
                            })
                                .then((response) => {
                                    if (response.id) setEditLayer(response.id);
                                    setReload((value) => value + 1);
                                })
                                .catch(report)
                        }
                    >
                        Создать слой
                    </Button>
                    <select
                        aria-label="Слой для новых объектов"
                        className="max-w-44 rounded border bg-background p-2 text-xs"
                        value={editLayer}
                        onChange={(event) => setEditLayer(event.target.value)}
                    >
                        <option value="">Личный слой</option>
                        {ownLayers.map((layer) => (
                            <option key={layer.id} value={layer.id}>
                                {layer.title}
                            </option>
                        ))}
                    </select>
                    {(['point', 'linestring', 'polygon'] as const).map(
                        (mode) => (
                            <Button
                                key={mode}
                                size="sm"
                                variant="outline"
                                disabled={!editLayer || !!at || !map}
                                onClick={() => startDrawing(mode)}
                            >
                                {
                                    {
                                        point: 'Точка',
                                        linestring: 'Линия',
                                        polygon: 'Полигон',
                                    }[mode]
                                }
                            </Button>
                        ),
                    )}
                    {at && (
                        <span className="text-xs text-muted-foreground">
                            Исторический просмотр. Для правок выберите «Сейчас».
                        </span>
                    )}
                </div>
            ) : (
                <p className="border-t px-4 py-3 text-xs text-muted-foreground">
                    Войдите с правом редактирования рабочего пространства, чтобы
                    создавать личные слои и объекты.
                </p>
            )}
        </section>
    );
}

import type { Feature, Geometry } from 'geojson';

export type GisLayer = {
    id: string;
    title: string;
    kind:
        | 'vector'
        | 'table'
        | 'raster'
        | 'vector_tile'
        | 'custom'
        | 'unavailable';
    owned: boolean;
    can_edit: boolean;
    status: string;
    error: string | null;
    progress: {
        status: string;
        feature_count: number;
        expected_count: number | null;
        cursor: Record<string, unknown>;
        coverage: { expected_tiles?: number } | null;
        archive: { files: number; bytes: number; tiles: number } | null;
    } | null;
    min_scale: number;
    max_scale: number;
    attribution: string;
    description: string;
    source_url: string | null;
    group_path: string[];
    occurrences: {
        map_id: string;
        map_title: string;
        title: string;
        groups: string[];
    }[];
    version_id: number | null;
    published_at: string | null;
    observed_at: string | null;
    count: number | null;
    complete: boolean;
    expected_count: number | null;
    default_visible: boolean;
    is_district_boundary: boolean;
    coverage: {
        bbox3857?: number[];
        bbox4326?: number[] | null;
        max_zoom?: number;
        expected_tiles?: number;
    } | null;
    fields: {
        name: string;
        alias: string;
        domain?: { codedValues?: { code: unknown; name: string }[] };
    }[];
    drawing: {
        renderer?: Record<string, unknown>;
        transparency?: number;
        labelingInfo?: {
            labelExpression?: string | null;
            labelExpressionInfo?: { expression?: string; value?: string };
            symbol?: { font?: { size?: number }; color?: number[] };
            minScale?: number;
            maxScale?: number;
        }[];
    };
    legend: {
        layers?: {
            layerId: number;
            legend: { label: string; imageData: string; contentType: string }[];
        }[];
    };
};
export type GisCatalog = {
    layers: GisLayer[];
    can_create: boolean;
    can_sync: boolean;
};
export type LayerSetting = {
    id: string;
    visible: boolean;
    opacity: number;
    order: number;
};
export type GisFeature = Feature<Geometry | null, Record<string, unknown>> & {
    content_id: number;
};
export type FeatureDetail = {
    tile_only?: boolean;
    feature: GisFeature;
    assets: { id: number; name: string }[];
    version_id: number;
    observed_at: string;
    source_url: string | null;
};
export type FeatureHistory = {
    id: number;
    published_at: string;
    author_id: number | null;
    content_id: number | null;
};
export type GisVersion = {
    id: number;
    published_at: string;
    observed_at: string;
    feature_count: number;
};

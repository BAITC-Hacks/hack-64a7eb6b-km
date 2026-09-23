import type {
    DataDrivenPropertyValueSpecification,
    LayerSpecification,
} from 'maplibre-gl';
import type { GisLayer } from '@/types/gis';

export function symbolColor(symbol: unknown, fallback = '#368374'): string {
    if (!symbol || typeof symbol !== 'object') return fallback;
    const color = (symbol as { color?: number[] }).color;
    if (!Array.isArray(color) || color.length < 3) return fallback;
    return `rgba(${color[0]},${color[1]},${color[2]},${(color[3] ?? 255) / 255})`;
}

export function layerColor(
    layer: GisLayer,
): DataDrivenPropertyValueSpecification<string> {
    const renderer = layer.drawing?.renderer;
    if (layer.owned) return ['coalesce', ['get', 'color'], '#de8544'];
    if (!renderer) return '#368374';
    const fallback = symbolColor(renderer.defaultSymbol ?? renderer.symbol);
    if (
        renderer.type === 'uniqueValue' &&
        typeof renderer.field1 === 'string' &&
        Array.isArray(renderer.uniqueValueInfos)
    ) {
        const expression: unknown[] = [
            'match',
            ['to-string', ['get', renderer.field1]],
        ];
        for (const entry of renderer.uniqueValueInfos) {
            expression.push(
                String(entry.value),
                symbolColor(entry.symbol, fallback),
            );
        }
        expression.push(fallback);
        if (expression.length > 3)
            return expression as DataDrivenPropertyValueSpecification<string>;
    }
    if (
        renderer.type === 'classBreaks' &&
        typeof renderer.field === 'string' &&
        Array.isArray(renderer.classBreakInfos)
    ) {
        const expression: unknown[] = ['case'];
        for (const entry of renderer.classBreakInfos) {
            expression.push(
                [
                    '<=',
                    ['to-number', ['get', renderer.field], 0],
                    entry.classMaxValue,
                ],
                symbolColor(entry.symbol, fallback),
            );
        }
        expression.push(fallback);
        if (expression.length > 2)
            return expression as DataDrivenPropertyValueSpecification<string>;
    }
    return fallback;
}

export function featureLayers(
    layer: GisLayer,
    source: string,
    prefix: string,
    opacity: number,
    fonts: string[] | null = null,
): LayerSpecification[] {
    const color = layerColor(layer);
    const common = {
        source,
        'source-layer': 'features',
        minzoom: layer.min_scale
            ? Math.max(0, Math.log2(295828775.8 / layer.min_scale))
            : 0,
        maxzoom: layer.max_scale
            ? Math.min(24, Math.log2(295828775.8 / layer.max_scale))
            : 24,
    };
    const labels: LayerSpecification[] = [];
    for (const [index, label] of (
        layer.drawing?.labelingInfo ?? []
    ).entries()) {
        const expression =
            label.labelExpressionInfo?.expression ??
            label.labelExpression ??
            label.labelExpressionInfo?.value ??
            '';
        const field =
            expression
                .match(/\$feature(?:\.([\w]+)|\["([^"]+)"\])/)
                ?.slice(1)
                .find(Boolean) ??
            expression.match(/^\[([^\]]+)\]$/)?.[1] ??
            expression.match(/^\{([^}]+)\}$/)?.[1];
        if (!field || !fonts) continue;
        const suffix = expression.match(/\+\s*"([^"]*)"/)?.[1] ?? '';
        labels.push({
            ...common,
            id: `${prefix}:label:${index}`,
            type: 'symbol',
            filter: ['has', field],
            minzoom: label.minScale
                ? Math.max(0, Math.log2(295828775.8 / label.minScale))
                : common.minzoom,
            maxzoom: label.maxScale
                ? Math.min(24, Math.log2(295828775.8 / label.maxScale))
                : common.maxzoom,
            layout: {
                'text-field': [
                    'concat',
                    ['to-string', ['coalesce', ['get', field], '']],
                    suffix,
                ],
                'text-font': fonts,
                'text-size': ((label.symbol?.font?.size ?? 10) * 4) / 3,
                'text-max-width': 14,
            },
            paint: {
                'text-color': symbolColor(label.symbol, '#333333'),
                'text-halo-color': '#ffffff',
                'text-halo-width': 1,
                'text-opacity': opacity,
            },
        });
    }
    return [
        {
            ...common,
            id: `${prefix}:fill`,
            type: 'fill',
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: { 'fill-color': color, 'fill-opacity': opacity * 0.45 },
        },
        {
            ...common,
            id: `${prefix}:line`,
            type: 'line',
            filter: ['!=', ['geometry-type'], 'Point'],
            paint: {
                'line-color': color,
                'line-width': [
                    'interpolate',
                    ['linear'],
                    ['zoom'],
                    10,
                    0.6,
                    18,
                    2,
                ],
                'line-opacity': opacity,
            },
        },
        {
            ...common,
            id: `${prefix}:point`,
            type: 'circle',
            filter: ['==', ['geometry-type'], 'Point'],
            paint: {
                'circle-color': color,
                'circle-radius': 5,
                'circle-stroke-color': '#ffffff',
                'circle-stroke-width': 1,
                'circle-opacity': opacity,
            },
        },
        ...labels,
    ];
}

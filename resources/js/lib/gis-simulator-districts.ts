import type { LayerSpecification } from 'maplibre-gl';
import type { District } from '@/types/simulation';

type ModelDistrict = Pick<District, 'id' | 'name'>;

/** name_object identifies the current district; region can name its former parent. */
export function simulatorDistrict(
    properties: Record<string, unknown>,
    districts: ModelDistrict[],
): ModelDistrict | undefined {
    return districts.find(
        (district) => district.name === properties.name_object,
    );
}

export function simulatorDistrictLayers(
    districts: ModelDistrict[],
    selectedDistrict: string,
): LayerSpecification[] {
    const selectedName = districts.find(
        (district) => district.id === selectedDistrict,
    )?.name;
    const knownNames = districts.map((district) => district.name);

    return [
        {
            id: 'model-zones-fill',
            type: 'fill',
            source: 'model-zones',
            'source-layer': 'features',
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: {
                'fill-color': [
                    'case',
                    ['==', ['get', 'name_object'], selectedName ?? ''],
                    '#df9a50',
                    ['in', ['get', 'name_object'], ['literal', knownNames]],
                    '#69a285',
                    '#94a3b8',
                ],
                'fill-opacity': 0.3,
            },
        },
        {
            id: 'model-zones-line',
            type: 'line',
            source: 'model-zones',
            'source-layer': 'features',
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: {
                'line-color': [
                    'case',
                    ['==', ['get', 'name_object'], selectedName ?? ''],
                    '#b76b23',
                    '#526f60',
                ],
                'line-width': 2,
            },
        },
    ];
}

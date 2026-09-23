import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    createExpression,
    validateStyleMin,
} from '@maplibre/maplibre-gl-style-spec';
import {
    simulatorDistrict,
    simulatorDistrictLayers,
} from '../../resources/js/lib/gis-simulator-districts.ts';

const districts = [
    { id: 'esil', name: 'Есиль' },
    { id: 'almaty', name: 'Алматы' },
    { id: 'saryarka', name: 'Сарыарка' },
    { id: 'baikonur', name: 'Байконур' },
    { id: 'nura', name: 'Нура' },
];

await test('selects all model districts by current GIS names, including Nura with its former parent', () => {
    for (const district of districts) {
        const properties = {
            name: 'Район',
            name_object: district.name,
            region: district.id === 'nura' ? 'Есиль' : district.name,
        };

        assert.equal(simulatorDistrict(properties, districts)?.id, district.id);
    }
});

await test('does not assign Saraishyk or an unnamed feature to a former parent district', () => {
    assert.equal(
        simulatorDistrict(
            { name_object: 'Сарайшык', region: 'Алматы' },
            districts,
        ),
        undefined,
    );
    assert.equal(simulatorDistrict({ region: 'Есиль' }, districts), undefined);
    assert.equal(simulatorDistrict({ name_object: 'Нура' }, []), undefined);
});

await test('supports district identifiers from the selected dataset rather than hardcoded demo identifiers', () => {
    assert.equal(
        simulatorDistrict({ name_object: 'Нура' }, [
            { id: 'new-dataset-nura', name: 'Нура' },
        ])?.id,
        'new-dataset-nura',
    );
});

await test('renders valid vector styles with selected, modeled and unavailable districts distinguished', () => {
    const layers = simulatorDistrictLayers(districts, 'nura');
    assert.deepEqual(
        validateStyleMin({
            version: 8,
            sources: {
                'model-zones': {
                    type: 'vector',
                    tiles: ['https://example.test/{z}/{x}/{y}.pbf'],
                },
            },
            layers,
        }),
        [],
    );
    const expression = createExpression(
        layers[0].paint['fill-color'],
        'fill-color',
    );
    assert.equal(expression.result, 'success');
    const color = (name) =>
        expression.value.evaluate(
            { zoom: 10 },
            { type: 'Polygon', properties: { name_object: name } },
        );

    assert.equal(color('Нура'), '#df9a50');
    assert.equal(color('Есиль'), '#69a285');
    assert.equal(color('Сарайшык'), '#94a3b8');

    const empty = createExpression(
        simulatorDistrictLayers([], '')[0].paint['fill-color'],
        'fill-color',
    );
    assert.equal(empty.result, 'success');
    assert.equal(
        empty.value.evaluate(
            { zoom: 10 },
            { type: 'Polygon', properties: { name_object: 'Нура' } },
        ),
        '#94a3b8',
    );
});

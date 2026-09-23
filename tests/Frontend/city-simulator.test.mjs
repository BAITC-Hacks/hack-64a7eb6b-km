import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    baseline,
    calculateScenario,
    exampleDecisions,
    explainScenario,
    scenarioCost,
    validateDecisions,
} from '../../resources/js/lib/city-simulator.ts';

await test('reproduces the dataset baseline and the displayed five-decision scenario', () => {
    const result = calculateScenario(exampleDecisions);

    assert.ok(Math.abs(baseline.score - 52.55768) < 0.000001);
    assert.equal(baseline.criticalCount, 2);
    assert.equal(scenarioCost(exampleDecisions), 79);
    assert.ok(Math.abs(result.score - 55.55057) < 0.000001);
    assert.equal(result.criticalCount, 1);
    const nura = result.districts.find((district) => district.id === 'nura');
    assert.equal(nura.indicators.S1, 38);
    assert.equal(nura.indicators.S2, 43.75);
    assert.equal(nura.indicators.B1, 67.5);
    assert.equal(nura.indicators.C2, 54.375);
    assert.deepEqual(nura.critical, ['S1']);
});

await test('applies city measures to every district and district measures only to their target', () => {
    const result = calculateScenario(exampleDecisions);

    assert.equal(
        result.districts.find((district) => district.id === 'esil').indicators
            .C2,
        74.375,
    );
    assert.equal(
        result.districts.find((district) => district.id === 'esil').indicators
            .B1,
        78,
    );
    assert.equal(
        result.districts.find((district) => district.id === 'saryarka')
            .indicators.E1,
        51,
    );
    assert.equal(result.synergies.length, 1);
});

await test('changes the score when the same intervention is moved to another district', () => {
    const next = exampleDecisions.map((decision) =>
        decision.measureId === 'M8'
            ? { ...decision, districtId: 'esil' }
            : decision,
    );
    const result = calculateScenario(next);

    assert.notEqual(result.score, calculateScenario(exampleDecisions).score);
    assert.equal(result.criticalCount, 2);
    assert.equal(
        result.districts.find((district) => district.id === 'nura').indicators
            .S2,
        35,
    );
});

await test('requires exactly five decisions before publishing a score', () => {
    assert.equal(calculateScenario([]), null);
    assert.equal(calculateScenario(exampleDecisions.slice(0, 4)), null);
    assert.equal(
        calculateScenario([
            ...exampleDecisions,
            { measureId: 'M2', districtId: null },
        ]),
        null,
    );
    assert.deepEqual(validateDecisions([], false), []);
});

await test('rejects overspending, duplicates and unknown measures', () => {
    const overspent = [
        { measureId: 'M3', districtId: 'nura' },
        { measureId: 'M5', districtId: 'saryarka' },
        { measureId: 'M7', districtId: 'nura' },
        { measureId: 'M10', districtId: 'nura' },
        { measureId: 'M12', districtId: null },
    ];

    assert.equal(scenarioCost(overspent), 105);
    assert.ok(
        validateDecisions(overspent).includes('Бюджет превышает 100 у. е.'),
    );
    assert.equal(calculateScenario(overspent), null);
    assert.ok(
        validateDecisions(
            [exampleDecisions[0], exampleDecisions[0]],
            false,
        ).some((message) => message.includes('повторно')),
    );
    assert.ok(
        validateDecisions(
            [{ measureId: 'M999', districtId: 'nura' }],
            false,
        ).includes('Неизвестное мероприятие.'),
    );
});

await test('enforces district and city targeting and the maximum per direction', () => {
    assert.ok(
        validateDecisions([{ measureId: 'M1', districtId: null }], false).some(
            (message) => message.includes('Выберите район'),
        ),
    );
    assert.ok(
        validateDecisions(
            [{ measureId: 'M1', districtId: 'missing' }],
            false,
        ).some((message) => message.includes('Выберите район')),
    );
    assert.ok(
        validateDecisions(
            [{ measureId: 'M12', districtId: 'nura' }],
            false,
        ).some((message) => message.includes('всему городу')),
    );
    assert.ok(
        validateDecisions(
            [
                { measureId: 'M7', districtId: 'nura' },
                { measureId: 'M8', districtId: 'nura' },
                { measureId: 'M9', districtId: 'esil' },
            ],
            false,
        ).some((message) => message.includes('Не более 2 мер')),
    );
});

await test('blocks transport incompatibility even in different districts and local conflicts only within one district', () => {
    assert.ok(
        validateDecisions(
            [
                { measureId: 'M1', districtId: 'nura' },
                { measureId: 'M3', districtId: 'esil' },
            ],
            false,
        ).some((message) => message.includes('несовместимы')),
    );
    for (const [first, second] of [
        ['M4', 'M7'],
        ['M5', 'M13'],
    ]) {
        assert.equal(
            validateDecisions(
                [
                    { measureId: first, districtId: 'nura' },
                    { measureId: second, districtId: 'nura' },
                ],
                false,
            ).length,
            1,
        );
        assert.deepEqual(
            validateDecisions(
                [
                    { measureId: first, districtId: 'nura' },
                    { measureId: second, districtId: 'esil' },
                ],
                false,
            ),
            [],
        );
    }
});

await test('does not depend on decision order or mutate the original dataset', () => {
    const original = JSON.stringify(baseline);
    const result = calculateScenario([...exampleDecisions].reverse());

    assert.equal(result.score, calculateScenario(exampleDecisions).score);
    assert.equal(JSON.stringify(baseline), original);
});

await test('gives local explanations from current results and refuses incomplete scenarios', () => {
    assert.match(
        explainScenario('Бюджет', exampleDecisions, 'nura'),
        /79 из 100/,
    );
    assert.match(
        explainScenario('Риски', exampleDecisions, 'nura'),
        /школы и детсады: 38,00/,
    );
    assert.match(
        explainScenario('Синергии', exampleDecisions, 'nura'),
        /Освещение \+ обращения/,
    );
    assert.match(
        explainScenario('Сроки', exampleDecisions, 'nura'),
        /8 кварталов/,
    );
    assert.match(
        explainScenario('Что изменилось?', exampleDecisions, 'nura'),
        /55,55/,
    );
    assert.match(explainScenario('Бюджет', [], 'nura'), /ровно 5 решений/);
});

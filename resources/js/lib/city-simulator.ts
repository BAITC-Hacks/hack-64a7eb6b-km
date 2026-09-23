export const indicatorKeys = [
    'T1',
    'T2',
    'E1',
    'E2',
    'S1',
    'S2',
    'B1',
    'B2',
    'C1',
    'C2',
] as const;
export type Indicator = (typeof indicatorKeys)[number];
export type DistrictId = 'esil' | 'almaty' | 'saryarka' | 'baikonur' | 'nura';
export type Direction =
    | 'transport'
    | 'ecology'
    | 'social'
    | 'safety'
    | 'services';
export type MeasureId = `M${number}`;
export type Decision = { measureId: MeasureId; districtId: DistrictId | null };
export type Indicators = Record<Indicator, number>;

export const budget = 100;
export const decisionLimit = 5;
export const horizon = 8;

export const directions: Record<Direction, string> = {
    transport: 'Транспорт',
    ecology: 'Озеленение',
    social: 'Соцсфера',
    safety: 'Безопасность',
    services: 'Городской сервис',
};

export const indicatorNames: Record<Indicator, string> = {
    T1: 'Разгрузка дорог',
    T2: 'Общественный транспорт',
    E1: 'Озеленение',
    E2: 'Качество воздуха',
    S1: 'Школы и детсады',
    S2: 'Медпомощь',
    B1: 'Безопасность улиц',
    B2: 'Безопасность движения',
    C1: 'Надёжность ЖКХ',
    C2: 'Решение обращений',
};

const weights: Indicators = {
    T1: 0.1,
    T2: 0.1,
    E1: 0.09,
    E2: 0.11,
    S1: 0.11,
    S2: 0.11,
    B1: 0.09,
    B2: 0.09,
    C1: 0.1,
    C2: 0.1,
};

type District = {
    id: DistrictId;
    name: string;
    population: number;
    indicators: Indicators;
    description: string;
};

export const districts: District[] = [
    {
        id: 'esil',
        name: 'Есиль',
        population: 0.27,
        indicators: {
            T1: 45,
            T2: 62,
            E1: 68,
            E2: 72,
            S1: 48,
            S2: 55,
            B1: 78,
            B2: 60,
            C1: 75,
            C2: 70,
        },
        description: 'Пробки на мостах и переполненные школы.',
    },
    {
        id: 'almaty',
        name: 'Алматы',
        population: 0.24,
        indicators: {
            T1: 40,
            T2: 75,
            E1: 50,
            E2: 55,
            S1: 60,
            S2: 65,
            B1: 62,
            B2: 52,
            C1: 50,
            C2: 60,
        },
        description: 'Нагрузка на дороги и изношенные сети ЖКХ.',
    },
    {
        id: 'saryarka',
        name: 'Сарыарка',
        population: 0.2,
        indicators: {
            T1: 50,
            T2: 70,
            E1: 42,
            E2: 40,
            S1: 62,
            S2: 68,
            B1: 58,
            B2: 55,
            C1: 45,
            C2: 55,
        },
        description: 'Смог от частного сектора и недостаток зелени.',
    },
    {
        id: 'baikonur',
        name: 'Байконур',
        population: 0.13,
        indicators: {
            T1: 52,
            T2: 68,
            E1: 55,
            E2: 50,
            S1: 58,
            S2: 60,
            B1: 52,
            B2: 58,
            C1: 55,
            C2: 58,
        },
        description: 'Сбалансированные показатели с запасом для роста.',
    },
    {
        id: 'nura',
        name: 'Нура',
        population: 0.16,
        indicators: {
            T1: 55,
            T2: 40,
            E1: 45,
            E2: 65,
            S1: 38,
            S2: 35,
            B1: 55,
            B2: 50,
            C1: 60,
            C2: 50,
        },
        description: 'Приоритет — социальная инфраструктура и транспорт.',
    },
];

export type Measure = {
    id: MeasureId;
    name: string;
    direction: Direction;
    scope: 'district' | 'city';
    cost: number;
    lag: number;
    effects: Partial<Indicators>;
};

export const measures: Measure[] = [
    {
        id: 'M1',
        name: 'Автобусные полосы',
        direction: 'transport',
        scope: 'district',
        cost: 18,
        lag: 2,
        effects: { T1: 6, T2: 9 },
    },
    {
        id: 'M2',
        name: 'Умные светофоры',
        direction: 'transport',
        scope: 'city',
        cost: 22,
        lag: 2,
        effects: { T1: 4, B2: 3 },
    },
    {
        id: 'M3',
        name: 'Линия ЛРТ / расширение',
        direction: 'transport',
        scope: 'district',
        cost: 30,
        lag: 4,
        effects: { T1: 16, T2: 20, E2: 4 },
    },
    {
        id: 'M4',
        name: 'Парк / сквер',
        direction: 'ecology',
        scope: 'district',
        cost: 15,
        lag: 2,
        effects: { E1: 12, E2: 3, B1: 2 },
    },
    {
        id: 'M5',
        name: 'Переход на чистое топливо',
        direction: 'ecology',
        scope: 'district',
        cost: 25,
        lag: 3,
        effects: { E2: 14, C1: 4 },
    },
    {
        id: 'M6',
        name: 'Городская программа озеленения',
        direction: 'ecology',
        scope: 'city',
        cost: 20,
        lag: 4,
        effects: { E1: 5, E2: 3 },
    },
    {
        id: 'M7',
        name: 'Школа и детсад',
        direction: 'social',
        scope: 'district',
        cost: 24,
        lag: 3,
        effects: { S1: 16 },
    },
    {
        id: 'M8',
        name: 'Поликлиника',
        direction: 'social',
        scope: 'district',
        cost: 20,
        lag: 3,
        effects: { S2: 14 },
    },
    {
        id: 'M9',
        name: 'Дворовые спорт-хабы',
        direction: 'social',
        scope: 'district',
        cost: 10,
        lag: 1,
        effects: { S1: 3, S2: 3, B1: 3 },
    },
    {
        id: 'M10',
        name: 'Освещение и камеры',
        direction: 'safety',
        scope: 'district',
        cost: 12,
        lag: 1,
        effects: { B1: 12, B2: 2 },
    },
    {
        id: 'M11',
        name: 'Безопасные переходы и школьные зоны',
        direction: 'safety',
        scope: 'district',
        cost: 10,
        lag: 1,
        effects: { B2: 12, T1: -2 },
    },
    {
        id: 'M12',
        name: 'Платформа обращений',
        direction: 'services',
        scope: 'city',
        cost: 14,
        lag: 1,
        effects: { C2: 5 },
    },
    {
        id: 'M13',
        name: 'Модернизация тепло- и водосетей',
        direction: 'services',
        scope: 'district',
        cost: 28,
        lag: 4,
        effects: { C1: 18, E2: 2 },
    },
    {
        id: 'M14',
        name: 'Аварийные бригады ЖКХ',
        direction: 'services',
        scope: 'city',
        cost: 16,
        lag: 1,
        effects: { C1: 5, C2: 2 },
    },
];

export const exampleDecisions: Decision[] = [
    { measureId: 'M1', districtId: 'nura' },
    { measureId: 'M4', districtId: 'saryarka' },
    { measureId: 'M8', districtId: 'nura' },
    { measureId: 'M10', districtId: 'nura' },
    { measureId: 'M12', districtId: null },
];

export function getMeasure(id: MeasureId): Measure {
    const measure = measures.find((item) => item.id === id);
    if (!measure) throw new Error('Неизвестное мероприятие.');
    return measure;
}

export function getDistrict(id: DistrictId): District {
    return districts.find((district) => district.id === id)!;
}

export function scenarioCost(decisions: Decision[]): number {
    return decisions.reduce(
        (total, decision) => total + getMeasure(decision.measureId).cost,
        0,
    );
}

export function validateDecisions(
    decisions: Decision[],
    requireComplete = true,
): string[] {
    const errors: string[] = [];
    if (decisions.length > decisionLimit) {
        errors.push('Уже выбрано 5 решений. Замените или удалите одно из них.');
    } else if (requireComplete && decisions.length !== decisionLimit) {
        errors.push('Выберите ровно 5 решений.');
    }
    if (
        decisions.some(
            (decision) =>
                !measures.some((measure) => measure.id === decision.measureId),
        )
    ) {
        return [...errors, 'Неизвестное мероприятие.'];
    }
    if (scenarioCost(decisions) > budget)
        errors.push('Бюджет превышает 100 у. е.');
    if (
        new Set(decisions.map((decision) => decision.measureId)).size !==
        decisions.length
    ) {
        errors.push('Одно мероприятие нельзя выбирать повторно.');
    }
    for (const direction of Object.keys(directions) as Direction[]) {
        if (
            decisions.filter(
                (decision) =>
                    getMeasure(decision.measureId).direction === direction,
            ).length > 2
        ) {
            errors.push(
                `Не более 2 мер в направлении «${directions[direction]}».`,
            );
        }
    }
    for (const decision of decisions) {
        const measure = getMeasure(decision.measureId);
        if (
            measure.scope === 'district' &&
            !districts.some((district) => district.id === decision.districtId)
        ) {
            errors.push(`Выберите район для меры «${measure.name}».`);
        }
        if (measure.scope === 'city' && decision.districtId !== null) {
            errors.push(`Мера «${measure.name}» применяется ко всему городу.`);
        }
    }
    const find = (id: MeasureId) =>
        decisions.find((decision) => decision.measureId === id);
    if (find('M1') && find('M3'))
        errors.push('Автобусные полосы и ЛРТ несовместимы в любом районе.');
    for (const [first, second, message] of [
        ['M4', 'M7', 'Парк и школа конфликтуют за участок в одном районе.'],
        [
            'M5',
            'M13',
            'Чистое топливо и модернизация сетей дублируют программу в одном районе.',
        ],
    ] as const) {
        if (
            find(first) &&
            find(second) &&
            find(first)?.districtId === find(second)?.districtId
        )
            errors.push(message);
    }
    return errors;
}

export type DistrictResult = District & {
    score: number;
    critical: Indicator[];
};
export type ModelResult = {
    districts: DistrictResult[];
    average: number;
    criticalCount: number;
    score: number;
    synergies: string[];
};

function model(decisions: Decision[]): ModelResult {
    const next = districts.map((district) => ({
        ...district,
        indicators: { ...district.indicators },
    }));
    for (const decision of decisions) {
        const measure = getMeasure(decision.measureId);
        for (const district of next) {
            if (
                measure.scope === 'district' &&
                district.id !== decision.districtId
            )
                continue;
            for (const indicator of indicatorKeys) {
                district.indicators[indicator] +=
                    ((measure.effects[indicator] ?? 0) *
                        (horizon - measure.lag)) /
                    horizon;
            }
        }
    }
    const synergies: string[] = [];
    for (const [first, second, indicator, title] of [
        ['M1', 'M2', 'T1', 'Полосы + светофоры'],
        ['M10', 'M12', 'B1', 'Освещение + обращения'],
        ['M5', 'M6', 'E2', 'Чистое топливо + озеленение'],
    ] as const) {
        const source = decisions.find(
            (decision) => decision.measureId === first,
        );
        if (
            !source ||
            !decisions.some((decision) => decision.measureId === second)
        )
            continue;
        const district = next.find((item) => item.id === source.districtId);
        if (district) {
            district.indicators[indicator] += 2;
            synergies.push(
                `${title}: +2 к показателю «${indicatorNames[indicator]}», ${district.name}.`,
            );
        }
    }
    const results = next.map((district) => {
        for (const indicator of indicatorKeys)
            district.indicators[indicator] = Math.min(
                100,
                Math.max(0, district.indicators[indicator]),
            );
        return {
            ...district,
            score: indicatorKeys.reduce(
                (sum, key) => sum + weights[key] * district.indicators[key],
                0,
            ),
            critical: indicatorKeys.filter(
                (key) => district.indicators[key] < 40,
            ),
        };
    });
    const average = results.reduce(
        (sum, district) => sum + district.population * district.score,
        0,
    );
    const criticalCount = results.reduce(
        (sum, district) => sum + district.critical.length,
        0,
    );
    return {
        districts: results,
        average,
        criticalCount,
        synergies,
        score:
            0.7 * average +
            0.3 * Math.min(...results.map((district) => district.score)) -
            criticalCount,
    };
}

export const baseline = model([]);

export function calculateScenario(decisions: Decision[]): ModelResult | null {
    return validateDecisions(decisions).length === 0 ? model(decisions) : null;
}

export function formatNumber(value: number, digits = 2): string {
    return value.toLocaleString('ru-RU', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

export function explainScenario(
    question: string,
    decisions: Decision[],
    selectedDistrict: DistrictId,
): string {
    const result = calculateScenario(decisions);
    if (!result)
        return `Сначала соберите допустимый сценарий: ${validateDecisions(decisions).join(' ')} Тогда я смогу объяснить его результат.`;
    const district = result.districts.find(
        (item) => item.id === selectedDistrict,
    )!;
    const critical = result.districts.flatMap((item) =>
        item.critical.map(
            (key) =>
                `${item.name} — ${indicatorNames[key].toLowerCase()}: ${formatNumber(item.indicators[key])}`,
        ),
    );
    if (/бюджет|стоим|остат|деньг/iu.test(question)) {
        return `Вы распределили ${scenarioCost(decisions)} из 100 у. е. Осталось ${budget - scenarioCost(decisions)} у. е. Остаток не даёт бонуса. В сценарии должно быть ровно 5 решений: для новой меры замените одну из выбранных.`;
    }
    if (/улучш|совет|риск|критич|компромисс/iu.test(question)) {
        return critical.length
            ? `Остались критические показатели: ${critical.join('; ')}. Значения ниже 40 дают штраф по 1 баллу. Попробуйте заменить одну из мер так, чтобы улучшить эти показатели. Сравните новый Score и стоимость: повышение одного показателя может уменьшить эффект в другом направлении.`
            : 'Критических показателей нет. Сравните альтернативы для района с самым низким индексом: его результат составляет 30% итоговой оценки. При замене мер учитывайте сроки эффекта и синергии.';
    }
    if (/синерг/iu.test(question))
        return result.synergies.length
            ? result.synergies.join('\n')
            : 'В этом наборе синергий нет. Бонус +2 доступен для сочетаний M1 + M2, M10 + M12 и M5 + M6 и применяется в районе первой меры.';
    if (/срок|квартал|горизонт/iu.test(question))
        return 'Горизонт модели — 8 кварталов. Мера с лагом 2 квартала реализует 75% полного эффекта, с лагом 3 — 62,5%. Синергии прибавляются целиком. Эти сроки уже учтены в показателях.';
    return `В выбранном районе ${district.name} индекс меняется с ${formatNumber(baseline.districts.find((item) => item.id === district.id)!.score)} до ${formatNumber(district.score)}. Итог города — ${formatNumber(result.score)}: 70% среднего по населению, 30% индекса самого слабого района, затем штраф за ${result.criticalCount} критических показателей. Это локальное объяснение расчёта; можно спросить о бюджете, рисках, синергиях или сроках.`;
}

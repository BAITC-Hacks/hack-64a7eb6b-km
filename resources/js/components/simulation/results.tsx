import type { ReactNode } from 'react';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    Building2,
    CircleHelp,
    Minus,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import type { Dataset, Selection, SimulationResult } from '@/types/simulation';

export function number(value: string | number, digits = 2) {
    return Number(value).toLocaleString('ru-RU', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

export function delta(value: string | number) {
    return `${Number(value) > 0 ? '+' : ''}${number(value)}`;
}

export function PageHeading({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-5">
            <div>
                <p className="mb-3 flex items-center gap-2 text-xs font-semibold tracking-widest text-emerald-700 uppercase dark:text-emerald-400">
                    <Building2 className="size-4" /> Аким на 5 часов
                </p>
                <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">
                    {title}
                </h1>
                <p className="mt-3 max-w-2xl text-sm leading-6 text-muted-foreground">
                    {description}
                </p>
            </div>
            <div className="flex flex-wrap gap-2">{children}</div>
        </header>
    );
}

export function ScoreCards({
    result,
    baseline,
}: {
    result: SimulationResult;
    baseline?: SimulationResult;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <section className="rounded-2xl bg-emerald-950 p-5 text-white">
                <p className="text-xs text-emerald-200">
                    Astana Quality of Life Score
                </p>
                <div className="mt-3 flex items-baseline gap-3">
                    <strong className="text-4xl font-medium tabular-nums">
                        {number(result.score)}
                    </strong>
                    {baseline && (
                        <span
                            className={`flex items-center text-sm ${Number(result.score) < Number(baseline.score) ? 'text-rose-200' : 'text-emerald-200'}`}
                        >
                            {Number(result.score) < Number(baseline.score) ? (
                                <ArrowDownRight className="size-4" />
                            ) : (
                                <ArrowUpRight className="size-4" />
                            )}
                            {delta(
                                Number(result.score) - Number(baseline.score),
                            )}
                        </span>
                    )}
                </div>
                <p className="mt-3 text-xs text-emerald-200">
                    Качество жизни по модели
                </p>
            </section>
            <Metric
                label="Распределено"
                value={`${result.cost} / 100`}
                note={`Осталось ${result.remaining} у. е.`}
            />
            <Metric
                label="Самый слабый район"
                value={number(result.minimum)}
                note="30% итоговой оценки"
            />
            <Metric
                label="Критические показатели"
                value={String(result.critical.length)}
                note="Значение ниже 40: −1 балл за каждое"
            />
        </div>
    );
}

function Metric({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note: string;
}) {
    return (
        <section className="rounded-2xl border bg-card p-5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-3 text-3xl font-medium tabular-nums">{value}</p>
            <p className="mt-3 text-xs text-muted-foreground">{note}</p>
        </section>
    );
}

export function DistrictScore({
    score,
    baselineScore,
}: {
    score: string | number;
    baselineScore?: string | number;
}) {
    const change =
        baselineScore === undefined
            ? null
            : Number((Number(score) - Number(baselineScore)).toFixed(2));
    const ChangeIcon =
        change === null || change === 0
            ? Minus
            : change > 0
              ? ArrowUpRight
              : ArrowDownRight;
    const changeLabel =
        change === null || change === 0
            ? 'Без изменений'
            : change > 0
              ? 'Рост'
              : 'Снижение';
    const changeClasses =
        change === null || change === 0
            ? 'border-border bg-muted text-muted-foreground'
            : change > 0
              ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
              : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-300';

    return (
        <section
            className="rounded-xl border bg-muted/20 p-4"
            aria-label="Индекс района"
            aria-live="polite"
        >
            <h3 className="text-xs font-medium text-muted-foreground">
                Индекс района
            </h3>
            <div className="mt-3 flex flex-wrap items-end justify-between gap-4">
                <div className="flex items-center gap-4">
                    {baselineScore !== undefined && (
                        <>
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    Было
                                </p>
                                <p className="mt-1 text-xl text-muted-foreground tabular-nums">
                                    {number(baselineScore)}
                                </p>
                            </div>
                            <ArrowRight
                                className="mt-4 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </>
                    )}
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {baselineScore === undefined
                                ? 'Текущее значение'
                                : 'Стало'}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {number(score)}
                        </p>
                    </div>
                </div>
                {change !== null && (
                    <Badge
                        variant="outline"
                        className={`gap-1.5 px-2.5 py-1 ${changeClasses}`}
                    >
                        <ChangeIcon aria-hidden="true" />
                        {changeLabel}
                        {change !== 0 && (
                            <span className="tabular-nums">
                                {delta(change)}
                            </span>
                        )}
                    </Badge>
                )}
            </div>
        </section>
    );
}

export function DistrictCharts({
    result,
    baseline,
}: {
    result: SimulationResult;
    baseline?: SimulationResult;
}) {
    return (
        <section className="rounded-2xl border bg-card p-6">
            <h2 className="text-lg font-semibold">Пять районов — один город</h2>
            <p className="mt-1 text-sm text-muted-foreground">
                Оценки районов по шкале от 0 до 100.
            </p>
            <div className="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-5">
                {result.districts.map((district) => {
                    const isLowScore = Number(district.score) < 50;
                    const before = baseline?.districts.find(
                        (item) => item.id === district.id,
                    );
                    return (
                        <article key={district.id}>
                            <div className="flex items-baseline justify-between gap-2">
                                <h3 className="font-medium">{district.name}</h3>
                                <span
                                    className={`text-sm font-semibold tabular-nums ${isLowScore ? 'text-yellow-700 dark:text-yellow-400' : ''}`}
                                >
                                    {number(district.score)}
                                </span>
                            </div>
                            <div
                                className="relative my-3 h-2 overflow-hidden rounded-full bg-muted"
                                role="meter"
                                aria-label={`Оценка района ${district.name}`}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={Number(district.score)}
                            >
                                <div
                                    className={`h-full rounded-full ${isLowScore ? 'bg-yellow-500' : 'bg-emerald-600'}`}
                                    style={{ width: `${district.score}%` }}
                                />
                            </div>
                            {before ? (
                                <p className="text-xs text-muted-foreground">
                                    Было {number(before.score)} ·{' '}
                                    <span
                                        className={
                                            Number(district.score) <
                                            Number(before.score)
                                                ? 'text-rose-700 dark:text-rose-400'
                                                : 'text-emerald-700 dark:text-emerald-400'
                                        }
                                    >
                                        {delta(
                                            Number(district.score) -
                                                Number(before.score),
                                        )}
                                    </span>
                                </p>
                            ) : (
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {district.description}
                                </p>
                            )}
                        </article>
                    );
                })}
            </div>
        </section>
    );
}

export function IndicatorMatrix({
    dataset,
    result,
    baseline,
}: {
    dataset: Dataset;
    result: SimulationResult;
    baseline?: SimulationResult;
}) {
    return (
        <section className="overflow-hidden rounded-2xl border bg-card">
            <div className="p-6">
                <h2 className="text-lg font-semibold">
                    Что меняется в районах
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Чем выше, тем лучше. Красным отмечены значения ниже 40.
                    {baseline && ' Под значением — изменение к базе.'}
                </p>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-215 text-left text-sm">
                    <caption className="sr-only">
                        Показатели качества жизни по районам
                    </caption>
                    <thead className="bg-muted/50">
                        <tr>
                            <th className="px-6 py-3">Район</th>
                            {Object.entries(dataset.indicators).map(
                                ([key, indicator]) => (
                                    <th
                                        key={key}
                                        className="px-3 py-3 text-center"
                                    >
                                        <abbr
                                            title={indicator.name}
                                            className="cursor-help no-underline"
                                        >
                                            {key}
                                        </abbr>
                                    </th>
                                ),
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {result.districts.map((district) => (
                            <tr key={district.id} className="border-t">
                                <th className="px-6 py-4 font-medium">
                                    {district.name}
                                </th>
                                {Object.entries(dataset.indicators).map(
                                    ([key]) => {
                                        const value = district.indicators[key];
                                        const before = baseline?.districts.find(
                                            (item) => item.id === district.id,
                                        )?.indicators[key];
                                        const change =
                                            before === undefined
                                                ? 0
                                                : Number(value) -
                                                  Number(before);
                                        return (
                                            <td
                                                key={key}
                                                className={`px-3 py-4 text-center tabular-nums ${Number(value) < 40 ? 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300' : ''}`}
                                            >
                                                <span>{number(value, 1)}</span>
                                                {before !== undefined && (
                                                    <span
                                                        className={`mt-1 block text-[10px] ${change < 0 ? 'text-rose-600' : 'text-muted-foreground'}`}
                                                    >
                                                        {change === 0
                                                            ? '—'
                                                            : delta(change)}
                                                    </span>
                                                )}
                                            </td>
                                        );
                                    },
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex flex-wrap gap-x-5 gap-y-2 border-t p-5">
                {Object.entries(dataset.indicators).map(([key, indicator]) => (
                    <span key={key} className="text-xs text-muted-foreground">
                        <strong className="text-foreground">{key}</strong>{' '}
                        {indicator.name}
                    </span>
                ))}
            </div>
        </section>
    );
}

export function DecisionList({
    dataset,
    selections,
}: {
    dataset: Dataset;
    selections: Selection[];
}) {
    return (
        <ol className="divide-y">
            {selections.map((selection) => {
                const measure = dataset.measures.find(
                    (item) => item.id === selection.measure_id,
                );
                return (
                    <li
                        key={selection.measure_id}
                        className="flex items-center justify-between gap-4 py-3 text-sm"
                    >
                        <div>
                            <span className="mr-2 font-mono text-xs text-muted-foreground">
                                {selection.measure_id}
                            </span>
                            {measure?.name}
                            <p className="mt-1 text-xs text-muted-foreground">
                                {selection.district_id
                                    ? dataset.districts.find(
                                          (district) =>
                                              district.id ===
                                              selection.district_id,
                                      )?.name
                                    : 'Весь город'}
                            </p>
                        </div>
                        <Badge variant="outline">{measure?.cost} у. е.</Badge>
                    </li>
                );
            })}
        </ol>
    );
}

export function MethodNote() {
    return (
        <p className="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
            <CircleHelp className="mt-0.5 size-4 shrink-0" />
            Синтетическая модель, горизонт — 8 кварталов. Score = 70% среднего
            по населению + 30% худшего района − штрафы за показатели ниже 40.
            Это учебная симуляция.
        </p>
    );
}

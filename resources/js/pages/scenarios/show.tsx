import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Copy, Map } from 'lucide-react';
import { ScenarioAssistant } from '@/components/simulation/scenario-assistant';
import { map } from '@/routes';
import {
    DecisionList,
    DistrictCharts,
    IndicatorMatrix,
    MethodNote,
    PageHeading,
    ScoreCards,
    delta,
    number,
} from '@/components/simulation/results';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { compare, create, index } from '@/routes/scenarios';
import type {
    Dataset,
    Scenario,
    ScenarioApproval,
    ScenarioRun,
    SimulationResult,
} from '@/types/simulation';

export default function ShowScenario({
    scenario,
    dataset,
    baseline,
    runs,
    approvals,
    can,
}: {
    scenario: Scenario;
    dataset: Dataset;
    baseline: SimulationResult;
    runs: ScenarioRun[];
    approvals: ScenarioApproval[];
    can: {
        create: boolean;
        analyze: boolean;
        cancel: boolean;
        approve: boolean;
    };
}) {
    return (
        <>
            <Head title={scenario.title} />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-7 p-5 lg:p-9">
                <Link
                    href={index()}
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Все сценарии
                </Link>
                <PageHeading
                    title={scenario.title}
                    description="Последствия пяти решений через восемь кварталов. Числа рассчитаны по модели; AI помогает объяснить их и проверить новые идеи."
                >
                    <Button asChild variant="outline">
                        <Link href={map({ query: { scenario: scenario.id } })}>
                            <Map className="size-4" /> На карте
                        </Link>
                    </Button>
                    {can.create && (
                        <Button asChild variant="outline">
                            <Link
                                href={create({
                                    query: { source: scenario.id },
                                })}
                            >
                                <Copy className="size-4" />
                                Создать копию
                            </Link>
                        </Button>
                    )}
                    {scenario.source_scenario_id && (
                        <Button asChild variant="outline">
                            <Link
                                href={compare({
                                    query: {
                                        left: scenario.source_scenario_id,
                                        right: scenario.id,
                                    },
                                })}
                            >
                                Сравнить с исходным
                            </Link>
                        </Button>
                    )}
                </PageHeading>
                <ScoreCards result={scenario.result} baseline={baseline} />
                <DistrictCharts result={scenario.result} baseline={baseline} />
                <IndicatorMatrix
                    dataset={dataset}
                    result={scenario.result}
                    baseline={baseline}
                />
                <div className="grid items-start gap-6 lg:grid-cols-2">
                    <section className="rounded-2xl border bg-card p-6">
                        <h2 className="text-lg font-semibold">
                            Принятые решения
                        </h2>
                        <DecisionList
                            dataset={dataset}
                            selections={scenario.selections}
                        />
                        <details className="mt-5 border-t pt-4 text-sm">
                            <summary className="cursor-pointer font-medium">
                                Из чего складывается результат
                            </summary>
                            <p className="mt-4 leading-6">
                                Среднее по населению:{' '}
                                {number(scenario.result.average, 5)}. Худший
                                район: {number(scenario.result.minimum, 5)}.
                                Штраф: {scenario.result.critical.length}. Итог:{' '}
                                {number(scenario.result.score, 5)}.
                            </p>
                            <h3 className="mt-4 font-medium">Синергии</h3>
                            {scenario.result.synergies.length ? (
                                <ul className="mt-2 space-y-2">
                                    {scenario.result.synergies.map(
                                        (synergy, i) => (
                                            <li key={i}>
                                                {synergy.measures.join(' + ')} ·{' '}
                                                {
                                                    dataset.districts.find(
                                                        (item) =>
                                                            item.id ===
                                                            synergy.district_id,
                                                    )?.name
                                                }
                                                : {synergy.indicator}{' '}
                                                {delta(synergy.delta)}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            ) : (
                                <p className="mt-2 text-muted-foreground">
                                    В этой комбинации нет синергий.
                                </p>
                            )}
                            {scenario.result.clipping.length > 0 && (
                                <p className="mt-3">
                                    Ограничение шкалы 0–100 применено к{' '}
                                    {scenario.result.clipping.length}{' '}
                                    показателям.
                                </p>
                            )}
                            <p className="mt-4 text-xs text-muted-foreground">
                                Данные: {dataset.version} · Расчёт:{' '}
                                {scenario.calculator_version}
                            </p>
                        </details>
                    </section>
                    <section className="rounded-2xl border bg-card p-6">
                        <h2 className="text-lg font-semibold">
                            Проверенные улучшения
                        </h2>
                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                            До трёх лучших допустимых замен одной меры или её
                            района. Каждый вариант уже пересчитан.
                        </p>
                        <div className="mt-4 space-y-3">
                            {scenario.alternatives.map((alternative, i) => (
                                <article
                                    key={alternative.id}
                                    className="rounded-xl bg-muted/40 p-4"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-sm font-medium">
                                            Вариант {i + 1}
                                        </span>
                                        <Badge variant="secondary">
                                            Score {delta(alternative.delta)}
                                        </Badge>
                                    </div>
                                    <p className="my-3 text-sm leading-6">
                                        {alternative.removed.measure_id} (
                                        {dataset.districts.find(
                                            (d) =>
                                                d.id ===
                                                alternative.removed.district_id,
                                        )?.name ?? 'весь город'}
                                        ) → {alternative.added.measure_id}:{' '}
                                        {
                                            dataset.measures.find(
                                                (m) =>
                                                    m.id ===
                                                    alternative.added
                                                        .measure_id,
                                            )?.name
                                        }{' '}
                                        (
                                        {dataset.districts.find(
                                            (d) =>
                                                d.id ===
                                                alternative.added.district_id,
                                        )?.name ?? 'весь город'}
                                        )
                                    </p>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-xs text-muted-foreground">
                                            {alternative.result.cost} у. е. ·
                                            Score{' '}
                                            {number(
                                                alternative.result.score,
                                                5,
                                            )}
                                        </span>
                                        {can.create && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                asChild
                                            >
                                                <Link
                                                    href={create({
                                                        query: {
                                                            source: scenario.id,
                                                            alternative: i,
                                                        },
                                                    })}
                                                >
                                                    Открыть в конструкторе
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </article>
                            ))}
                            {!scenario.alternatives.length && (
                                <p className="py-4 text-sm text-muted-foreground">
                                    Замена одной меры не улучшает Score. Можно
                                    проверить другую комбинацию в конструкторе.
                                </p>
                            )}
                        </div>
                    </section>
                </div>
                <ScenarioAssistant
                    key={scenario.id}
                    scenario={scenario}
                    dataset={dataset}
                    runs={runs}
                    approvals={approvals}
                    can={can}
                />
                <MethodNote />
            </main>
        </>
    );
}
ShowScenario.layout = {
    breadcrumbs: [{ title: 'Город и сценарии', href: index() }],
};

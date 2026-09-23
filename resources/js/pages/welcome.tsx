import { Head, Link, router } from '@inertiajs/react';
import { Copy, LayoutGrid } from 'lucide-react';
import { useState } from 'react';
import {
    DecisionList,
    DistrictScore,
    MethodNote,
    delta,
    number,
} from '@/components/simulation/results';
import { ScenarioAssistant } from '@/components/simulation/scenario-assistant';
import { DistrictMap } from '@/components/simulator/district-map';
import {
    ScenarioSummary,
    SimulatorHeader,
    SimulatorRules,
} from '@/components/simulator/simulator-header';
import { Button } from '@/components/ui/button';
import { dashboard, map } from '@/routes';
import { create, show } from '@/routes/scenarios';
import type {
    AiRuntime,
    Dataset,
    Scenario,
    ScenarioApproval,
    ScenarioRun,
    SimulationResult,
} from '@/types/simulation';

type Props = {
    aiRuntime: AiRuntime;
    dataset: Dataset | null;
    baseline: SimulationResult | null;
    scenario: Scenario | null;
    recentScenarios: { id: string; title: string }[];
    runs: ScenarioRun[];
    approvals: ScenarioApproval[];
    can: {
        create: boolean;
        analyze: boolean;
        cancel: boolean;
        approve: boolean;
    };
};

export default function Welcome({
    aiRuntime,
    dataset,
    baseline,
    scenario,
    recentScenarios,
    runs,
    approvals,
    can,
}: Props) {
    const [selectedDistrict, setSelectedDistrict] = useState('');
    const [rulesOpen, setRulesOpen] = useState(false);
    const result = scenario?.result ?? baseline;
    const district =
        result?.districts.find((item) => item.id === selectedDistrict) ??
        result?.districts[0];
    const before = baseline?.districts.find((item) => item.id === district?.id);

    return (
        <>
            <Head
                title={scenario ? scenario.title + ' — карта' : 'Карта Астаны'}
            />
            <main className="simulator min-h-dvh overflow-x-clip bg-background text-foreground">
                <h1 className="sr-only">Карта Астаны и городские сценарии</h1>
                <SimulatorHeader onRules={() => setRulesOpen(true)} />
                <section className="flex flex-wrap items-center justify-end gap-4 border-b px-4 py-4 sm:px-7">
                    {!scenario && (
                        <h2 className="min-w-0 flex-1 font-semibold">
                            Исходное состояние города
                        </h2>
                    )}
                    <Button asChild variant="outline">
                        <Link href={dashboard()}>
                            <LayoutGrid className="size-4" />
                            Все сценарии
                        </Link>
                    </Button>
                    {scenario && can.create && (
                        <Button asChild variant="outline">
                            <Link
                                href={create({
                                    query: { source: scenario.id },
                                })}
                            >
                                <Copy className="size-4" />
                                Создать вариант
                            </Link>
                        </Button>
                    )}
                </section>
                {dataset && result && (
                    <ScenarioSummary
                        cost={result.cost}
                        count={scenario?.selections.length ?? 0}
                        budget={dataset.budget}
                        horizon={dataset.horizon}
                        canCreate={can.create}
                    />
                )}
                <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1.65fr)_minmax(360px,1fr)]">
                    <div className="min-w-0">
                        <DistrictMap
                            selectedDistrict={district?.id ?? ''}
                            onSelectDistrict={setSelectedDistrict}
                            result={result}
                        />
                        <section className="space-y-4 border-t p-4 sm:p-7">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="text-xl font-semibold">
                                    Принятые решения
                                </h2>
                                {scenario && (
                                    <Link
                                        className="text-sm underline"
                                        href={show(scenario.id)}
                                    >
                                        Расчёт и улучшения
                                    </Link>
                                )}
                            </div>
                            {scenario && dataset ? (
                                <DecisionList
                                    dataset={dataset}
                                    selections={scenario.selections}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {dataset
                                        ? 'Сохранённых сценариев пока нет. Создайте первый сценарий, чтобы увидеть решения и их последствия на карте.'
                                        : 'Данные симулятора ещё не подготовлены. Обратитесь к администратору.'}
                                </p>
                            )}
                            <MethodNote />
                        </section>
                    </div>
                    <aside
                        className="min-w-0 space-y-6 border-t bg-card/40 p-4 sm:p-6 xl:border-t-0 xl:border-l"
                        aria-label="Показатели и AI-советник"
                    >
                        {result && baseline && dataset && (
                            <>
                                <section
                                    className="space-y-3 border-b pb-6"
                                    aria-label="Оценка города"
                                >
                                    <h2 className="text-sm text-muted-foreground">
                                        Astana Quality of Life Score
                                    </h2>
                                    <div className="flex items-baseline gap-4">
                                        <strong className="text-4xl tabular-nums">
                                            {number(result.score)}
                                        </strong>
                                        {scenario && (
                                            <span className="text-sm">
                                                {delta(
                                                    Number(result.score) -
                                                        Number(baseline.score),
                                                )}{' '}
                                                к исходному
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {scenario && (
                                            <>
                                                Исходная оценка:{' '}
                                                {number(baseline.score)}.{' '}
                                            </>
                                        )}
                                        Критических показателей:{' '}
                                        {result.critical.length}
                                    </p>
                                </section>
                                {scenario && (
                                    <div className="space-y-4">
                                        <label
                                            htmlFor="map-scenario"
                                            className="block text-sm font-semibold"
                                        >
                                            Сохранённый сценарий
                                        </label>
                                        <select
                                            id="map-scenario"
                                            className="sim-select w-full appearance-auto"
                                            value={scenario.id}
                                            onChange={(event) =>
                                                router.visit(
                                                    map({
                                                        query: {
                                                            scenario:
                                                                event.target
                                                                    .value,
                                                        },
                                                    }),
                                                )
                                            }
                                        >
                                            {recentScenarios.map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.title}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                {district && (
                                    <section
                                        className="space-y-4"
                                        aria-label="Показатели района"
                                    >
                                        <label
                                            className="block text-sm font-semibold"
                                            htmlFor="map-district"
                                        >
                                            Район
                                        </label>
                                        <select
                                            id="map-district"
                                            className="sim-select w-full appearance-auto"
                                            value={district.id}
                                            onChange={(event) =>
                                                setSelectedDistrict(
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {result.districts.map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.name}
                                                </option>
                                            ))}
                                        </select>
                                        <DistrictScore
                                            score={district.score}
                                            baselineScore={
                                                scenario
                                                    ? before?.score
                                                    : undefined
                                            }
                                        />
                                        <dl className="grid gap-2 text-sm">
                                            {Object.entries(
                                                dataset.indicators,
                                            ).map(([key, indicator]) => (
                                                <div
                                                    key={key}
                                                    className="flex justify-between gap-4 border-b border-border/50 py-2"
                                                >
                                                    <dt>{indicator.name}</dt>
                                                    <dd className="shrink-0 font-medium tabular-nums">
                                                        {number(
                                                            district.indicators[
                                                                key
                                                            ],
                                                            1,
                                                        )}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </section>
                                )}
                            </>
                        )}
                        {scenario && dataset ? (
                            <ScenarioAssistant
                                aiRuntime={aiRuntime}
                                key={scenario.id}
                                scenario={scenario}
                                dataset={dataset}
                                runs={runs}
                                approvals={approvals}
                                can={can}
                                returnToMap
                            />
                        ) : (
                            <section className="rounded-xl border p-5">
                                <h2 className="font-semibold">AI-советник</h2>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Разбор и диалог появятся после сохранения
                                    сценария.
                                </p>
                                {can.create && (
                                    <Button className="mt-4" asChild>
                                        <Link href={create()}>
                                            Создать сценарий
                                        </Link>
                                    </Button>
                                )}
                            </section>
                        )}
                    </aside>
                </div>
            </main>
            {dataset && baseline && (
                <SimulatorRules
                    open={rulesOpen}
                    onOpenChange={setRulesOpen}
                    budget={dataset.budget}
                    horizon={dataset.horizon}
                    baselineScore={baseline.score}
                />
            )}
        </>
    );
}

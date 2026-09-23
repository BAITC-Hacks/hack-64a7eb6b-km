import { Head, Link } from '@inertiajs/react';
import { ArrowRight, GitCompareArrows, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    DistrictCharts,
    IndicatorMatrix,
    MethodNote,
    PageHeading,
    ScoreCards,
    number,
} from '@/components/simulation/results';
import { Button } from '@/components/ui/button';
import { index, create, show, compare } from '@/routes/scenarios';
import type { Dataset, Scenario, SimulationResult } from '@/types/simulation';

type Props = {
    dataset: Dataset | null;
    baseline: SimulationResult | null;
    scenarios: {
        data: Scenario[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    can: { create: boolean };
};

export default function Scenarios({
    dataset,
    baseline,
    scenarios,
    can,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);
    return (
        <>
            <Head title="Панель управленца" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-8 p-5 lg:p-9">
                <PageHeading
                    title="Город начинается с решений"
                    description="100 единиц бюджета. Пять решений. Найдите баланс между развитием города и потребностями каждого района."
                >
                    {can.create && dataset && (
                        <Button
                            asChild
                            className="bg-emerald-800 text-white hover:bg-emerald-700"
                        >
                            <Link href={create()}>
                                <Plus className="size-4" />
                                Новый сценарий
                            </Link>
                        </Button>
                    )}
                </PageHeading>
                {dataset && baseline ? (
                    <>
                        <ScoreCards result={baseline} />
                        <DistrictCharts result={baseline} />
                    </>
                ) : (
                    <p className="rounded-xl border p-6">
                        Данные симулятора ещё не подготовлены. Обратитесь к
                        администратору.
                    </p>
                )}
                <section className="rounded-2xl border bg-card p-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-xl font-semibold">
                                Мои сценарии{' '}
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    {scenarios.total}
                                </span>
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Выберите два сценария одной версии для
                                сравнения.
                            </p>
                        </div>
                        {selected.length === 2 && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={compare({
                                        query: {
                                            left: selected[0],
                                            right: selected[1],
                                        },
                                    })}
                                >
                                    <GitCompareArrows className="size-4" />
                                    Сравнить выбранные
                                </Link>
                            </Button>
                        )}
                    </div>
                    {scenarios.data.length === 0 ? (
                        <div className="py-10 text-center">
                            <p className="text-muted-foreground">
                                Первое решение — с чего начать.
                            </p>
                            {can.create && dataset && (
                                <Button asChild variant="link" className="mt-2">
                                    <Link href={create()}>
                                        Собрать первый сценарий{' '}
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="mt-6 divide-y">
                            {scenarios.data.map((scenario) => {
                                const first = scenarios.data.find(
                                    (item) => item.id === selected[0],
                                );
                                const checked = selected.includes(scenario.id);
                                const compatible =
                                    !first ||
                                    (first.simulation_dataset_id ===
                                        scenario.simulation_dataset_id &&
                                        first.calculator_version ===
                                            scenario.calculator_version);
                                return (
                                    <article
                                        key={scenario.id}
                                        className="flex items-center gap-4 py-4"
                                    >
                                        <input
                                            type="checkbox"
                                            aria-label={`Сравнить: ${scenario.title}`}
                                            checked={checked}
                                            disabled={
                                                !checked &&
                                                (selected.length === 2 ||
                                                    !compatible)
                                            }
                                            onChange={() =>
                                                setSelected(
                                                    checked
                                                        ? selected.filter(
                                                              (id) =>
                                                                  id !==
                                                                  scenario.id,
                                                          )
                                                        : [
                                                              ...selected,
                                                              scenario.id,
                                                          ],
                                                )
                                            }
                                            className="size-4 accent-emerald-700"
                                        />
                                        <Link
                                            href={show(scenario.id)}
                                            className="min-w-0 flex-1"
                                        >
                                            <h3 className="truncate font-medium hover:underline">
                                                {scenario.title}
                                            </h3>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {new Date(
                                                    scenario.created_at,
                                                ).toLocaleDateString(
                                                    'ru-RU',
                                                )}{' '}
                                                · {scenario.result.cost} у. е. ·{' '}
                                                {
                                                    scenario.result.critical
                                                        .length
                                                }{' '}
                                                критических показателей
                                            </p>
                                        </Link>
                                        <strong className="text-xl tabular-nums">
                                            {number(scenario.result.score)}
                                        </strong>
                                        <Link
                                            href={show(scenario.id)}
                                            aria-label={`Открыть: ${scenario.title}`}
                                        >
                                            <ArrowRight className="size-4" />
                                        </Link>
                                    </article>
                                );
                            })}
                        </div>
                    )}
                    <div className="mt-4 flex justify-between text-sm">
                        {scenarios.prev_page_url ? (
                            <Link href={scenarios.prev_page_url}>← Назад</Link>
                        ) : (
                            <span />
                        )}
                        {scenarios.next_page_url && (
                            <Link href={scenarios.next_page_url}>Далее →</Link>
                        )}
                    </div>
                </section>
                {dataset && baseline && (
                    <IndicatorMatrix dataset={dataset} result={baseline} />
                )}
                <MethodNote />
            </main>
        </>
    );
}
Scenarios.layout = {
    breadcrumbs: [{ title: 'Город и сценарии', href: index() }],
};

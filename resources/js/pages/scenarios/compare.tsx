import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import {
    DecisionList,
    DistrictCharts,
    IndicatorMatrix,
    MethodNote,
    PageHeading,
    ScoreCards,
    delta,
} from '@/components/simulation/results';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/scenarios';
import type { Dataset, Scenario } from '@/types/simulation';

type Comparison = {
    score: string;
    cost: number;
    minimum: string;
    critical: number;
};
export default function Compare({
    left,
    right,
    dataset,
    comparison,
}: {
    left: Scenario;
    right: Scenario;
    dataset: Dataset;
    comparison: Comparison;
}) {
    return (
        <>
            <Head title="Сравнение сценариев" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-7 p-5 lg:p-9">
                <Link
                    href={index()}
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Все сценарии
                </Link>
                <PageHeading
                    title="Два решения для одного города"
                    description="Изменения показаны для правого сценария относительно левого. Оба используют одну версию данных и формулы."
                />
                <div className="grid gap-5 lg:grid-cols-2">
                    {[left, right].map((scenario, i) => (
                        <section
                            key={`${i}-${scenario.id}`}
                            className="rounded-2xl border bg-card p-6"
                        >
                            <span className="text-xs font-medium text-muted-foreground">
                                {i === 0
                                    ? 'Исходный сценарий'
                                    : 'Сравниваемый сценарий'}
                            </span>
                            <h2 className="mt-2 text-xl font-semibold">
                                {scenario.title}
                            </h2>
                            <DecisionList
                                dataset={dataset}
                                selections={scenario.selections}
                            />
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                className="mt-4"
                            >
                                <Link href={show(scenario.id)}>
                                    Открыть
                                    <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                        </section>
                    ))}
                </div>
                <div className="grid gap-4 rounded-2xl bg-muted/50 p-6 sm:grid-cols-4">
                    {[
                        ['Изменение Score', delta(comparison.score)],
                        [
                            'Изменение расходов',
                            `${comparison.cost > 0 ? '+' : ''}${comparison.cost} у. е.`,
                        ],
                        ['Худший район', delta(comparison.minimum)],
                        [
                            'Критические показатели',
                            `${comparison.critical > 0 ? '+' : ''}${comparison.critical}`,
                        ],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <p className="text-xs text-muted-foreground">
                                {label}
                            </p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">
                                {value}
                            </p>
                        </div>
                    ))}
                </div>
                <ScoreCards result={right.result} baseline={left.result} />
                <DistrictCharts result={right.result} baseline={left.result} />
                <IndicatorMatrix
                    dataset={dataset}
                    result={right.result}
                    baseline={left.result}
                />
                <MethodNote />
            </main>
        </>
    );
}
Compare.layout = {
    breadcrumbs: [{ title: 'Город и сценарии', href: index() }],
};

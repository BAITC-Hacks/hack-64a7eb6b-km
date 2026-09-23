import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Copy,
    Send,
    Sparkles,
    Square,
    X,
} from 'lucide-react';
import { useEffect } from 'react';
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
import { Label } from '@/components/ui/label';
import { update } from '@/routes/approvals';
import { cancel } from '@/routes/runs';
import {
    analysis,
    compare,
    create,
    index,
    messages,
    show,
} from '@/routes/scenarios';
import { statusLabels } from '@/types/agents';
import type {
    Dataset,
    Scenario,
    ScenarioApproval,
    ScenarioRun,
    SimulationResult,
} from '@/types/simulation';

function Proposal({
    approval,
    dataset,
    sourceId,
    allowed,
}: {
    approval: ScenarioApproval;
    dataset: Dataset;
    sourceId: string;
    allowed: boolean;
}) {
    const form = useForm({ decision: 'approve' });
    const enabled = approval.run_status === 'succeeded';
    const decide = (decision: string) => {
        form.transform(() => ({ decision }));
        form.post(update(approval.id).url, { preserveScroll: true });
    };
    return (
        <article className="rounded-xl border border-emerald-600/30 bg-emerald-50/30 p-5 dark:bg-emerald-950/20">
            <div className="flex flex-wrap justify-between gap-2">
                <h3 className="font-semibold">Предложенный вариант</h3>
                <Badge variant="outline">
                    {
                        {
                            pending: 'Ожидает решения',
                            approved: 'Создан',
                            rejected: 'Отклонён',
                        }[approval.status]
                    }
                </Badge>
            </div>
            <p className="mt-3 text-sm">
                Score{' '}
                <strong>{number(approval.arguments.result.score, 5)}</strong> ·
                Бюджет {approval.arguments.result.cost} / 100
            </p>
            <DecisionList
                dataset={dataset}
                selections={approval.arguments.selections}
            />
            {approval.status === 'pending' && (
                <>
                    <p className="my-3 text-xs leading-5 text-muted-foreground">
                        Подтверждение создаст отдельный сценарий с этими пятью
                        решениями и показанным расчётом.
                        {!enabled &&
                            ' Предложение доступно после успешного завершения ответа AI.'}
                    </p>
                    {allowed && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                disabled={!enabled || form.processing}
                                onClick={() => decide('approve')}
                            >
                                <Check className="size-4" />
                                Создать этот вариант
                            </Button>
                            <Button
                                variant="outline"
                                disabled={!enabled || form.processing}
                                onClick={() => decide('reject')}
                            >
                                <X className="size-4" />
                                Отклонить
                            </Button>
                        </div>
                    )}
                </>
            )}
            {approval.scenario_id && (
                <div className="mt-4 flex flex-wrap gap-3">
                    <Button asChild size="sm">
                        <Link href={show(approval.scenario_id)}>
                            Открыть вариант
                            <ArrowRight className="size-4" />
                        </Link>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={compare({
                                query: {
                                    left: sourceId,
                                    right: approval.scenario_id,
                                },
                            })}
                        >
                            Сравнить с исходным
                        </Link>
                    </Button>
                </div>
            )}
            {Object.values(form.errors).map((error, i) => (
                <p
                    role="alert"
                    className="mt-3 text-sm text-destructive"
                    key={i}
                >
                    {error}
                </p>
            ))}
        </article>
    );
}

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
    const active = runs.filter((run) =>
        ['queued', 'running'].includes(run.status),
    );
    const { start, stop } = usePoll(
        2000,
        { only: ['runs', 'approvals'] },
        { autoStart: false },
    );
    useEffect(() => {
        if (active.length) start();
        else stop();
        return stop;
    }, [active.length, start, stop]);
    const analysisForm = useForm({ request_key: crypto.randomUUID() });
    const chatForm = useForm({ input: '', request_key: crypto.randomUUID() });
    const cancelForm = useForm({});
    const report = [...runs]
        .reverse()
        .find(
            (run) =>
                run.kind === 'scenario_analysis' &&
                run.status === 'succeeded' &&
                run.output_data,
        )?.output_data;
    const chats = runs.filter((run) => run.kind === 'scenario_chat');
    const hasAnalysis = active.some((run) => run.kind === 'scenario_analysis');
    const hasChat = active.some((run) => run.kind === 'scenario_chat');
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
                <section className="rounded-2xl border bg-card p-6">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 className="flex items-center gap-2 text-lg font-semibold">
                                <Sparkles className="size-5 text-emerald-600" />
                                Разбор AI-советника
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Объяснение результатов, рисков и компромиссов.
                            </p>
                        </div>
                        {can.analyze && (
                            <Button
                                variant="outline"
                                disabled={
                                    hasAnalysis || analysisForm.processing
                                }
                                onClick={() =>
                                    analysisForm.post(
                                        analysis(scenario.id).url,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                analysisForm.setData(
                                                    'request_key',
                                                    crypto.randomUUID(),
                                                ),
                                        },
                                    )
                                }
                            >
                                {hasAnalysis
                                    ? 'Советник анализирует…'
                                    : report
                                      ? 'Обновить разбор'
                                      : 'Получить разбор'}
                            </Button>
                        )}
                    </div>
                    {report ? (
                        <div className="mt-6 space-y-5">
                            <p className="text-sm leading-7 whitespace-pre-wrap">
                                {report.summary}
                            </p>
                            <div className="grid gap-6 md:grid-cols-3">
                                {(
                                    [
                                        ['Сильные стороны', report.strengths],
                                        ['Риски', report.risks],
                                        ['Компромиссы', report.tradeoffs],
                                    ] as [string, string[]][]
                                ).map(([title, items]) => (
                                    <div key={title}>
                                        <h3 className="mb-3 font-medium">
                                            {title}
                                        </h3>
                                        <ul className="list-disc space-y-2 pl-4 text-sm leading-6 text-muted-foreground">
                                            {items.map((item, i) => (
                                                <li key={i}>{item}</li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                            {report.recommendations.map((item, i) => (
                                <p
                                    key={i}
                                    className="rounded-xl bg-muted/40 p-4 text-sm leading-6"
                                >
                                    Вариант{' '}
                                    {scenario.alternatives.findIndex(
                                        (alternative) =>
                                            alternative.id ===
                                            item.alternative_id,
                                    ) + 1}
                                    : {item.reason}
                                </p>
                            ))}
                        </div>
                    ) : (
                        <p className="mt-6 text-sm text-muted-foreground">
                            Запросите разбор, чтобы обсудить последствия
                            принятых решений.
                        </p>
                    )}
                    {Object.values(analysisForm.errors).map((error, i) => (
                        <p
                            role="alert"
                            key={i}
                            className="mt-3 text-sm text-destructive"
                        >
                            {error}
                        </p>
                    ))}
                    <div aria-live="polite" className="mt-4 space-y-2">
                        {runs
                            .filter((run) => run.kind === 'scenario_analysis')
                            .slice(-1)
                            .filter((run) => run.status !== 'succeeded')
                            .map((run) => (
                                <p
                                    key={run.id}
                                    className="text-sm text-muted-foreground"
                                >
                                    {statusLabels[run.status]}
                                    {run.error && `: ${run.error}`}
                                </p>
                            ))}
                    </div>
                </section>
                <section className="rounded-2xl border bg-card p-6">
                    <h2 className="text-lg font-semibold">Обсудите сценарий</h2>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        Спросите, почему изменилась оценка, или попросите
                        предложить лучший вариант.
                    </p>
                    <div className="my-6 space-y-5" aria-live="polite">
                        {chats.map((run) => (
                            <article key={run.id} className="space-y-3">
                                <p className="ml-auto max-w-3xl rounded-xl bg-muted p-4 text-sm leading-6 whitespace-pre-wrap">
                                    {run.input}
                                </p>
                                <div className="max-w-3xl rounded-xl border p-4">
                                    <span className="text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                                        {run.driver === 'demo'
                                            ? 'Демо-советник'
                                            : 'AI-советник'}
                                    </span>
                                    <p className="mt-2 text-sm leading-7 break-words whitespace-pre-wrap">
                                        {run.output ??
                                            run.error ??
                                            statusLabels[run.status]}
                                    </p>
                                </div>
                            </article>
                        ))}
                        {!chats.length && (
                            <p className="text-sm text-muted-foreground">
                                Например: «Предложи улучшение и подготовь
                                вариант на подтверждение».
                            </p>
                        )}
                    </div>
                    {approvals.length > 0 && (
                        <div className="mb-6 space-y-4">
                            {approvals.map((approval) => (
                                <Proposal
                                    key={approval.id}
                                    approval={approval}
                                    dataset={dataset}
                                    sourceId={scenario.id}
                                    allowed={can.approve}
                                />
                            ))}
                        </div>
                    )}
                    {can.analyze && (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                chatForm.post(messages(scenario.id).url, {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        chatForm.setData({
                                            input: '',
                                            request_key: crypto.randomUUID(),
                                        }),
                                });
                            }}
                            className="space-y-3"
                        >
                            <Label htmlFor="scenario-question">
                                Ваш вопрос
                            </Label>
                            <textarea
                                className="w-full rounded-md border bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-2 focus-visible:outline-ring"
                                id="scenario-question"
                                value={chatForm.data.input}
                                onChange={(event) =>
                                    chatForm.setData(
                                        'input',
                                        event.target.value,
                                    )
                                }
                                maxLength={2000}
                                rows={3}
                                required
                                placeholder="Какие риски остаются у района Нура?"
                            />
                            <div className="flex justify-end">
                                <Button
                                    disabled={
                                        hasChat ||
                                        chatForm.processing ||
                                        !chatForm.data.input.trim()
                                    }
                                >
                                    <Send className="size-4" />
                                    {hasChat
                                        ? 'Советник отвечает…'
                                        : 'Отправить'}
                                </Button>
                            </div>
                            {Object.values(chatForm.errors).map((error, i) => (
                                <p
                                    role="alert"
                                    key={i}
                                    className="text-sm text-destructive"
                                >
                                    {error}
                                </p>
                            ))}
                        </form>
                    )}
                </section>
                {active.length > 0 && (
                    <div className="flex flex-wrap gap-3">
                        {active.map((run) => (
                            <div
                                key={run.id}
                                className="flex items-center gap-3 rounded-lg border px-4 py-2 text-sm"
                            >
                                <span>
                                    {run.kind === 'scenario_analysis'
                                        ? 'Разбор'
                                        : 'Диалог'}
                                    : {statusLabels[run.status]}
                                </span>
                                {can.cancel && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={cancelForm.processing}
                                        onClick={() =>
                                            cancelForm.post(
                                                cancel(run.id).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Square className="size-3" />
                                        Остановить
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
                <MethodNote />
            </main>
        </>
    );
}
ShowScenario.layout = {
    breadcrumbs: [{ title: 'Город и сценарии', href: index() }],
};

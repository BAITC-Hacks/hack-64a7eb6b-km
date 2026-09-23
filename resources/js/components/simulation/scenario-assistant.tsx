import { Link, useForm, usePoll } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    RotateCcw,
    Send,
    Sparkles,
    Square,
    X,
} from 'lucide-react';
import { useEffect } from 'react';
import { DecisionList, number } from '@/components/simulation/results';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/approvals';
import { cancel } from '@/routes/runs';
import { analysis, compare, messages, show } from '@/routes/scenarios';
import { map } from '@/routes';
import { statusLabels } from '@/types/agents';
import type {
    AiRuntime,
    Dataset,
    Scenario,
    ScenarioApproval,
    ScenarioRun,
} from '@/types/simulation';

function RunActivity({ run }: { run: ScenarioRun }) {
    if (!run.activity.length) return null;
    return (
        <details className="mt-3 text-xs text-muted-foreground">
            <summary className="cursor-pointer">Действия агента</summary>
            <ol className="mt-2 space-y-1 border-l pl-3">
                {run.activity.map((event) => (
                    <li key={event.id}>
                        {event.type === 'approval.requested'
                            ? 'Предложение передано на подтверждение'
                            : `${event.tool === 'evaluate_scenario' ? 'Проверка и расчёт варианта' : 'Подготовка предложения'}: ${event.type === 'tool.started' ? 'начато' : 'завершено'}`}
                    </li>
                ))}
            </ol>
        </details>
    );
}

function RetryRun({
    run,
    scenarioId,
    returnToMap,
    disabled,
}: {
    run: ScenarioRun;
    scenarioId: string;
    returnToMap: boolean;
    disabled: boolean;
}) {
    const form = useForm({
        input: run.input,
        request_key: crypto.randomUUID(),
        return_to: returnToMap ? 'map' : 'scenario',
    });
    return (
        <div className="mt-3">
            <Button
                size="sm"
                variant="outline"
                disabled={disabled || form.processing}
                onClick={() =>
                    form.post(
                        (run.kind === 'scenario_chat' ? messages : analysis)(
                            scenarioId,
                        ).url,
                        {
                            preserveScroll: true,
                            onSuccess: () =>
                                form.setData(
                                    'request_key',
                                    crypto.randomUUID(),
                                ),
                        },
                    )
                }
            >
                <RotateCcw className="size-3" /> Повторить
            </Button>
            {Object.values(form.errors).map((error, index) => (
                <p
                    key={index}
                    role="alert"
                    className="mt-2 text-sm text-destructive"
                >
                    {error}
                </p>
            ))}
        </div>
    );
}

function Proposal({
    approval,
    dataset,
    sourceId,
    allowed,
    returnToMap,
}: {
    approval: ScenarioApproval;
    dataset: Dataset;
    sourceId: string;
    allowed: boolean;
    returnToMap: boolean;
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
                Бюджет {approval.arguments.result.cost} / {dataset.budget}
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
                        <Link
                            href={
                                returnToMap
                                    ? map({
                                          query: {
                                              scenario: approval.scenario_id,
                                          },
                                      })
                                    : show(approval.scenario_id)
                            }
                        >
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

export function ScenarioAssistant({
    aiRuntime,
    scenario,
    dataset,
    runs,
    approvals,
    can,
    returnToMap = false,
}: {
    aiRuntime: AiRuntime;
    scenario: Scenario;
    dataset: Dataset;
    runs: ScenarioRun[];
    approvals: ScenarioApproval[];
    can: { analyze: boolean; cancel: boolean; approve: boolean };
    returnToMap?: boolean;
}) {
    const active = runs.filter((run) =>
        ['queued', 'running'].includes(run.status),
    );
    const { start, stop } = usePoll(
        2000,
        {
            only: ['runs', 'approvals', 'aiRuntime'],
            data: returnToMap ? { scenario: scenario.id } : {},
        },
        { autoStart: false },
    );
    useEffect(() => {
        if (active.length) start();
        else stop();
        return stop;
    }, [active.length, start, stop]);
    const analysisForm = useForm({
        request_key: crypto.randomUUID(),
        return_to: returnToMap ? 'map' : 'scenario',
    });
    const chatForm = useForm({
        input: '',
        request_key: crypto.randomUUID(),
        return_to: returnToMap ? 'map' : 'scenario',
    });
    const cancelForm = useForm({});
    const reportRun = [...runs]
        .reverse()
        .find(
            (run) =>
                run.kind === 'scenario_analysis' &&
                run.status === 'succeeded' &&
                run.output_data,
        );
    const report = reportRun?.output_data;
    const chats = runs.filter((run) => run.kind === 'scenario_chat');
    const hasAnalysis = active.some((run) => run.kind === 'scenario_analysis');
    const hasChat = active.some((run) => run.kind === 'scenario_chat');
    return (
        <div className="space-y-6">
            <div
                role="status"
                className="flex flex-wrap items-center gap-2 text-sm"
            >
                <Badge
                    variant={
                        aiRuntime.driver === 'demo' ? 'outline' : 'default'
                    }
                >
                    {aiRuntime.driver === 'demo'
                        ? 'Демо-режим'
                        : 'AI подключён'}
                </Badge>
                <span className="text-muted-foreground">
                    {aiRuntime.reason === 'missing_key'
                        ? 'Ключ не указан — доступны расчёты, диалог и предложения без AI.'
                        : aiRuntime.reason === 'forced_demo'
                          ? 'Демо включено в настройках. Запросы к AI не отправляются.'
                          : 'Ответы формирует AI на основе расчётов сценария.'}
                </span>
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
                            disabled={hasAnalysis || analysisForm.processing}
                            onClick={() =>
                                analysisForm.post(analysis(scenario.id).url, {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        analysisForm.setData(
                                            'request_key',
                                            crypto.randomUUID(),
                                        ),
                                })
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
                        <Badge variant="outline">
                            {reportRun?.driver === 'demo'
                                ? 'Демо-разбор'
                                : 'AI-разбор'}
                        </Badge>
                        <p className="text-sm leading-7 whitespace-pre-wrap">
                            {report.summary}
                        </p>
                        <div
                            className={
                                returnToMap
                                    ? 'grid gap-6'
                                    : 'grid gap-6 md:grid-cols-3'
                            }
                        >
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
                                        alternative.id === item.alternative_id,
                                ) + 1}
                                : {item.reason}
                            </p>
                        ))}
                    </div>
                ) : (
                    <p className="mt-6 text-sm text-muted-foreground">
                        Запросите разбор, чтобы обсудить последствия принятых
                        решений.
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
                            <div
                                key={run.id}
                                className="text-sm text-muted-foreground"
                            >
                                {statusLabels[run.status]}
                                {run.error && `: ${run.error}`}
                                {run.status === 'failed' && can.analyze && (
                                    <RetryRun
                                        run={run}
                                        scenarioId={scenario.id}
                                        returnToMap={returnToMap}
                                        disabled={hasAnalysis}
                                    />
                                )}
                            </div>
                        ))}
                </div>
            </section>
            <section className="rounded-2xl border bg-card p-6">
                <h2 className="text-lg font-semibold">Обсудите сценарий</h2>
                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                    Спросите, почему изменилась оценка, или попросите предложить
                    лучший вариант.
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
                                <RunActivity run={run} />
                                {run.status === 'failed' && can.analyze && (
                                    <RetryRun
                                        run={run}
                                        scenarioId={scenario.id}
                                        returnToMap={returnToMap}
                                        disabled={hasChat}
                                    />
                                )}
                            </div>
                        </article>
                    ))}
                    {!chats.length && (
                        <p className="text-sm text-muted-foreground">
                            Например: «Предложи улучшение и подготовь вариант на
                            подтверждение».
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
                                returnToMap={returnToMap}
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
                                        return_to: returnToMap
                                            ? 'map'
                                            : 'scenario',
                                    }),
                            });
                        }}
                        className="space-y-3"
                    >
                        <div
                            className="flex flex-wrap gap-2"
                            aria-label="Примеры вопросов"
                        >
                            {[
                                'Как распределён бюджет?',
                                'Какие риски остаются?',
                                'Как изменились показатели районов?',
                                'Покажи варианты улучшения',
                            ].map((question) => (
                                <Button
                                    key={question}
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="h-auto text-left whitespace-normal"
                                    disabled={hasChat || chatForm.processing}
                                    onClick={() =>
                                        chatForm.setData('input', question)
                                    }
                                >
                                    {question}
                                </Button>
                            ))}
                        </div>
                        <Label htmlFor="scenario-question">Ваш вопрос</Label>
                        <textarea
                            className="w-full rounded-md border bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-2 focus-visible:outline-ring"
                            id="scenario-question"
                            value={chatForm.data.input}
                            onChange={(event) =>
                                chatForm.setData('input', event.target.value)
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
                                {hasChat ? 'Советник отвечает…' : 'Отправить'}
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
                                        cancelForm.post(cancel(run.id).url, {
                                            preserveScroll: true,
                                        })
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
        </div>
    );
}

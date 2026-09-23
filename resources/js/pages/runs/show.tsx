import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import { ArrowLeft, Check, Square, X } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { update } from '@/routes/approvals';
import { cancel, index } from '@/routes/runs';
import type { AgentRun, Approval, RunEvent } from '@/types/agents';
import { statusLabels } from '@/types/agents';

function ApprovalCard({
    approval,
    enabled,
    allowed,
}: {
    approval: Approval;
    enabled: boolean;
    allowed: boolean;
}) {
    const form = useForm<{ decision: 'approve' | 'reject' }>({
        decision: 'approve',
    });
    const decide = (decision: 'approve' | 'reject') => {
        form.transform(() => ({ decision }));
        form.post(update(approval.id).url, { preserveScroll: true });
    };
    return (
        <article className="rounded-xl border p-5">
            <div className="flex flex-wrap justify-between gap-2">
                <h3 className="font-medium">{approval.arguments.title}</h3>
                <Badge variant="outline">
                    {
                        {
                            pending: 'Ожидает решения',
                            approved: 'Сохранено',
                            rejected: 'Отклонено',
                        }[approval.status]
                    }
                </Badge>
            </div>
            <p className="my-4 text-sm break-words whitespace-pre-wrap text-muted-foreground">
                {approval.arguments.body}
            </p>
            {approval.status === 'pending' && allowed && (
                <>
                    <p className="mb-3 text-xs text-muted-foreground">
                        Подтверждение сохранит именно этот текст в ваших
                        заметках.
                    </p>
                    <div className="flex gap-2">
                        <Button
                            disabled={!enabled || form.processing}
                            onClick={() => decide('approve')}
                        >
                            <Check className="size-4" />
                            Подтвердить запись
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
                </>
            )}
            {approval.status === 'pending' && !allowed && (
                <p className="text-xs text-muted-foreground">
                    У вас нет разрешения на подтверждение или отклонение
                    предложения.
                </p>
            )}
            <InputError message={form.errors.decision} />
        </article>
    );
}

export default function ShowRun({
    run,
    events,
    approvals,
    can,
}: {
    run: AgentRun;
    events: RunEvent[];
    approvals: Approval[];
    can: { cancel: boolean; resolveApprovals: boolean };
}) {
    const active = ['queued', 'running'].includes(run.status);
    const { start, stop } = usePoll(
        2000,
        { only: ['run', 'events', 'approvals'] },
        { autoStart: false },
    );
    useEffect(() => {
        if (active) start();
        else stop();
        return stop;
    }, [active, start, stop]);
    const cancelForm = useForm({});

    return (
        <>
            <Head title={`Запуск · ${statusLabels[run.status]}`} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6 lg:p-10">
                <Link
                    href={index()}
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Все запуски
                </Link>
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Запуск агента
                        </h1>
                        <p className="mt-2 font-mono text-xs text-muted-foreground">
                            {run.id}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Badge
                            variant={
                                run.status === 'failed'
                                    ? 'destructive'
                                    : 'secondary'
                            }
                        >
                            {statusLabels[run.status]}
                        </Badge>
                        {active && can.cancel && (
                            <Button
                                variant="outline"
                                disabled={cancelForm.processing}
                                onClick={() =>
                                    cancelForm.post(cancel(run.id).url)
                                }
                            >
                                <Square className="size-3" />
                                Отменить
                            </Button>
                        )}
                    </div>
                </header>
                <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                    <span>
                        {run.driver === 'demo'
                            ? 'Локальное демо · AI не вызывается'
                            : `${run.provider} / ${run.model}`}
                    </span>
                    <span>
                        Инструменты: {run.tool_calls}/
                        {run.limits.max_tool_calls}
                    </span>
                    <span>
                        Токены:{' '}
                        {(run.usage?.prompt_tokens ?? 0) +
                            (run.usage?.completion_tokens ?? 0)}
                    </span>
                    <span>Prompt: {run.prompt_version}</span>
                </div>
                <section className="rounded-xl border p-6">
                    <h2 className="mb-3 text-sm font-semibold text-muted-foreground">
                        Задача
                    </h2>
                    <p className="break-words whitespace-pre-wrap">
                        {run.input}
                    </p>
                </section>
                <section
                    className="rounded-xl border bg-muted/20 p-6"
                    aria-live="polite"
                >
                    <h2 className="mb-3 text-sm font-semibold text-muted-foreground">
                        Результат
                    </h2>
                    <p className="text-sm leading-7 break-words whitespace-pre-wrap">
                        {run.output ??
                            run.error ??
                            (run.status === 'queued'
                                ? 'Задача ожидает worker. Если статус не меняется, проверьте, что запущен composer dev.'
                                : run.status === 'cancelled'
                                  ? 'Запуск отменён. Новые действия не выполняются.'
                                  : 'Агент выполняет задачу…')}
                    </p>
                </section>
                {approvals.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-medium">
                            Предложения агента
                        </h2>
                        {approvals.map((approval) => (
                            <ApprovalCard
                                key={approval.id}
                                approval={approval}
                                enabled={run.status === 'succeeded'}
                                allowed={can.resolveApprovals}
                            />
                        ))}
                    </section>
                )}
                <section>
                    <h2 className="mb-4 text-lg font-medium">
                        Журнал выполнения
                    </h2>
                    <ol className="divide-y rounded-xl border">
                        {events.map((event) => (
                            <li key={event.id} className="p-4">
                                <div className="flex flex-wrap justify-between gap-2">
                                    <code className="text-xs">
                                        {event.type}
                                    </code>
                                    <time className="text-xs text-muted-foreground">
                                        {new Date(
                                            event.created_at,
                                        ).toLocaleTimeString('ru-RU')}
                                    </time>
                                </div>
                                {Object.keys(event.data).length > 0 && (
                                    <pre className="mt-2 overflow-x-auto text-xs text-muted-foreground">
                                        {JSON.stringify(event.data, null, 2)}
                                    </pre>
                                )}
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </>
    );
}

ShowRun.layout = { breadcrumbs: [{ title: 'Запуски', href: index() }] };

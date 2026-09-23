import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import { ArrowRight, Play, ShieldCheck, Wrench } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { index, show, store } from '@/routes/runs';
import type { AgentRun, Note } from '@/types/agents';
import { statusLabels } from '@/types/agents';

type Props = {
    runs: {
        data: AgentRun[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    notes: Note[];
    runtime: {
        driver: string;
        provider: string;
        model: string;
        max_steps: number;
        max_tool_calls: number;
    };
};

export default function Runs({ runs, notes, runtime }: Props) {
    const { can } = usePermissions();
    const canCreate = can('workspace.runs.create');
    const form = useForm({ input: '', request_key: crypto.randomUUID() });
    const active = runs.data.some((run) =>
        ['queued', 'running'].includes(run.status),
    );
    const { start, stop } = usePoll(
        3000,
        { only: ['runs', 'notes'] },
        { autoStart: false },
    );
    useEffect(() => {
        if (active) start();
        else stop();
        return stop;
    }, [active, start, stop]);

    return (
        <>
            <Head title="Запуски" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 p-6 lg:p-10">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                            Hackalem / Agent workspace
                        </p>
                        <h1 className="text-3xl font-semibold tracking-tight">
                            {canCreate
                                ? 'От задачи к действию'
                                : 'История запусков'}
                        </h1>
                        <p className="mt-2 max-w-xl text-sm text-muted-foreground">
                            {canCreate
                                ? 'Запустите агента, изучите его действия и подтвердите изменения.'
                                : 'Просматривайте свои задачи, результаты и журнал действий. Для создания запусков обратитесь к администратору.'}
                        </p>
                    </div>
                    <Badge variant="outline">
                        {runtime.driver === 'demo'
                            ? 'Демо · без AI-запросов'
                            : `${runtime.provider} / ${runtime.model}`}
                    </Badge>
                </header>

                {canCreate && (
                    <div className="grid gap-8 lg:grid-cols-[1.2fr_1fr]">
                        <section className="rounded-xl border p-6">
                            <h2 className="mb-5 text-lg font-medium">
                                Новый запуск
                            </h2>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(store().url);
                                }}
                                className="space-y-4"
                            >
                                <Label htmlFor="input">Задача для агента</Label>
                                <textarea
                                    id="input"
                                    name="input"
                                    value={form.data.input}
                                    onChange={(event) =>
                                        form.setData(
                                            'input',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={6000}
                                    rows={6}
                                    required
                                    placeholder="Найди мои заметки о проекте и предложи план следующего шага…"
                                    className="w-full resize-y rounded-md border bg-background p-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    aria-describedby="prompt-hint"
                                    aria-invalid={Boolean(form.errors.input)}
                                />
                                <InputError
                                    message={
                                        form.errors.input ??
                                        form.errors.request_key
                                    }
                                />
                                <p
                                    id="prompt-hint"
                                    className="text-xs text-muted-foreground"
                                >
                                    {runtime.driver === 'demo'
                                        ? 'Локальный сценарий прочитает заметки и предложит сохранить демо-заметку. Модель не вызывается.'
                                        : 'Агент видит только ваши заметки. Любая новая запись требует вашего подтверждения.'}
                                </p>
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        !form.data.input.trim()
                                    }
                                >
                                    <Play className="size-4" />
                                    {form.processing
                                        ? 'Отправляем…'
                                        : 'Запустить агента'}
                                </Button>
                            </form>
                        </section>
                        <aside className="space-y-5 rounded-xl border bg-muted/30 p-6">
                            <h2 className="flex items-center gap-2 text-lg font-medium">
                                <ShieldCheck className="size-5" />
                                Границы запуска
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                До {runtime.max_steps} шагов модели и{' '}
                                {runtime.max_tool_calls} вызовов инструментов.
                                Запуск можно отменить; уже отправленный запрос к
                                провайдеру может завершиться позже.
                            </p>
                            <div className="border-t pt-5">
                                <h3 className="mb-3 flex items-center gap-2 text-sm font-medium">
                                    <Wrench className="size-4" />
                                    Доступные инструменты
                                </h3>
                                <ul className="space-y-3 text-sm">
                                    <li>
                                        <code className="text-xs">
                                            search_notes
                                        </code>
                                        <p className="text-muted-foreground">
                                            Поиск по вашим заметкам.
                                        </p>
                                    </li>
                                    <li>
                                        <code className="text-xs">
                                            propose_note
                                        </code>
                                        <p className="text-muted-foreground">
                                            Предложение записи с ручным
                                            подтверждением.
                                        </p>
                                    </li>
                                </ul>
                            </div>
                        </aside>
                    </div>
                )}

                <section>
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-lg font-medium">Запуски</h2>
                        <span className="text-sm text-muted-foreground">
                            Всего {runs.total}
                        </span>
                    </div>
                    <div className="divide-y rounded-xl border">
                        {runs.data.length === 0 && (
                            <p className="p-8 text-center text-sm text-muted-foreground">
                                Здесь появятся задачи, ответы и журнал действий
                                агента.
                            </p>
                        )}
                        {runs.data.map((run) => (
                            <Link
                                key={run.id}
                                href={show(run.id)}
                                className="flex items-center justify-between gap-4 p-4 transition-colors hover:bg-muted/40"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {run.input}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {new Date(
                                            run.created_at,
                                        ).toLocaleString('ru-RU')}{' '}
                                        ·{' '}
                                        {run.driver === 'demo' ? 'Демо' : 'AI'}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-3">
                                    <Badge
                                        variant={
                                            run.status === 'failed'
                                                ? 'destructive'
                                                : 'secondary'
                                        }
                                    >
                                        {statusLabels[run.status]}
                                    </Badge>
                                    <ArrowRight className="size-4 text-muted-foreground" />
                                </div>
                            </Link>
                        ))}
                    </div>
                    <nav
                        aria-label="Страницы запусков"
                        className="mt-3 flex justify-end gap-4 text-sm"
                    >
                        {runs.prev_page_url && (
                            <Link href={runs.prev_page_url}>Назад</Link>
                        )}
                        {runs.next_page_url && (
                            <Link href={runs.next_page_url}>Дальше</Link>
                        )}
                    </nav>
                </section>

                <section>
                    <h2 className="mb-4 text-lg font-medium">
                        Сохранённые заметки
                    </h2>
                    {notes.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                            {can('workspace.approvals.resolve')
                                ? 'Пока пусто. Подтвердите предложение агента — заметка появится здесь.'
                                : 'Здесь появятся ваши сохранённые заметки.'}
                        </p>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            {notes.map((note) => (
                                <article
                                    key={note.id}
                                    className="rounded-xl border p-5"
                                >
                                    <h3 className="font-medium">
                                        {note.title}
                                    </h3>
                                    <p className="mt-2 text-sm break-words whitespace-pre-wrap text-muted-foreground">
                                        {note.body}
                                    </p>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

Runs.layout = { breadcrumbs: [{ title: 'Запуски', href: index() }] };

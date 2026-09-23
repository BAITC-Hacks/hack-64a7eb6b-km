import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import { ArrowLeft, Check, Plus, Sparkles, X } from 'lucide-react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import {
    PageHeading,
    number,
    delta,
    MethodNote,
} from '@/components/simulation/results';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store, preview } from '@/routes/scenarios';
import type { Dataset, Selection, SimulationResult } from '@/types/simulation';

type Props = {
    dataset: Dataset;
    baseline: SimulationResult;
    initialSelections: Selection[];
    sourceId: string | null;
};
export default function CreateScenario({
    dataset,
    baseline,
    initialSelections,
    sourceId,
}: Props) {
    const form = useForm({
        title: sourceId ? 'Новый вариант' : 'Мой городской сценарий',
        selections: initialSelections,
        source_scenario_id: sourceId,
        request_key: crypto.randomUUID(),
    });
    const http = useHttp<
        { selections: Selection[]; source_scenario_id: string | null },
        { result: SimulationResult }
    >({ selections: [], source_scenario_id: sourceId });
    const [direction, setDirection] = useState('all');
    const [calculated, setCalculated] = useState<{
        fingerprint: string;
        result: SimulationResult;
    } | null>(null);
    const previewGeneration = useRef(0);
    const fingerprint = JSON.stringify(form.data.selections);
    const result =
        calculated?.fingerprint === fingerprint ? calculated.result : null;
    const cost = form.data.selections.reduce(
        (total, selection) =>
            total +
            (dataset.measures.find(
                (measure) => measure.id === selection.measure_id,
            )?.cost ?? 0),
        0,
    );
    const refreshPreview = useEffectEvent(
        async (selectionKey: string, selections: Selection[]) => {
            const generation = ++previewGeneration.current;
            http.cancel();
            http.clearErrors();
            http.transform(() => ({
                selections,
                source_scenario_id: sourceId,
            }));
            try {
                const response = await http.post(preview().url);
                setCalculated({
                    fingerprint: selectionKey,
                    result: response.result,
                });
            } catch {
                if (generation === previewGeneration.current)
                    setCalculated(null);
            }
        },
    );
    const cancelPreview = useEffectEvent(() => {
        ++previewGeneration.current;
        http.cancel();
    });
    useEffect(() => {
        if (form.data.selections.length !== 5) return;
        const timeout = window.setTimeout(() => {
            void refreshPreview(fingerprint, form.data.selections);
        }, 300);
        return () => {
            window.clearTimeout(timeout);
            cancelPreview();
        };
    }, [fingerprint, form.data.selections]);
    const errors = Object.values({ ...form.errors, ...http.errors }).flat();
    const select = (measureId: string) => {
        const measure = dataset.measures.find((item) => item.id === measureId)!;
        const selected = form.data.selections.find(
            (item) => item.measure_id === measureId,
        );
        form.clearErrors();
        http.clearErrors();
        form.setData(
            'selections',
            selected
                ? form.data.selections.filter(
                      (item) => item.measure_id !== measureId,
                  )
                : [
                      ...form.data.selections,
                      {
                          measure_id: measureId,
                          district_id: measure.scope === 'city' ? null : '',
                      },
                  ],
        );
    };
    return (
        <>
            <Head title="Новый сценарий" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-7 p-5 lg:p-9">
                <Link
                    href={index()}
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <ArrowLeft className="size-4" />К городу
                </Link>
                <PageHeading
                    title="Каким станет ваш город?"
                    description="Выберите ровно пять мероприятий. В одном направлении — не больше двух. Для районных мер укажите, где они нужны."
                >
                    <Button
                        variant="outline"
                        onClick={() => {
                            form.setData('selections', dataset.example);
                            http.clearErrors();
                        }}
                    >
                        <Sparkles className="size-4" />
                        Пример из задания
                    </Button>
                </PageHeading>
                <div className="grid items-start gap-6 xl:grid-cols-[1fr_360px]">
                    <section>
                        <div className="mb-5 flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant={
                                    direction === 'all' ? 'default' : 'outline'
                                }
                                onClick={() => setDirection('all')}
                            >
                                Все меры
                            </Button>
                            {Object.entries(dataset.directions).map(
                                ([key, label]) => (
                                    <Button
                                        key={key}
                                        size="sm"
                                        variant={
                                            direction === key
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => setDirection(key)}
                                    >
                                        {label}
                                    </Button>
                                ),
                            )}
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            {dataset.measures
                                .filter(
                                    (measure) =>
                                        direction === 'all' ||
                                        measure.direction === direction,
                                )
                                .map((measure) => {
                                    const selection = form.data.selections.find(
                                        (item) =>
                                            item.measure_id === measure.id,
                                    );
                                    return (
                                        <article
                                            key={measure.id}
                                            className={`flex flex-col gap-4 rounded-2xl border bg-card p-5 ${selection ? 'border-emerald-600 ring-1 ring-emerald-600' : ''}`}
                                        >
                                            <div className="flex items-center justify-between gap-3">
                                                <span className="text-xs text-muted-foreground">
                                                    {measure.id} ·{' '}
                                                    {
                                                        dataset.directions[
                                                            measure.direction
                                                        ]
                                                    }
                                                </span>
                                                <strong className="text-sm">
                                                    {measure.cost} у. е.
                                                </strong>
                                            </div>
                                            <h2 className="font-semibold">
                                                {measure.name}
                                            </h2>
                                            <p className="text-xs text-muted-foreground">
                                                {measure.scope === 'city'
                                                    ? 'Весь город'
                                                    : 'Один район'}{' '}
                                                · Лаг {measure.lag} кв. ·
                                                Реализуется{' '}
                                                {((8 - measure.lag) / 8) * 100}%
                                                эффекта
                                            </p>
                                            <div className="flex flex-wrap gap-2">
                                                {Object.entries(
                                                    measure.effects,
                                                ).map(([key, value]) => (
                                                    <span
                                                        title={
                                                            dataset.indicators[
                                                                key
                                                            ].name
                                                        }
                                                        key={key}
                                                        className={`rounded-md px-2 py-1 text-xs ${value < 0 ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200' : 'bg-muted'}`}
                                                    >
                                                        {key}{' '}
                                                        {value > 0 ? '+' : ''}
                                                        {value}
                                                    </span>
                                                ))}
                                            </div>
                                            <p className="text-[11px] text-muted-foreground">
                                                Полные эффекты до учёта лага
                                            </p>
                                            <div className="mt-auto flex flex-col gap-3">
                                                {selection &&
                                                    measure.scope ===
                                                        'district' && (
                                                        <>
                                                            <Label
                                                                htmlFor={`district-${measure.id}`}
                                                            >
                                                                Район
                                                            </Label>
                                                            <select
                                                                id={`district-${measure.id}`}
                                                                value={
                                                                    selection.district_id ??
                                                                    ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    form.setData(
                                                                        'selections',
                                                                        form.data.selections.map(
                                                                            (
                                                                                item,
                                                                            ) =>
                                                                                item.measure_id ===
                                                                                measure.id
                                                                                    ? {
                                                                                          ...item,
                                                                                          district_id:
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                      }
                                                                                    : item,
                                                                        ),
                                                                    )
                                                                }
                                                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                            >
                                                                <option value="">
                                                                    Выберите
                                                                    район
                                                                </option>
                                                                {dataset.districts.map(
                                                                    (
                                                                        district,
                                                                    ) => (
                                                                        <option
                                                                            key={
                                                                                district.id
                                                                            }
                                                                            value={
                                                                                district.id
                                                                            }
                                                                        >
                                                                            {
                                                                                district.name
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        </>
                                                    )}
                                                <Button
                                                    variant={
                                                        selection
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                    disabled={
                                                        !selection &&
                                                        form.data.selections
                                                            .length >= 5
                                                    }
                                                    onClick={() =>
                                                        select(measure.id)
                                                    }
                                                >
                                                    {selection ? (
                                                        <Check className="size-4" />
                                                    ) : (
                                                        <Plus className="size-4" />
                                                    )}
                                                    {selection
                                                        ? 'Выбрано · убрать'
                                                        : 'Добавить'}
                                                </Button>
                                            </div>
                                        </article>
                                    );
                                })}
                        </div>
                    </section>
                    <aside className="space-y-5 rounded-2xl border bg-card p-5 xl:sticky xl:top-6">
                        <div className="flex items-baseline justify-between">
                            <h2 className="font-semibold">Ваши решения</h2>
                            <span className="text-sm text-muted-foreground">
                                {form.data.selections.length} / 5
                            </span>
                        </div>
                        <div className="rounded-xl bg-muted/50 p-4">
                            <div className="flex justify-between text-sm">
                                <span>Бюджет</span>
                                <strong
                                    className={
                                        cost > 100 ? 'text-destructive' : ''
                                    }
                                >
                                    {cost} / {dataset.budget}
                                </strong>
                            </div>
                            <div className="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={`h-full ${cost > 100 ? 'bg-destructive' : 'bg-emerald-600'}`}
                                    style={{ width: `${Math.min(100, cost)}%` }}
                                />
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Остаток {dataset.budget - cost} у. е. не даёт
                                бонуса.
                            </p>
                        </div>
                        <ol className="space-y-3">
                            {form.data.selections.map((selection) => (
                                <li
                                    key={selection.measure_id}
                                    className="flex items-start justify-between gap-3 text-sm"
                                >
                                    <span>
                                        {
                                            dataset.measures.find(
                                                (measure) =>
                                                    measure.id ===
                                                    selection.measure_id,
                                            )?.name
                                        }
                                        <span className="mt-1 block text-xs text-muted-foreground">
                                            {selection.district_id === null
                                                ? 'Весь город'
                                                : (dataset.districts.find(
                                                      (district) =>
                                                          district.id ===
                                                          selection.district_id,
                                                  )?.name ?? 'Район не выбран')}
                                        </span>
                                    </span>
                                    <button
                                        aria-label={`Убрать ${selection.measure_id}`}
                                        onClick={() =>
                                            select(selection.measure_id)
                                        }
                                        className="rounded p-1 hover:bg-muted"
                                    >
                                        <X className="size-4" />
                                    </button>
                                </li>
                            ))}
                        </ol>
                        {form.data.selections.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Добавьте мероприятия из каталога.
                            </p>
                        )}
                        <div
                            aria-live="polite"
                            className="rounded-xl border border-dashed p-4"
                        >
                            {result ? (
                                <>
                                    <p className="text-xs text-muted-foreground">
                                        Предварительный Score
                                    </p>
                                    <p className="mt-1 text-3xl font-semibold tabular-nums">
                                        {number(result.score)}
                                    </p>
                                    <p className="mt-2 text-xs text-emerald-700 dark:text-emerald-400">
                                        {delta(
                                            Number(result.score) -
                                                Number(baseline.score),
                                        )}{' '}
                                        к исходному состоянию
                                    </p>
                                </>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {http.processing
                                        ? 'Рассчитываем последствия…'
                                        : 'Расчёт появится после выбора пяти допустимых решений.'}
                                </p>
                            )}
                        </div>
                        {errors.length > 0 && (
                            <ul
                                role="alert"
                                className="space-y-2 text-sm text-destructive"
                            >
                                {errors.map((error, i) => (
                                    <li key={i}>{String(error)}</li>
                                ))}
                            </ul>
                        )}
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(store().url);
                            }}
                            className="space-y-3"
                        >
                            <Label htmlFor="scenario-title">
                                Название сценария
                            </Label>
                            <Input
                                id="scenario-title"
                                value={form.data.title}
                                maxLength={160}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                                required
                            />
                            <Button
                                type="submit"
                                className="w-full bg-emerald-800 text-white hover:bg-emerald-700"
                                disabled={!result || form.processing}
                            >
                                {form.processing
                                    ? 'Сохраняем…'
                                    : 'Сохранить и посмотреть результат'}
                            </Button>
                        </form>
                    </aside>
                </div>
                <MethodNote />
            </main>
        </>
    );
}
CreateScenario.layout = {
    breadcrumbs: [
        { title: 'Город и сценарии', href: index() },
        { title: 'Новый сценарий', href: '#' },
    ],
};

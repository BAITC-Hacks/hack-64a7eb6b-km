import {
    BusFront,
    Check,
    ChevronDown,
    Leaf,
    Pencil,
    Plus,
    Search,
    Settings,
    ShieldCheck,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    budget,
    directions,
    districts,
    getMeasure,
    indicatorNames,
    measures,
    scenarioCost,
    validateDecisions,
} from '@/lib/city-simulator';
import type {
    Decision,
    Direction,
    DistrictId,
    Indicator,
    Measure,
} from '@/lib/city-simulator';
import { cn } from '@/lib/utils';

export const directionIcons = {
    transport: BusFront,
    ecology: Leaf,
    social: Users,
    safety: ShieldCheck,
    services: Settings,
};

type Props = {
    decisions: Decision[];
    selectedDistrict: DistrictId;
    onChange: (next: Decision[]) => void;
};

export function DecisionsEditor({
    decisions,
    selectedDistrict,
    onChange,
}: Props) {
    const [catalogOpen, setCatalogOpen] = useState(false);
    const [editingIndex, setEditingIndex] = useState<number | null>(null);
    const [error, setError] = useState('');

    function update(next: Decision[]) {
        const errors = validateDecisions(next, false);
        if (errors.length) {
            setError(errors[0]);
            return false;
        }
        setError('');
        onChange(next);
        return true;
    }

    function openCatalog(index: number | null = null) {
        setEditingIndex(index);
        setCatalogOpen(true);
        setError('');
    }

    return (
        <section
            aria-labelledby="decisions-title"
            className="flex flex-1 flex-col border-t border-border px-4 sm:px-6"
        >
            <div className="flex min-h-16 items-center justify-between gap-4">
                <h2
                    id="decisions-title"
                    className="text-xl font-bold tracking-tight"
                >
                    Ваши решения
                </h2>
                <button className="sim-button" onClick={() => openCatalog()}>
                    Каталог · 14 мер
                </button>
            </div>
            {error && (
                <p
                    role="alert"
                    className="mb-3 border-l-2 border-amber-500 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                >
                    {error}
                </p>
            )}
            <div className="overflow-x-auto">
                <table className="sim-decisions-table w-full min-w-145 table-fixed text-left text-sm">
                    <caption className="sr-only">
                        Мероприятия, выбранные для сценария
                    </caption>
                    <colgroup>
                        <col className="w-[24%]" />
                        <col className="w-[33%]" />
                        <col className="w-[25%]" />
                        <col className="w-[12%]" />
                        <col className="w-[6%]" />
                    </colgroup>
                    <thead className="border-b border-border text-[10px] font-normal tracking-wide text-muted-foreground uppercase">
                        <tr>
                            <th className="pb-3 font-normal">Направление</th>
                            <th className="pb-3 font-normal">Мера</th>
                            <th className="pb-3 font-normal">Территория</th>
                            <th className="pb-3 font-normal">Цена, у. е.</th>
                            <th>
                                <span className="sr-only">Изменить</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {decisions.map((decision, index) => {
                            const measure = getMeasure(decision.measureId);
                            const Icon = directionIcons[measure.direction];
                            return (
                                <tr
                                    key={measure.id}
                                    className="border-b border-border transition-colors hover:bg-muted/40"
                                >
                                    <td className="py-2 pr-2">
                                        <span className="flex items-center gap-3">
                                            <Icon className="size-5 shrink-0 stroke-[1.7]" />
                                            <span>
                                                {directions[measure.direction]}
                                            </span>
                                        </span>
                                    </td>
                                    <td className="py-2 pr-3">
                                        {measure.name}
                                    </td>
                                    <td className="py-2 pr-5">
                                        {measure.scope === 'city' ? (
                                            <span className="text-muted-foreground">
                                                Весь город
                                            </span>
                                        ) : (
                                            <div className="relative">
                                                <select
                                                    aria-label={`Район: ${measure.name}`}
                                                    className="sim-select h-8 min-h-8 w-full py-1 text-[13px]"
                                                    value={
                                                        decision.districtId ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        update(
                                                            decisions.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              districtId:
                                                                                  event
                                                                                      .target
                                                                                      .value as DistrictId,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    {districts.map(
                                                        (district) => (
                                                            <option
                                                                key={
                                                                    district.id
                                                                }
                                                                value={
                                                                    district.id
                                                                }
                                                            >
                                                                {district.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <ChevronDown className="pointer-events-none absolute top-2.5 right-2 size-3" />
                                            </div>
                                        )}
                                    </td>
                                    <td className="py-2 text-base font-bold tabular-nums">
                                        {measure.cost}
                                    </td>
                                    <td>
                                        <button
                                            aria-label={`Изменить: ${measure.name}`}
                                            className="sim-icon-button size-8 border-0"
                                            onClick={() => openCatalog(index)}
                                        >
                                            <Pencil className="size-4" />
                                        </button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {decisions.length < 5 && (
                <button
                    onClick={() => openCatalog()}
                    className="my-4 flex min-h-14 items-center justify-center gap-2 border border-dashed border-border text-sm text-muted-foreground transition-colors hover:border-emerald-600 hover:text-emerald-700"
                >
                    <Plus className="size-4" />
                    Добавить решение · осталось {5 - decisions.length}
                </button>
            )}
            <div
                className="flex min-h-20 items-center gap-3 py-5 text-xs sm:text-sm"
                aria-live="polite"
            >
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-white">
                    <Check className="size-4" />
                </span>
                <span className="text-muted-foreground">
                    {decisions.length === 5
                        ? 'Бюджет соблюдён · Повторов и конфликтов нет'
                        : 'Выберите 5 мероприятий, чтобы получить итоговую оценку'}
                </span>
            </div>
            <MeasureCatalog
                open={catalogOpen}
                onOpenChange={setCatalogOpen}
                decisions={decisions}
                editingIndex={editingIndex}
                initialDistrict={
                    editingIndex === null
                        ? selectedDistrict
                        : (decisions[editingIndex]?.districtId ??
                          selectedDistrict)
                }
                onSelect={(measure, districtId) => {
                    const nextDecision = {
                        measureId: measure.id,
                        districtId:
                            measure.scope === 'city' ? null : districtId,
                    };
                    const next =
                        editingIndex === null
                            ? [...decisions, nextDecision]
                            : decisions.map((decision, index) =>
                                  index === editingIndex
                                      ? nextDecision
                                      : decision,
                              );
                    if (update(next)) setCatalogOpen(false);
                }}
                onRemove={() => {
                    if (editingIndex !== null)
                        update(
                            decisions.filter(
                                (_, index) => index !== editingIndex,
                            ),
                        );
                    setCatalogOpen(false);
                }}
            />
        </section>
    );
}

type CatalogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    decisions: Decision[];
    editingIndex: number | null;
    initialDistrict: DistrictId;
    onSelect: (measure: Measure, districtId: DistrictId) => void;
    onRemove: () => void;
};

function MeasureCatalog(props: CatalogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            <DialogContent className="flex max-h-[90dvh] flex-col gap-0 overflow-hidden rounded-none p-0 sm:max-w-3xl">
                <CatalogContents
                    key={`${props.open}-${props.editingIndex}-${props.initialDistrict}`}
                    {...props}
                />
            </DialogContent>
        </Dialog>
    );
}

function CatalogContents({
    decisions,
    editingIndex,
    initialDistrict,
    onSelect,
    onRemove,
}: CatalogProps) {
    const [query, setQuery] = useState('');
    const [direction, setDirection] = useState<Direction | 'all'>('all');
    const [districtId, setDistrictId] = useState(initialDistrict);
    const available =
        editingIndex === null
            ? decisions
            : decisions.filter((_, index) => index !== editingIndex);
    const shown = measures.filter(
        (measure) =>
            (direction === 'all' || measure.direction === direction) &&
            measure.name
                .toLocaleLowerCase('ru')
                .includes(query.toLocaleLowerCase('ru')),
    );
    return (
        <>
            <DialogHeader className="border-b border-border p-6 text-left">
                <DialogTitle className="text-xl">
                    {editingIndex === null
                        ? 'Каталог мероприятий'
                        : 'Заменить решение'}
                </DialogTitle>
                <DialogDescription>
                    14 способов изменить город. Доступный бюджет:{' '}
                    {budget - scenarioCost(available)} у. е.
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-3 border-b border-border p-4 sm:grid-cols-[1fr_160px] sm:px-6">
                <div className="relative">
                    <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                    <input
                        aria-label="Поиск мероприятия"
                        placeholder="Найти мероприятие…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        className="sim-input w-full pr-8 pl-9"
                    />
                    {query && (
                        <button
                            aria-label="Очистить поиск"
                            className="absolute top-3 right-3"
                            onClick={() => setQuery('')}
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
                <select
                    aria-label="Район для мероприятия"
                    className="sim-select"
                    value={districtId}
                    onChange={(event) =>
                        setDistrictId(event.target.value as DistrictId)
                    }
                >
                    {districts.map((district) => (
                        <option key={district.id} value={district.id}>
                            {district.name}
                        </option>
                    ))}
                </select>
                <div className="flex flex-wrap gap-1 sm:col-span-2">
                    {(
                        ['all', ...Object.keys(directions)] as (
                            | Direction
                            | 'all'
                        )[]
                    ).map((key) => (
                        <button
                            key={key}
                            className={cn(
                                'border px-2.5 py-1.5 text-xs transition-colors',
                                direction === key
                                    ? 'border-foreground bg-foreground text-background'
                                    : 'border-transparent text-muted-foreground hover:bg-muted',
                            )}
                            aria-pressed={direction === key}
                            onClick={() => setDirection(key)}
                        >
                            {key === 'all'
                                ? 'Все направления'
                                : directions[key]}
                        </button>
                    ))}
                </div>
            </div>
            <div className="min-h-0 overflow-y-auto px-4 sm:px-6">
                {shown.length === 0 && (
                    <p className="py-12 text-center text-sm text-muted-foreground">
                        Ничего не найдено. Попробуйте другое название.
                    </p>
                )}
                {shown.map((measure) => {
                    const candidate = [
                        ...available,
                        {
                            measureId: measure.id,
                            districtId:
                                measure.scope === 'city' ? null : districtId,
                        },
                    ];
                    const errors = validateDecisions(candidate, false);
                    const Icon = directionIcons[measure.direction];
                    return (
                        <article
                            key={measure.id}
                            className="flex items-start gap-3 border-b border-border py-5 last:border-0"
                        >
                            <Icon className="mt-1 size-5 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <div className="mb-1 flex flex-wrap gap-x-2">
                                    <h3 className="text-sm font-semibold">
                                        {measure.name}
                                    </h3>
                                    <span className="text-xs text-muted-foreground">
                                        {measure.id}
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {directions[measure.direction]} ·{' '}
                                    {measure.scope === 'city'
                                        ? 'Весь город'
                                        : districts.find(
                                              (district) =>
                                                  district.id === districtId,
                                          )?.name}{' '}
                                    · Лаг: {measure.lag} кв.
                                </p>
                                <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                                    {Object.entries(measure.effects)
                                        .map(
                                            ([key, value]) =>
                                                `${indicatorNames[key as Indicator]} ${value > 0 ? '+' : ''}${value}`,
                                        )
                                        .join(' · ')}
                                </p>
                                <p className="mt-1 text-[10px] text-muted-foreground">
                                    Полный эффект до учёта лага
                                </p>
                                {errors.length > 0 && (
                                    <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                                        {errors[0]}
                                    </p>
                                )}
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-2">
                                <span className="text-base font-bold">
                                    {measure.cost}{' '}
                                    <span className="text-xs font-normal text-muted-foreground">
                                        у. е.
                                    </span>
                                </span>
                                <button
                                    disabled={errors.length > 0}
                                    className="sim-button px-3 py-1.5 text-xs"
                                    onClick={() =>
                                        onSelect(measure, districtId)
                                    }
                                >
                                    {editingIndex === null
                                        ? 'Выбрать'
                                        : 'Заменить'}
                                </button>
                            </div>
                        </article>
                    );
                })}
            </div>
            <div className="flex justify-between gap-4 border-t border-border px-6 py-4">
                <span className="text-xs text-muted-foreground">
                    Не более 2 мер на направление
                </span>
                {editingIndex !== null && (
                    <button
                        className="flex items-center gap-2 text-xs text-red-700 dark:text-red-400"
                        onClick={onRemove}
                    >
                        <Trash2 className="size-3.5" />
                        Удалить решение
                    </button>
                )}
            </div>
        </>
    );
}

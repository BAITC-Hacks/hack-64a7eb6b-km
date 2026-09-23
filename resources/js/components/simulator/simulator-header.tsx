import { Link, usePage } from '@inertiajs/react';
import { Check, ChevronDown, MapPin, Mountain, Plus } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import { create } from '@/routes/scenarios';
import { number } from '@/components/simulation/results';

const decisionLimit = 5;
import { dashboard, login } from '@/routes';

export function SimulatorHeader({ onRules }: { onRules: () => void }) {
    const { auth } = usePage().props;
    const initials = useInitials();
    return (
        <header className="flex min-h-19 flex-wrap items-center justify-between gap-x-5 border-b border-border px-4 sm:px-7 lg:grid lg:grid-cols-[auto_1fr_auto] xl:px-8">
            <div className="flex items-center gap-3 py-4">
                <Mountain aria-hidden="true" className="size-9 stroke-[2.5]" />
                <span className="text-xl font-extrabold tracking-tight sm:text-[27px]">
                    HACKALEM.AI
                </span>
                <span className="mx-1 hidden h-6 border-l border-border xl:block" />
                <span className="hidden text-lg font-medium xl:block">
                    Аким на 5 часов
                </span>
            </div>
            <nav
                aria-label="Главная навигация"
                className="order-3 flex h-12 w-full justify-center gap-8 sm:order-none sm:h-19 sm:w-auto lg:ml-10 lg:justify-self-start xl:ml-16"
            >
                <span
                    aria-current="page"
                    className="flex items-center border-b-2 border-emerald-700 px-2 font-semibold"
                >
                    Симулятор
                </span>
                <Link
                    href={dashboard()}
                    className="flex items-center px-2 text-sm transition-colors hover:text-emerald-700"
                >
                    Дашборд
                </Link>
                <button
                    onClick={onRules}
                    className="px-2 text-sm transition-colors hover:text-emerald-700"
                >
                    Правила
                </button>
            </nav>
            <div className="flex items-center gap-4 sm:gap-7">
                <span className="hidden items-center gap-2 text-sm sm:flex">
                    <MapPin className="size-4" />
                    Астана
                </span>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        className="flex min-h-10 items-center gap-2 border border-border px-3 text-xs hover:bg-muted"
                        aria-label="Меню аккаунта"
                    >
                        {auth.user ? initials(auth.user.name) : 'Гость'}
                        <ChevronDown className="size-3" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="rounded-none">
                        <DropdownMenuItem asChild>
                            <Link href={auth.user ? dashboard() : login()}>
                                {auth.user
                                    ? 'Рабочее пространство'
                                    : 'Войти в аккаунт'}
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}

export function ScenarioSummary({
    cost,
    count,
    budget,
    horizon,
    canCreate,
}: {
    cost: number;
    count: number;
    budget: number;
    horizon: number;
    canCreate: boolean;
}) {
    return (
        <>
            <section
                aria-label="Состояние сценария"
                className="grid grid-cols-2 border-b border-border lg:grid-cols-[1.5fr_1fr_1fr_auto]"
            >
                <div className="flex min-h-22 flex-col justify-center gap-2 border-r border-border px-4 py-4 sm:px-7">
                    <span className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                        Бюджет
                    </span>
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                        <strong className="text-2xl leading-none tracking-tight tabular-nums">
                            {cost} / {budget}
                        </strong>
                        <progress
                            aria-label="Использовано бюджета"
                            value={cost}
                            max={budget}
                            className="budget-progress hidden h-2.5 min-w-12 flex-1 xl:block"
                        />
                        <span className="text-xs text-muted-foreground">
                            Остаток{' '}
                            <span className="font-medium text-foreground">
                                {budget - cost}
                            </span>{' '}
                            у. е.
                        </span>
                    </div>
                </div>
                <div className="flex min-h-22 flex-col justify-center gap-2 px-4 py-4 sm:px-7 lg:border-r lg:border-border">
                    <span className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                        Решения
                    </span>
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                        <strong className="text-2xl leading-none tracking-tight tabular-nums">
                            {count} / {decisionLimit}
                        </strong>
                        <span className="flex items-center gap-2 text-xs">
                            {count === decisionLimit ? (
                                <>
                                    <span className="flex size-5 items-center justify-center rounded-full bg-emerald-700 text-white">
                                        <Check className="size-3.5" />
                                    </span>
                                    Проверка пройдена
                                </>
                            ) : (
                                <span className="text-muted-foreground">
                                    Выберите ещё {decisionLimit - count}
                                </span>
                            )}
                        </span>
                    </div>
                </div>
                <div className="flex min-h-22 flex-col justify-center gap-2 border-t border-r border-border px-4 py-4 sm:px-7 lg:border-t-0">
                    <span className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                        Горизонт
                    </span>
                    <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                        <strong className="text-xl leading-none tracking-tight xl:text-2xl">
                            {horizon} кварталов
                        </strong>
                        <span className="text-xs text-muted-foreground">
                            {(horizon / 4).toLocaleString('ru-RU')} года
                        </span>
                    </div>
                </div>
                <div className="flex items-center justify-center border-t border-border px-4 py-4 lg:border-t-0 lg:px-6">
                    {canCreate && (
                        <Link
                            className="sim-button w-full sm:w-auto"
                            href={create()}
                        >
                            <Plus className="size-3.5" />
                            Новый сценарий
                        </Link>
                    )}
                </div>
            </section>
        </>
    );
}

export function SimulatorRules({
    open,
    onOpenChange,
    budget,
    horizon,
    baselineScore,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    budget: number;
    horizon: number;
    baselineScore: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85dvh] overflow-y-auto rounded-none sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        Аким на 5 часов
                    </DialogTitle>
                    <DialogDescription>
                        Пять решений. Один бюджет. Будущее города.
                    </DialogDescription>
                </DialogHeader>
                <ol className="grid list-decimal gap-4 pl-5 text-sm leading-relaxed">
                    <li>
                        У всех одинаковые исходные данные и бюджет{' '}
                        <strong>{budget} у. е.</strong> Остаток не даёт бонуса.
                    </li>
                    <li>
                        Выберите <strong>ровно 5 разных мероприятий</strong>, не
                        более двух из одного направления. По правилам датасета
                        достаточно затронуть минимум три направления.
                    </li>
                    <li>
                        Для районных мер выберите район. Городские действуют на
                        все пять районов.
                    </li>
                    <li>
                        Автобусные полосы и ЛРТ несовместимы. Парк и школа, а
                        также чистое топливо и модернизация сетей не могут
                        находиться в одном районе.
                    </li>
                    <li>
                        Эффект рассчитывается за{' '}
                        <strong>{horizon} кварталов</strong> с учётом сроков
                        запуска. Показатели ограничены шкалой 0–100.
                    </li>
                </ol>
                <div className="border-t border-border pt-4 text-sm leading-relaxed">
                    <h3 className="mb-2 font-semibold">Как считается Score</h3>
                    <p>
                        70% среднего индекса по населению + 30% индекса самого
                        слабого района − 1 балл за каждый показатель ниже 40.
                    </p>
                    <p className="mt-3 text-muted-foreground">
                        Исходный Score: {number(baselineScore)}. Расчёт
                        выполняет модель, помощник объясняет результат. Данные и
                        границы районов — условные, для учебной симуляции.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}

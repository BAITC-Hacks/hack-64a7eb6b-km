import {
    ArrowUp,
    ChartNoAxesCombined,
    ChevronRight,
    History,
    Paperclip,
    Plus,
    Sparkle,
    TriangleAlert,
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
    baseline,
    explainScenario,
    formatNumber,
    getDistrict,
    getMeasure,
    indicatorKeys,
    indicatorNames,
} from '@/lib/city-simulator';
import type { Decision, DistrictId, ModelResult } from '@/lib/city-simulator';
import { cn } from '@/lib/utils';

type Message = { question: string; answer: string };
type Props = {
    decisions: Decision[];
    selectedDistrict: DistrictId;
    result: ModelResult | null;
};

export function AssistantPanel({ decisions, selectedDistrict, result }: Props) {
    const [question, setQuestion] = useState('');
    const [messages, setMessages] = useState<Message[]>([]);
    const [history, setHistory] = useState<Message[]>([]);
    const [historyOpen, setHistoryOpen] = useState(false);
    const district = (result ?? baseline).districts.find(
        (item) => item.id === selectedDistrict,
    )!;
    const original = baseline.districts.find(
        (item) => item.id === selectedDistrict,
    )!;
    const critical =
        result?.districts.flatMap((item) =>
            item.critical.map((indicator) => ({ district: item, indicator })),
        ) ?? [];
    const improvement =
        district.indicators.S2 > original.indicators.S2
            ? 'S2'
            : [...indicatorKeys].sort(
                  (first, second) =>
                      district.indicators[second] -
                      original.indicators[second] -
                      (district.indicators[first] - original.indicators[first]),
              )[0];
    const districtAccusative = {
        esil: 'Есиль',
        almaty: 'Алматы',
        saryarka: 'Сарыарку',
        baikonur: 'Байконур',
        nura: 'Нуру',
    };
    const lagValues = decisions.map(
        (decision) => getMeasure(decision.measureId).lag,
    );

    function ask(text: string) {
        const trimmed = text.trim();
        if (!trimmed) return;
        const message = {
            question: trimmed,
            answer: explainScenario(trimmed, decisions, selectedDistrict),
        };
        setMessages((previous) => [...previous, message]);
        setHistory((previous) => [...previous, message]);
        setQuestion('');
    }

    return (
        <aside
            aria-labelledby="assistant-title"
            className="flex min-w-0 flex-col border-t border-border lg:border-t-0 lg:border-l"
        >
            <header className="flex min-h-15 items-center justify-between border-b border-border px-5 sm:px-6">
                <h2
                    id="assistant-title"
                    className="text-lg font-bold tracking-tight"
                >
                    AI-помощник
                </h2>
                <div className="flex gap-2">
                    <button
                        aria-label="Новый диалог"
                        className="sim-icon-button"
                        onClick={() => {
                            setMessages([]);
                            setQuestion('');
                        }}
                    >
                        <Plus className="size-5" />
                    </button>
                    <button
                        aria-label="История диалога"
                        className="sim-icon-button"
                        onClick={() => setHistoryOpen(true)}
                    >
                        <History className="size-4" />
                    </button>
                </div>
            </header>
            <div className="flex flex-1 flex-col px-5 sm:px-6">
                <div className="flex items-center justify-between gap-3 border-b border-border py-5 text-xs text-muted-foreground">
                    <span>Анализ сценария · {decisions.length} решений</span>
                    <span
                        className="text-[10px] uppercase"
                        title="Локальные объяснения без обращения к AI-провайдеру"
                    >
                        Демо
                    </span>
                </div>
                <section
                    aria-label="Итоговая оценка"
                    className="border-b border-border pt-5 pb-6"
                >
                    <h3 className="text-sm xl:text-base">
                        Astana Quality of Life Score
                    </h3>
                    <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-baseline gap-1.5">
                            <strong
                                className="text-[clamp(3rem,4.5vw,4.8rem)] leading-none font-semibold tracking-[-0.065em] tabular-nums"
                                data-testid="scenario-score"
                            >
                                {result ? formatNumber(result.score) : '—'}
                            </strong>
                            <span className="text-xl tracking-tight xl:text-3xl">
                                / 100
                            </span>
                        </div>
                        <div className="text-right">
                            <p
                                className={cn(
                                    'text-2xl font-semibold tracking-tight tabular-nums xl:text-3xl',
                                    result && result.score >= baseline.score
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {result
                                    ? `${result.score >= baseline.score ? '+' : ''}${formatNumber(result.score - baseline.score)}`
                                    : '5 решений'}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Было {formatNumber(baseline.score)}
                            </p>
                        </div>
                    </div>
                </section>
                <div className="flex flex-col gap-6 py-6">
                    {result ? (
                        <>
                            <div className="flex gap-4">
                                <Sparkle className="mt-0.5 size-7 shrink-0 stroke-[1.7]" />
                                <div>
                                    <h3 className="text-lg leading-tight font-bold tracking-tight xl:text-xl">
                                        {district.score > original.score
                                            ? `Сценарий улучшает ${districtAccusative[selectedDistrict]}`
                                            : `Район ${district.name}: без изменений`}
                                    </h3>
                                    <p className="mt-2 text-xs leading-relaxed text-muted-foreground xl:text-sm">
                                        {indicatorNames[improvement]}:{' '}
                                        {formatNumber(
                                            original.indicators[improvement],
                                            0,
                                        )}{' '}
                                        →{' '}
                                        {formatNumber(
                                            district.indicators[improvement],
                                        )}
                                        .<br />
                                        Районный индекс:{' '}
                                        {formatNumber(original.score)} →{' '}
                                        {formatNumber(district.score)}.
                                    </p>
                                </div>
                            </div>
                            {result.synergies.length > 0 && (
                                <div className="flex gap-4">
                                    <ChartNoAxesCombined className="size-6 shrink-0 stroke-[1.7]" />
                                    <div>
                                        <h3 className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                            Синергия мероприятий
                                        </h3>
                                        {result.synergies.map((synergy) => (
                                            <p
                                                key={synergy}
                                                className="mt-1 text-xs leading-relaxed text-muted-foreground xl:text-sm"
                                            >
                                                {synergy}
                                            </p>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {critical.length > 0 ? (
                                <div className="flex gap-4 border-l-2 border-amber-500 bg-amber-50/80 px-4 py-4 dark:bg-amber-950/35">
                                    <TriangleAlert className="size-6 shrink-0 text-amber-500" />
                                    <div>
                                        <h3 className="text-sm font-semibold text-amber-950 dark:text-amber-200">
                                            {critical.length === 1
                                                ? 'Остаётся 1 критический показатель'
                                                : `Критических показателей: ${critical.length}`}
                                        </h3>
                                        {critical.map(
                                            ({ district: item, indicator }) => (
                                                <p
                                                    key={`${item.id}-${indicator}`}
                                                    className="mt-1 text-xs leading-relaxed text-muted-foreground"
                                                >
                                                    {indicatorNames[indicator]},{' '}
                                                    {item.name} —{' '}
                                                    {formatNumber(
                                                        item.indicators[
                                                            indicator
                                                        ],
                                                        0,
                                                    )}{' '}
                                                    / 100.
                                                </p>
                                            ),
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <p className="border-l-2 border-emerald-600 bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                                    Критических показателей нет. Все значения не
                                    ниже 40.
                                </p>
                            )}
                            <p className="-mt-2 text-xs text-muted-foreground">
                                Учтены сроки эффекта: {Math.min(...lagValues)}–
                                {Math.max(...lagValues)} квартала.
                            </p>
                            <button
                                className="sim-button -mt-2 min-h-13 w-full justify-start gap-4 px-4 text-left"
                                onClick={() => ask('Как улучшить сценарий?')}
                            >
                                <ChartNoAxesCombined className="size-5" />
                                <span className="flex-1">
                                    Как улучшить сценарий?
                                </span>
                                <ChevronRight className="size-4" />
                            </button>
                        </>
                    ) : (
                        <div className="py-6">
                            <Sparkle className="mb-5 size-8" />
                            <h3 className="text-2xl font-semibold tracking-tight">
                                Каким станет ваш город?
                            </h3>
                            <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                                Выберите пять мероприятий в каталоге. Я помогу
                                понять их влияние на районы, бюджет и качество
                                жизни.
                            </p>
                            <p className="mt-4 text-xs text-muted-foreground">
                                Сейчас выбран район{' '}
                                {getDistrict(selectedDistrict).name}.
                            </p>
                        </div>
                    )}
                    {messages.length > 0 && (
                        <div
                            aria-label="Диалог"
                            role="log"
                            aria-live="polite"
                            className="max-h-64 space-y-4 overflow-y-auto border-t border-border pt-4"
                        >
                            {messages.map((message, index) => (
                                <div key={index} className="space-y-3 text-sm">
                                    <p className="ml-5 bg-muted p-3 whitespace-pre-wrap">
                                        {message.question}
                                    </p>
                                    <p className="pr-2 leading-relaxed whitespace-pre-wrap text-muted-foreground">
                                        {message.answer}
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
                <form
                    className="mt-auto pt-4 pb-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        ask(question);
                    }}
                >
                    <div className="border border-foreground p-3 focus-within:ring-1 focus-within:ring-emerald-700">
                        <label className="sr-only" htmlFor="assistant-question">
                            Вопрос помощнику
                        </label>
                        <textarea
                            id="assistant-question"
                            value={question}
                            onChange={(event) =>
                                setQuestion(event.target.value)
                            }
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && !event.shiftKey) {
                                    event.preventDefault();
                                    event.currentTarget.form?.requestSubmit();
                                }
                            }}
                            maxLength={1000}
                            rows={2}
                            className="min-h-14 w-full resize-none bg-transparent text-sm leading-relaxed outline-none placeholder:text-muted-foreground"
                            placeholder="Спросите о рисках и компромиссах…"
                        />
                        <div className="flex items-end justify-between">
                            <button
                                type="button"
                                className="sim-icon-button border-0"
                                aria-label="Добавить вопрос о выбранном районе"
                                title={`Контекст: ${district.name}`}
                                onClick={() =>
                                    setQuestion(
                                        `Как изменятся показатели района ${district.name}?`,
                                    )
                                }
                            >
                                <Paperclip className="size-5" />
                            </button>
                            <button
                                type="submit"
                                disabled={!question.trim()}
                                aria-label="Отправить вопрос"
                                className="flex size-11 items-center justify-center bg-foreground text-background transition-colors hover:bg-foreground/80 disabled:cursor-not-allowed disabled:opacity-35"
                            >
                                <ArrowUp className="size-6 stroke-[1.5]" />
                            </button>
                        </div>
                    </div>
                    <p className="mt-3 text-[11px] text-muted-foreground">
                        Демо-помощник объясняет результаты расчёта
                    </p>
                </form>
            </div>
            <Dialog open={historyOpen} onOpenChange={setHistoryOpen}>
                <DialogContent className="max-h-[85dvh] overflow-y-auto rounded-none">
                    <DialogHeader>
                        <DialogTitle>История диалога</DialogTitle>
                        <DialogDescription>
                            Вопросы в этой сессии. При обновлении страницы
                            история сбрасывается.
                        </DialogDescription>
                    </DialogHeader>
                    {history.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            Пока нет вопросов. Начните диалог с помощником.
                        </p>
                    ) : (
                        history.map((message, index) => (
                            <article
                                key={index}
                                className="border-b border-border py-3 text-sm last:border-0"
                            >
                                <h3 className="font-medium">
                                    {message.question}
                                </h3>
                                <p className="mt-2 leading-relaxed whitespace-pre-wrap text-muted-foreground">
                                    {message.answer}
                                </p>
                            </article>
                        ))
                    )}
                </DialogContent>
            </Dialog>
        </aside>
    );
}

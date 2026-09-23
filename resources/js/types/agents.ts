export type RunStatus =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'cancelled';

export const statusLabels: Record<RunStatus, string> = {
    queued: 'В очереди',
    running: 'Выполняется',
    succeeded: 'Завершён',
    failed: 'Ошибка',
    cancelled: 'Отменён',
};

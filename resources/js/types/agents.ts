export type RunStatus =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'cancelled';

export type AgentRun = {
    id: string;
    input: string;
    output: string | null;
    error: string | null;
    status: RunStatus;
    driver: 'demo' | 'laravel';
    provider: string;
    model: string;
    prompt_version: string;
    limits: {
        max_steps: number;
        max_tokens: number;
        max_tool_calls: number;
        timeout: number;
    };
    usage: { prompt_tokens?: number; completion_tokens?: number } | null;
    tool_calls: number;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
};

export type Approval = {
    id: string;
    tool: string;
    status: 'pending' | 'approved' | 'rejected';
    arguments: { title: string; body: string };
};

export type RunEvent = {
    id: number;
    type: string;
    data: Record<string, unknown>;
    created_at: string;
};
export type Note = { id: string; title: string; body: string };

export const statusLabels: Record<RunStatus, string> = {
    queued: 'В очереди',
    running: 'Выполняется',
    succeeded: 'Завершён',
    failed: 'Ошибка',
    cancelled: 'Отменён',
};

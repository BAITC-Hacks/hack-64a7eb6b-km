import type { RunStatus } from '@/types/agents';

export type Selection = { measure_id: string; district_id: string | null };
export type Measure = {
    id: string;
    name: string;
    direction: string;
    scope: 'city' | 'district';
    cost: number;
    lag: number;
    effects: Record<string, number>;
};
export type District = {
    id: string;
    name: string;
    population: string;
    description: string;
    indicators: Record<string, number>;
};
export type Dataset = {
    version: string;
    budget: number;
    horizon: number;
    directions: Record<string, string>;
    indicators: Record<string, { name: string; weight: string }>;
    districts: District[];
    measures: Measure[];
    example: Selection[];
};
export type DistrictResult = Omit<District, 'indicators'> & {
    score: string;
    indicators: Record<string, string>;
};
export type SimulationResult = {
    score: string;
    average: string;
    minimum: string;
    cost: number;
    remaining: number;
    critical: { district_id: string; indicator: string; value: string }[];
    districts: DistrictResult[];
    effects: {
        measure_id: string;
        district_id: string;
        indicator: string;
        delta: string;
    }[];
    synergies: {
        measures: string[];
        district_id: string;
        indicator: string;
        delta: string;
    }[];
    clipping: { district_id: string; indicator: string; delta: string }[];
};
export type Alternative = {
    id: string;
    selections: Selection[];
    result: SimulationResult;
    delta: string;
    removed: Selection;
    added: Selection;
};
export type Scenario = {
    id: string;
    title: string;
    result: SimulationResult;
    simulation_dataset_id: string;
    calculator_version: string;
    source_scenario_id: string | null;
    created_at: string;
    selections: Selection[];
    alternatives: Alternative[];
};
export type Analysis = {
    summary: string;
    strengths: string[];
    risks: string[];
    tradeoffs: string[];
    recommendations: { alternative_id: string; reason: string }[];
};
export type AiRuntime = {
    configured_driver: 'auto' | 'demo' | 'laravel';
    driver: 'demo' | 'laravel';
    reason: 'missing_key' | 'forced_demo' | null;
};
export type ScenarioRun = {
    id: string;
    kind: 'scenario_analysis' | 'scenario_chat';
    input: string;
    output: string | null;
    output_data: Analysis | null;
    status: RunStatus;
    error: string | null;
    driver: string;
    created_at: string;
    activity: {
        id: number;
        type: 'tool.started' | 'tool.completed' | 'approval.requested';
        tool: 'evaluate_scenario' | 'propose_scenario';
    }[];
};
export type ScenarioApproval = {
    id: string;
    tool: 'propose_scenario';
    status: 'pending' | 'approved' | 'rejected';
    run_status: RunStatus;
    scenario_id: string | null;
    arguments: {
        title: string;
        result: SimulationResult;
        selections: Selection[];
    };
};

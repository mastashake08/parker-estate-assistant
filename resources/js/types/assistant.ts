export type McpTransport = 'stdio' | 'http';

export type McpServer = {
    id: number;
    name: string;
    transport: McpTransport;
    command: string | null;
    args: string[] | null;
    url: string | null;
    oauth_client_id: string | null;
    oauth_scope: string | null;
    signed_in: boolean;
    timeout: number | null;
    enabled: boolean;
};

export type AssistantTool = {
    key: string;
    name: string;
    description: string;
    enabled: boolean;
};

export type McpServerTools = {
    id: number;
    name: string;
    error: string | null;
    signInRequired: boolean;
    tools: AssistantTool[];
};

export type Skill = {
    id: number;
    name: string;
    description: string;
    instructions: string;
    enabled: boolean;
};

export type ConversationSummary = {
    id: string;
    title: string;
    updated_at: string;
};

export type ToolUse = {
    name: string;
    arguments: Record<string, unknown>;
    result: string;
    failed: boolean;
};

export type WebSource = {
    title: string;
    url: string;
};

export type ConversationMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string | null;
    html: string | null;
    created_at: string;
    sources: WebSource[];
    tools: ToolUse[];
};

export type PendingApproval = {
    id: string;
    tool: string;
    arguments: Record<string, unknown>;
    reason: string | null;
};

export type ConversationDocument = {
    id: number;
    name: string;
    mime_type: string;
    size: number;
};

export type EnabledCount = {
    enabled: number;
    total: number;
};

export type DashboardStats = {
    conversations: number;
    documents: number;
    skills: EnabledCount;
    mcpServers: EnabledCount;
};

export type ModelStatus = {
    name: string;
    installed: boolean;
    available: string[];
};

export type Conversation = {
    id: string;
    title: string;
    model: string | null;
    messages: ConversationMessage[];
    approvals: PendingApproval[];
    documents: ConversationDocument[];
};

export type RoutineFrequency =
    | 'daily'
    | 'weekdays'
    | 'weekly'
    | 'hourly'
    | 'custom';

export type RoutineRunStatus =
    | 'running'
    | 'completed'
    | 'waiting_for_approval'
    | 'failed';

export type RoutineRun = {
    id: number;
    status: RoutineRunStatus;
    error: string | null;
    conversation_id: string | null;
    created_at: string;
    finished_at: string | null;
};

export type Routine = {
    id: number;
    name: string;
    instructions: string;
    frequency: RoutineFrequency;
    time: string | null;
    day_of_week: number | null;
    every_hours: number | null;
    cron: string;
    timezone: string;
    model: string | null;
    tools: string[];
    auto_approve: boolean;
    deliver_email: boolean;
    deliver_sms: boolean;
    sms_to: string | null;
    enabled: boolean;
    next_run_at: string | null;
    last_run_at: string | null;
    schedule: string;
    runs: RoutineRun[];
};

export type ToolCatalog = {
    builtin: (AssistantTool & { needsApproval: boolean })[];
    servers: McpServerTools[];
};

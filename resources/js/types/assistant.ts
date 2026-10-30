export type McpTransport = 'stdio' | 'http';

export type McpServer = {
    id: number;
    name: string;
    transport: McpTransport;
    command: string | null;
    args: string[] | null;
    url: string | null;
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

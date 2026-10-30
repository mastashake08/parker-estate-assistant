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

export type ConversationMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string | null;
    created_at: string;
    tools: ToolUse[];
};

export type Conversation = {
    id: string;
    title: string;
    messages: ConversationMessage[];
};

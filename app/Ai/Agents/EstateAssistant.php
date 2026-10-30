<?php

namespace App\Ai\Agents;

use Closure;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Skills\Skill;
use Laravel\Mcp\Client\Primitives\Tool as McpTool;
use Stringable;

class EstateAssistant implements Agent, Conversational, HasSkills, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Create a new assistant with the tools and skills resolved for the current user.
     *
     * @param  array<int, Agent|Tool|ProviderTool|McpTool>  $tools
     * @param  array<int, Closure|Skill|string>  $skills
     */
    public function __construct(
        protected array $tools = [],
        protected array $skills = [],
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return config('assistant.instructions');
    }

    /**
     * Get the tools available to the agent.
     *
     * @return list<Agent|Tool|ProviderTool|McpTool>
     */
    public function tools(): iterable
    {
        return array_values($this->tools);
    }

    /**
     * Get the skills available to the agent.
     *
     * @return list<Closure|Skill|string>
     */
    public function skills(): iterable
    {
        return array_values($this->skills);
    }

    /**
     * Get the maximum number of conversation messages to include in context.
     */
    protected function maxConversationMessages(): int
    {
        return (int) config('assistant.memory_messages', 50);
    }
}

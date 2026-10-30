<?php

namespace App\Ai;

/**
 * Small local models sometimes send a tool's arguments wrapped in a copy of its
 * schema, as {"type": "object", "properties": {"to": "..."}}. This recovers
 * the arguments they meant.
 */
class ToolArguments
{
    /**
     * Unwrap arguments sent inside a copy of the schema; any others are returned unchanged.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    public static function unwrap(array $arguments): array
    {
        $isWrapped = ($arguments['type'] ?? null) === 'object'
            && is_array($arguments['properties'] ?? null)
            && array_diff(array_keys($arguments), ['type', 'properties', 'required']) === [];

        return $isWrapped ? $arguments['properties'] : $arguments;
    }
}

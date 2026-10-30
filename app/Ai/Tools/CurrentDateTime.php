<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CurrentDateTime implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the current date and time, optionally in a given timezone.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $timezone = $request->string('timezone')->toString() ?: config('app.timezone');

        try {
            return now($timezone)->toDayDateTimeString().' ('.$timezone.')';
        } catch (\Exception) {
            return "Unknown timezone [{$timezone}].";
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'timezone' => $schema->string()->description('An IANA timezone such as America/New_York.'),
        ];
    }
}

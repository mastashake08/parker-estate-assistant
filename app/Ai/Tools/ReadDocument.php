<?php

namespace App\Ai\Tools;

use App\Ai\ToolArguments;
use App\Models\Document;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Reads the documents shared in the current conversation a page at a time, for
 * documents too long to fit in the assistant's instructions.
 */
class ReadDocument implements Tool
{
    /**
     * @param  Collection<int, Document>  $documents  The documents in the current conversation.
     */
    public function __construct(protected Collection $documents) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Read part of a document the user shared in this conversation, starting at a character offset. Use it when a document in your instructions was shortened.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $request = new Request(ToolArguments::unwrap($request->all()));
        $name = $request->string('name')->trim()->toString();

        $document = $this->documents->first(fn (Document $document) => $document->name === $name)
            ?? $this->documents->first(fn (Document $document) => strcasecmp($document->name, $name) === 0);

        if ($document === null) {
            return "There is no document named [{$name}] in this conversation. Documents: {$this->documents->pluck('name')->implode(', ')}.";
        }

        $length = mb_strlen($document->text);
        $offset = max(0, $request->integer('offset'));

        if ($offset >= $length) {
            return "[Offset {$offset} is past the end of the document ({$length} characters).]";
        }

        $page = mb_substr($document->text, $offset, config()->integer('assistant.documents.page_characters'));
        $end = $offset + mb_strlen($page);

        $position = $end >= $length
            ? "[Characters {$offset} to {$end} of {$length}. This is the end of the document.]"
            : "[Characters {$offset} to {$end} of {$length}. Continue from offset {$end}.]";

        return "{$page}\n\n{$position}";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The document\'s file name, as given in your instructions.')->required(),
            'offset' => $schema->integer()->description('The character to start reading from (0 for the beginning).')->min(0),
        ];
    }
}

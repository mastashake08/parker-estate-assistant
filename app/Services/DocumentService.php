<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DocumentService
{
    public function __construct(protected DocumentTextExtractor $extractor) {}

    /**
     * Read the text from uploaded documents before anything is stored.
     *
     * @param  array<int, UploadedFile>  $files
     * @return list<array{file: UploadedFile, text: string}>
     *
     * @throws ValidationException When a document has no text that can be read.
     */
    public function read(array $files): array
    {
        $read = [];

        foreach (array_values($files) as $index => $file) {
            $name = $file->getClientOriginalName();

            try {
                $text = $this->extractor->extract($file->getRealPath(), $file->getClientOriginalExtension());
            } catch (RuntimeException) {
                throw ValidationException::withMessages([
                    "documents.{$index}" => __('Could not read :name. Check that it is a valid :type file.', [
                        'name' => $name,
                        'type' => strtoupper($file->getClientOriginalExtension()),
                    ]),
                ]);
            }

            if ($text === '') {
                throw ValidationException::withMessages([
                    "documents.{$index}" => __('No text could be read from :name. Scanned PDFs (pictures of text) are not supported.', ['name' => $name]),
                ]);
            }

            $read[] = ['file' => $file, 'text' => $text];
        }

        return $read;
    }

    /**
     * Store documents that have been read in one of the user's conversations.
     *
     * @param  list<array{file: UploadedFile, text: string}>  $documents
     */
    public function store(User $user, string $conversationId, array $documents): void
    {
        foreach ($documents as ['file' => $file, 'text' => $text]) {
            $path = $file->storeAs(
                "documents/{$user->id}",
                Str::uuid7().'.'.strtolower($file->getClientOriginalExtension()),
                'local',
            );

            $user->documents()->create([
                'conversation_id' => $conversationId,
                'name' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'path' => $path,
                'text' => $text,
            ]);
        }
    }

    /**
     * Get the documents shared in one of the user's conversations, oldest first.
     *
     * @return Collection<int, Document>
     */
    public function forConversation(User $user, string $conversationId): Collection
    {
        return $user->documents()
            ->where('conversation_id', $conversationId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Delete a conversation's documents and their files.
     */
    public function deleteForConversation(string $conversationId): void
    {
        $documents = Document::query()->where('conversation_id', $conversationId)->get();

        DB::transaction(fn () => Document::query()->whereKey($documents->modelKeys())->delete());

        Storage::disk('local')->delete($documents->pluck('path')->all());
    }
}

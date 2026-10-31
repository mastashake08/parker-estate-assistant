<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The result of a routine run, emailed to the routine's owner.
 */
class RoutineResult extends Mailable
{
    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $routineName,
        public string $reply,
        public string $conversationUrl,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->routineName);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(text: 'mail.routine-result');
    }
}

<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An email the assistant writes, sent as plain text once the user approves it.
 */
class AssistantEmail extends Mailable
{
    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $emailSubject,
        public string $body,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(text: 'mail.assistant-email');
    }
}

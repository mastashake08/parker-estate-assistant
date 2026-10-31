<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

/**
 * The result of a routine run, texted to the number on the routine.
 */
class RoutineResultSms extends Notification
{
    /**
     * Twilio splits long texts and refuses those over 1,600 characters, so the reply is shortened to leave room for the link.
     */
    protected const MAX_REPLY_CHARACTERS = 1_200;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $routineName,
        public string $reply,
        public string $conversationUrl,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [TwilioChannel::class];
    }

    /**
     * Get the Twilio representation of the notification.
     */
    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        return new TwilioSmsMessage(
            "{$this->routineName}\n\n".Str::limit($this->reply, self::MAX_REPLY_CHARACTERS)."\n\n{$this->conversationUrl}",
        );
    }
}

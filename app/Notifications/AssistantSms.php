<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

/**
 * A text message the assistant writes, sent through Twilio once the user approves it.
 */
class AssistantSms extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public string $message) {}

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
        return new TwilioSmsMessage($this->message);
    }
}

<?php

use App\Ai\Tools\SendEmail;
use App\Ai\Tools\SendSms;
use App\Mail\AssistantEmail;
use App\Notifications\AssistantSms;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Tools\Request;
use NotificationChannels\Twilio\Twilio;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioConfig;

test('the email tool sends the email', function () {
    Mail::fake();

    $result = (string) app(SendEmail::class)->handle(new Request([
        'to' => 'jane@example.com',
        'subject' => 'Gate code',
        'body' => "Hi Jane,\n\nThe code is 4321.",
    ]));

    expect($result)->toBe('Sent the email to jane@example.com.');
    Mail::assertSent(AssistantEmail::class, fn (AssistantEmail $mail) => $mail->hasTo('jane@example.com')
        && $mail->hasSubject('Gate code')
        && $mail->body === "Hi Jane,\n\nThe code is 4321.");
});

test('the email is sent as plain text without escaping', function () {
    $mail = new AssistantEmail('Hi', 'Fish & chips <today>');

    $mail->assertSeeInText('Fish & chips <today>');
});

test('the email tool refuses an invalid address', function () {
    Mail::fake();

    expect((string) app(SendEmail::class)->handle(new Request(['to' => 'not-an-email', 'subject' => 'Hi', 'body' => 'Hi'])))
        ->toBe('Refusing to send: [not-an-email] is not a valid email address.');

    Mail::assertNothingSent();
});

test('the sms tool sends the text message', function () {
    Notification::fake();

    $result = (string) app(SendSms::class)->handle(new Request(['to' => '+15025550123', 'message' => 'Running late.']));

    expect($result)->toBe('Sent the text message to +15025550123.');
    Notification::assertSentOnDemand(AssistantSms::class, fn (AssistantSms $sms, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routeNotificationFor(TwilioChannel::class) === '+15025550123'
        && $sms->toTwilio($notifiable)->content === 'Running late.');
});

test('the sms tool needs an international phone number', function (string $number) {
    Notification::fake();

    expect((string) app(SendSms::class)->handle(new Request(['to' => $number, 'message' => 'Hi'])))
        ->toBe("Refusing to send: [{$number}] is not a phone number in international format, such as +15025550123.");

    Notification::assertNothingSent();
})->with(['no country code' => '5025550123', 'letters' => '+1 502 CALL NOW', 'too short' => '+1']);

test('a failed sms is reported to the assistant', function (int $twilioErrorCode) {
    $twilio = Mockery::mock(Twilio::class);
    $twilio->config = new TwilioConfig(config('twilio-notification-channel'));
    $twilio->shouldReceive('sendMessage')->andThrow(new Exception('Twilio refused the message.', $twilioErrorCode));
    app()->instance(Twilio::class, $twilio);

    expect((string) app(SendSms::class)->handle(new Request(['to' => '+15025550123', 'message' => 'Hi'])))
        ->toBe('Could not send the text message: Twilio refused the message.');
})->with([
    'service error' => 20003,
    'unverified number, which the channel ignores by default' => 21608,
]);

test('sending email and text messages always needs approval', function (string $tool, array $arguments, string $reason) {
    expect(app($tool)->shouldRequestApproval(new Request($arguments))?->reason)->toBe($reason);
})->with([
    'email' => [SendEmail::class, ['to' => 'jane@example.com', 'subject' => 'Gate code', 'body' => 'Hi'], 'Send an email to jane@example.com: "Gate code"'],
    'sms' => [SendSms::class, ['to' => '+15025550123', 'message' => 'Hi'], 'Send a text message to +15025550123'],
]);

test('arguments a model wraps in a copy of the schema are unwrapped', function () {
    Mail::fake();
    $wrapped = ['type' => 'object', 'properties' => ['to' => 'jane@example.com', 'subject' => 'Gate code', 'body' => 'It is 4321.']];
    $tool = app(SendEmail::class);

    expect($tool->shouldRequestApproval(new Request($wrapped))?->reason)->toBe('Send an email to jane@example.com: "Gate code"')
        ->and((string) $tool->handle(new Request($wrapped)))->toBe('Sent the email to jane@example.com.');
});

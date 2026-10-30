<?php

namespace App\Ai\Tools;

use App\Ai\ToolArguments;
use App\Notifications\AssistantSms;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use NotificationChannels\Twilio\TwilioChannel;
use Stringable;
use Throwable;

/**
 * Sends a text message through Twilio. The user must approve every message before it is sent.
 */
class SendSms implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Send an SMS text message to a phone number in international format, such as +15025550123. The user is shown the message and must approve it before it is sent.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $request = new Request(ToolArguments::unwrap($request->all()));

        $to = $request->string('to')->trim()->toString();

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $to)) {
            return "Refusing to send: [{$to}] is not a phone number in international format, such as +15025550123.";
        }

        try {
            Notification::route(TwilioChannel::class, $to)
                ->notify(new AssistantSms($request->string('message')->toString()));
        } catch (Throwable $exception) {
            return "Could not send the text message: {$exception->getMessage()}";
        }

        return "Sent the text message to {$to}.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->string()->description('The phone number in international format, such as +15025550123.')->required(),
            'message' => $schema->string()->description('The text of the message.')->required(),
        ];
    }

    /**
     * Every text message needs the user's approval, which shows them the recipient.
     */
    protected function needsApproval(Request $request): Approval
    {
        $request = new Request(ToolArguments::unwrap($request->all()));

        return Approval::required("Send a text message to {$request->string('to')->trim()}");
    }
}

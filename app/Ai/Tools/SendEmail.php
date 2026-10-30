<?php

namespace App\Ai\Tools;

use App\Ai\ToolArguments;
use App\Mail\AssistantEmail;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Sends an email. The user must approve every email before it is sent.
 */
class SendEmail implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Send a plain-text email. The user is shown the email and must approve it before it is sent.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $request = new Request(ToolArguments::unwrap($request->all()));

        $to = $request->string('to')->trim()->toString();

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return "Refusing to send: [{$to}] is not a valid email address.";
        }

        try {
            Mail::to($to)->send(new AssistantEmail(
                $request->string('subject')->trim()->toString(),
                $request->string('body')->toString(),
            ));
        } catch (Throwable $exception) {
            return "Could not send the email: {$exception->getMessage()}";
        }

        return "Sent the email to {$to}.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->string()->description('The recipient\'s email address.')->required(),
            'subject' => $schema->string()->description('The subject line.')->required(),
            'body' => $schema->string()->description('The email text.')->required(),
        ];
    }

    /**
     * Every email needs the user's approval, which shows them the recipient and subject.
     */
    protected function needsApproval(Request $request): Approval
    {
        $request = new Request(ToolArguments::unwrap($request->all()));

        return Approval::required("Send an email to {$request->string('to')->trim()}: \"{$request->string('subject')->trim()}\"");
    }
}

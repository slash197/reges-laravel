<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Outbox;

use Illuminate\Contracts\Events\Dispatcher;
use slash197\Reges\Laravel\Events\MessageAccepted;
use slash197\Reges\Laravel\Events\MessageRejected;
use slash197\Reges\Laravel\Events\ResultReceived;

/**
 * Matches a result to the stored message it answers and records the outcome.
 */
final class RecordResult
{
    public function __construct(private readonly Dispatcher $events)
    {
    }

    public function handle(ResultReceived $event): void
    {
        $result = $event->result;

        if ($result->messageId === null) {
            return;
        }

        $message = OutboxMessage::query()
            ->where('registry', $event->registry)
            ->where('message_id', $result->messageId)
            ->first();

        // A message sent by other means, or by another application on the same registry
        if ($message === null) {
            return;
        }

        $message->update([
            'status' => $result->isSuccess() ? MessageStatus::Accepted : MessageStatus::Rejected,
            'resolved_at' => now(),
            'result_code' => $result->code,
            'result_description' => $result->description,
            'ref' => $result->ref,
            'sec_ref' => $result->secRef,
            'error' => $result->isSuccess() ? null : $result->description,
        ]);

        $this->events->dispatch(
            $result->isSuccess() ? new MessageAccepted($message, $result) : new MessageRejected($message, $result),
        );
    }
}

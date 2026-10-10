<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Events;

use slash197\Reges\Laravel\Outbox\OutboxMessage;

/**
 * A stored message could not be delivered before the retry window closed. $message->error holds the last failure.
 */
final readonly class MessageFailed
{
    public function __construct(
        public OutboxMessage $message,
    ) {
    }
}

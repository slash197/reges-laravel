<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Events;

use slash197\Reges\Laravel\Outbox\OutboxMessage;
use slash197\Reges\Results\Result;

/**
 * REGES refused a stored message: on submission, when there is no result, or in its result. $message->error says why.
 */
final readonly class MessageRejected
{
    public function __construct(
        public OutboxMessage $message,
        public ?Result $result = null,
    ) {
    }
}

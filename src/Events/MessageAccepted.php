<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Events;

use slash197\Reges\Laravel\Outbox\OutboxMessage;
use slash197\Reges\Results\Result;

/**
 * REGES carried out the operation of a stored message. $message->ref is the id of what it created.
 */
final readonly class MessageAccepted
{
    public function __construct(
        public OutboxMessage $message,
        public Result $result,
    ) {
    }
}

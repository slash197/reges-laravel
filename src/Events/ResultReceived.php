<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Events;

use slash197\Reges\Results\Result;

/**
 * A result was read from a registry's queue. The queue moves past it once
 * every listener has returned; if one throws, the same result is delivered
 * again on the next read.
 */
final readonly class ResultReceived
{
    /**
     * @param string|null $registry The label the application gave the registry when reading its queue;
     *                              null for the client bound in the container
     */
    public function __construct(
        public Result $result,
        public ?string $registry = null,
    ) {
    }
}

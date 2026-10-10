<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use slash197\Reges\Laravel\Events\ResultReceived;
use slash197\Reges\Reges;
use slash197\Reges\Results\Result;

/**
 * Reads a registry's result queue and hands each result to the application
 * as a ResultReceived event.
 */
final class ResultReader
{
    public function __construct(private readonly Dispatcher $events)
    {
    }

    /**
     * Reads results until the queue is empty or the limit is reached. A result
     * is committed only after its listeners returned, so a listener that
     * throws stops the read and leaves that result at the head of the queue.
     *
     * @param string|null $registry   Passed on in the event, for applications reading several registries
     * @param string|null $consumerId Reads the queue as a separate consumer, see Reges::results()
     * @param int|null    $limit      Most results to read in this call; null reads until the queue is empty
     *
     * @return int Number of results read and committed
     */
    public function drain(Reges $reges, ?string $registry = null, ?string $consumerId = null, ?int $limit = null): int
    {
        $results = $reges->results($consumerId);
        $read = 0;

        while ($limit === null || $read < $limit) {
            $result = $results->consume(function (Result $result) use ($registry): void {
                $this->events->dispatch(new ResultReceived($result, $registry));
            });

            if ($result === null) {
                break;
            }

            $read++;
        }

        return $read;
    }
}

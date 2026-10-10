<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Outbox;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use slash197\Reges\Exception\ValidationException;
use slash197\Reges\Laravel\Registries;
use slash197\Reges\Message;

/**
 * Stores messages to be sent by reges:send, in the order they were queued.
 */
final class Outbox
{
    public function __construct(
        private readonly Registries $registries,
        private readonly Repository $cache,
    ) {
    }

    /**
     * Validates the message, puts it in its final form and stores it. Nothing
     * is stored when validation fails.
     *
     * Messages given the same sequence are sent one at a time, in the order
     * they were queued, each waiting for the result of the one before. Use
     * one sequence per employee and per contract, since REGES refuses a
     * contract's messages when they arrive out of order.
     *
     * @param string|null          $sequence Messages sharing it are sent strictly in order; null sends independently
     * @param Model|null           $subject  The application's record the message is about
     * @param array<string, mixed> $meta     Anything the application wants back when the result arrives
     * @param string|null          $user     The person on whose behalf the message is sent; falls back to reges.user
     *
     * @throws ValidationException
     */
    public function queue(
        Message $message,
        ?string $sequence = null,
        string $registry = Registries::DEFAULT,
        ?Model $subject = null,
        array $meta = [],
        ?string $user = null,
    ): OutboxMessage {
        $reges = $this->registries->client($registry);

        $store = function () use ($reges, $message, $sequence, $registry, $subject, $meta, $user): OutboxMessage {
            // Built here, under the lock, so that the order of the rows is the
            // order of the timestamps REGES compares
            $envelope = $reges->envelope($message, $user);

            return OutboxMessage::query()->create([
                'registry' => $registry,
                'message_id' => $envelope->messageId(),
                'operation' => $envelope->operation() ?? $message->operation->value,
                'sequence' => $sequence,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject === null ? null : (string) $subject->getKey(),
                'status' => MessageStatus::Pending,
                'payload' => $envelope->toJson(),
                'meta' => $meta === [] ? null : $meta,
                'next_attempt_at' => now(),
            ]);
        };

        $locks = $this->cache->getStore();

        if ($sequence === null || !$locks instanceof LockProvider) {
            return $store();
        }

        return $locks->lock('reges:outbox:' . sha1($registry . '|' . $sequence), 30)->block(10, $store);
    }
}

<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Outbox;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use slash197\Reges\Exception\ApiException;
use slash197\Reges\Laravel\Events\MessageFailed;
use slash197\Reges\Laravel\Events\MessageRejected;
use slash197\Reges\Laravel\Registries;

/**
 * Sends the stored messages that are due, keeping each sequence in order.
 */
final class OutboxSender
{
    /** Attempts made at the plain retry delay before it starts doubling. */
    private const STEADY_ATTEMPTS = 60;

    private const MAX_DELAY_SECONDS = 43200;

    public function __construct(
        private readonly Registries $registries,
        private readonly Dispatcher $events,
        private readonly Repository $config,
        private readonly ExceptionHandler $exceptions,
    ) {
    }

    /**
     * @param int|null $limit Most messages to attempt in this call; null attempts every due message
     *
     * @return int Number of messages REGES queued
     */
    public function sendDue(?int $limit = null): int
    {
        $sent = 0;
        $attempted = 0;

        foreach ($this->due()->lazyById(100) as $message) {
            if ($limit !== null && $attempted >= $limit) {
                break;
            }

            if ($this->isHeldBack($message)) {
                continue;
            }

            if ($this->isExpired($message)) {
                $this->giveUp($message);

                continue;
            }

            if (!$this->claim($message)) {
                continue;
            }

            $attempted++;
            $sent += $this->send($message) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Pending messages whose time has come, and messages left in "sending" by
     * a process that died. Whether REGES received those is unknown, so they
     * are sent again, as a message is after a timeout.
     *
     * @return Builder<OutboxMessage>
     */
    private function due(): Builder
    {
        return OutboxMessage::query()->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query->where('status', MessageStatus::Pending)->where('next_attempt_at', '<=', now());
                })
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('status', MessageStatus::Sending)
                        ->where('last_attempt_at', '<=', now()->subSeconds($this->seconds('sending_timeout', 600)));
                });
        });
    }

    private function isHeldBack(OutboxMessage $message): bool
    {
        if ($message->sequence === null) {
            return false;
        }

        return OutboxMessage::query()
            ->where('registry', $message->registry)
            ->where('sequence', $message->sequence)
            ->where('id', '<', $message->id)
            ->whereIn('status', MessageStatus::unresolved())
            ->exists();
    }

    private function isExpired(OutboxMessage $message): bool
    {
        $window = $this->seconds('give_up_after', 259200);

        return $window > 0 && $message->created_at !== null && $message->created_at->lte(now()->subSeconds($window));
    }

    /**
     * Takes the message for this process; false when another one got there first.
     */
    private function claim(OutboxMessage $message): bool
    {
        $claimed = OutboxMessage::query()
            ->whereKey($message->id)
            ->where('status', $message->status)
            ->where('attempts', $message->attempts)
            ->update([
                'status' => MessageStatus::Sending,
                'attempts' => $message->attempts + 1,
                'last_attempt_at' => now(),
            ]) === 1;

        if ($claimed) {
            $message->refresh();
        }

        return $claimed;
    }

    private function send(OutboxMessage $message): bool
    {
        try {
            $receipt = $this->registries->client($message->registry)->send($message->envelope());
        } catch (ApiException $exception) {
            if ($exception->isRetryable()) {
                $this->retryLater($message, $exception->getMessage());
            } else {
                $this->reject($message, $exception);
            }

            return false;
        } catch (\Throwable $exception) {
            // Refused credentials, an unknown registry, a payload that cannot
            // be decrypted: none of it is cured by dropping the message
            $this->exceptions->report($exception);
            $this->retryLater($message, $exception->getMessage());

            return false;
        }

        $message->update([
            'status' => MessageStatus::Sent,
            'response_id' => $receipt->responseId,
            'sent_at' => now(),
            'error' => null,
        ]);

        return true;
    }

    private function retryLater(OutboxMessage $message, string $error): void
    {
        $doublings = max(0, $message->attempts - self::STEADY_ATTEMPTS);
        $delay = (int) min($this->seconds('retry_delay', 60) * 2 ** min($doublings, 20), self::MAX_DELAY_SECONDS);

        $message->update([
            'status' => MessageStatus::Pending,
            'next_attempt_at' => now()->addSeconds($delay),
            'error' => $error,
        ]);
    }

    private function reject(OutboxMessage $message, ApiException $exception): void
    {
        $message->update([
            'status' => MessageStatus::Rejected,
            'resolved_at' => now(),
            'error' => trim($exception->getMessage() . ' ' . mb_substr($exception->body, 0, 4000)),
        ]);

        $this->events->dispatch(new MessageRejected($message));
    }

    private function giveUp(OutboxMessage $message): void
    {
        $gaveUp = OutboxMessage::query()
            ->whereKey($message->id)
            ->where('status', $message->status)
            ->update(['status' => MessageStatus::Failed, 'resolved_at' => now()]) === 1;

        if ($gaveUp) {
            $this->events->dispatch(new MessageFailed($message->refresh()));
        }
    }

    private function seconds(string $key, int $default): int
    {
        $value = $this->config->get("reges.outbox.{$key}");

        return is_numeric($value) ? (int) $value : $default;
    }
}

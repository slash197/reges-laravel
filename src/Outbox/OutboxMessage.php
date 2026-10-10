<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Outbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use slash197\Reges\Envelope;

/**
 * A message to REGES and what became of it.
 *
 * @property int                       $id
 * @property string                    $registry
 * @property string                    $message_id
 * @property string                    $operation
 * @property string|null               $sequence
 * @property string|null               $subject_type
 * @property string|null               $subject_id
 * @property MessageStatus             $status
 * @property string                    $payload            The message as sent, encrypted at rest
 * @property array<string, mixed>|null $meta
 * @property int                       $attempts
 * @property Carbon|null               $next_attempt_at
 * @property Carbon|null               $last_attempt_at
 * @property Carbon|null               $sent_at
 * @property Carbon|null               $resolved_at
 * @property string|null               $response_id
 * @property string|null               $error              Why the last attempt failed or the message was refused, encrypted at rest
 * @property string|null               $result_code
 * @property string|null               $result_description What REGES said about the outcome, encrypted at rest
 * @property string|null               $ref                Id of what the operation created, see Result::$ref
 * @property string|null               $sec_ref
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 */
class OutboxMessage extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('reges.outbox.table');

        return is_string($table) && $table !== '' ? $table : 'reges_messages';
    }

    /**
     * The application's record the message is about, if one was given.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function envelope(): Envelope
    {
        return Envelope::fromJson($this->payload);
    }

    /**
     * Withdraws a message that has not been sent yet.
     *
     * @return bool False when it was already sent or resolved
     */
    public function cancel(): bool
    {
        $cancelled = static::query()
            ->whereKey($this->getKey())
            ->where('status', MessageStatus::Pending)
            ->update(['status' => MessageStatus::Cancelled, 'resolved_at' => now()]) === 1;

        $this->refresh();

        return $cancelled;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'payload' => 'encrypted',
            // What REGES answers quotes names and CNPs back
            'error' => 'encrypted',
            'result_description' => 'encrypted',
            'meta' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}

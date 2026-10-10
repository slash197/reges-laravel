<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Outbox;

enum MessageStatus: string
{
    /** Waiting to be sent, for the first time or again after a transient failure. */
    case Pending = 'pending';

    /** Being sent right now. */
    case Sending = 'sending';

    /** Queued by REGES; its result has not arrived yet. */
    case Sent = 'sent';

    /** REGES carried the operation out. */
    case Accepted = 'accepted';

    /** REGES refused the message, on submission or in its result. */
    case Rejected = 'rejected';

    /** Could not be delivered before the retry window closed. */
    case Failed = 'failed';

    /** Withdrawn by the application before it was sent. */
    case Cancelled = 'cancelled';

    /**
     * Statuses that hold back the later messages of the same sequence.
     *
     * @return list<self>
     */
    public static function unresolved(): array
    {
        return [self::Pending, self::Sending, self::Sent];
    }
}

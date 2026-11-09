<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions;

/**
 * A redelivery of a provider event another delivery is still processing, under
 * `purchases.audit.deduplicate`. Nothing was recorded: answer with a non-2xx status (the webhook
 * route answers 409) so the store delivers it again later — by then it is either done, and the
 * repeat is skipped, or its claim has timed out and the redelivery takes it over.
 */
final class EventInProgressException extends Exception
{
    public static function for(string $provider, string $eventId): self
    {
        return new self("The {$provider} event [{$eventId}] is still being processed; deliver it again later.");
    }
}

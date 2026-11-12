<?php

declare(strict_types=1);

namespace App\Billing\Service;

/**
 * What the webhook handler did with an event. Every outcome is answered with a 2xx:
 * Stripe must not retry any of them.
 */
enum WebhookOutcome: string
{
    /** The shop subscription was updated. */
    case Applied = 'applied';

    /** The event had already been processed. */
    case Duplicate = 'duplicate';

    /** Late or irrelevant event about a known shop: recorded, nothing changed. */
    case Skipped = 'skipped';

    /** Unknown event type, or no shop could be found for it. */
    case Ignored = 'ignored';
}

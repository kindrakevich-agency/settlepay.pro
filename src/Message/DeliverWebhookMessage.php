<?php

namespace App\Message;

/**
 * "Please attempt to POST a queued webhook delivery." Handler retries
 * via Messenger's exponential backoff (see messenger.yaml).
 */
final class DeliverWebhookMessage
{
    public function __construct(public readonly int $deliveryId) {}
}

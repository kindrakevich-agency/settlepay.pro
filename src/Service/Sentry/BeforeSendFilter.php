<?php

namespace App\Service\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Last-chance filter that runs before an event is sent to Sentry.
 *
 *   - Drops known-noise errors we never want paged on.
 *   - Scrubs PII out of request data (full emails, full wallet addresses).
 *
 * Wired into config/packages/sentry.yaml under `options.before_send` as a
 * plain `Class::method` callable string — Sentry config doesn't resolve
 * Symfony service refs (no `@`), so this stays static + dependency-free.
 */
final class BeforeSendFilter
{
    public static function filter(Event $event, ?EventHint $hint = null): ?Event
    {
        // Drop noisy non-actionable errors entirely.
        foreach ($event->getExceptions() as $exception) {
            $msg = $exception->getValue();
            if ($msg === null) {
                continue;
            }
            // Wallet-side rejections come from the user clicking "reject"
            // in MetaMask — not a server bug.
            if (str_contains($msg, 'User rejected the request')) {
                return null;
            }
            // RPC provider rate-limit / temporary-outage messages —
            // the listener already retries on the next tick.
            if (str_contains($msg, 'rate limit')
                || str_contains($msg, 'no backend is currently healthy')
                || str_contains($msg, 'Request timeout on the free tier')) {
                return null;
            }
        }

        // Scrub PII from request payload before sending.
        $request = $event->getRequest();
        if (!empty($request)) {
            // Mask the body of POST forms — passwords + tokens should never
            // ride along to Sentry.
            unset($request['data']);
            $event->setRequest($request);
        }

        return $event;
    }
}

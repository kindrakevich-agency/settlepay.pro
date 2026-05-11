<?php

namespace App\Service\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry before_send filter — drops known-noise events and scrubs PII
 * out of POST bodies before they leave the box.
 *
 * Wired via the documented sentry-symfony 5.x factory pattern:
 *   sentry.options.before_send → 'sentry.callback.before_send'
 *   services.sentry.callback.before_send.factory → [this, 'getCallable']
 *
 * The factory returns the actual callable, which Sentry stores on
 * Sentry\Options::setBeforeSendCallback().
 *
 * https://docs.sentry.io/platforms/php/guides/symfony/configuration/symfony-options/
 */
final class BeforeSendFilter
{
    public function getCallable(): callable
    {
        return static function (Event $event, ?EventHint $hint = null): ?Event {
            // Drop noisy non-actionable errors entirely.
            foreach ($event->getExceptions() as $exception) {
                $msg = $exception->getValue();
                if ($msg === null) {
                    continue;
                }
                // Wallet-side rejections come from the user clicking
                // "reject" in MetaMask — not a server bug.
                if (str_contains($msg, 'User rejected the request')) {
                    return null;
                }
                // RPC provider rate-limit / temporary-outage messages.
                // The listener already retries on the next tick.
                if (str_contains($msg, 'rate limit')
                    || str_contains($msg, 'no backend is currently healthy')
                    || str_contains($msg, 'Request timeout on the free tier')) {
                    return null;
                }
            }

            // Scrub the POST body — passwords + CSRF tokens never ship.
            $request = $event->getRequest();
            if (!empty($request)) {
                unset($request['data']);
                $event->setRequest($request);
            }

            return $event;
        };
    }
}

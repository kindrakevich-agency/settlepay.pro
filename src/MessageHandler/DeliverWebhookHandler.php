<?php

namespace App\MessageHandler;

use App\Message\DeliverWebhookMessage;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Notification\WebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POSTs the queued webhook payload. Retries are handled by Symfony
 * Messenger's `async` retry strategy (3 attempts, exponential backoff)
 * — we just throw on failure to trigger a retry.
 *
 * Each request carries three headers receivers care about:
 *   - X-Settlepay-Event       e.g. "invoice.paid"
 *   - X-Settlepay-Timestamp   unix seconds, used in the signature
 *   - X-Settlepay-Signature   hex HMAC-SHA256("<ts>.<body>", secret)
 *
 * Tone-mapping of HTTP responses:
 *   - 2xx          → success, delivery marked delivered
 *   - 410 Gone     → endpoint asked us to stop, deactivate the webhook
 *   - other 4xx    → client error, no point retrying
 *   - 5xx / timeout → throw → Messenger retries
 */
#[AsMessageHandler]
class DeliverWebhookHandler
{
    private const TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(DeliverWebhookMessage $msg): void
    {
        $delivery = $this->deliveries->find($msg->deliveryId);
        if (!$delivery || $delivery->getDeliveredAt() !== null) {
            return; // race-safe: already delivered or row gone
        }

        $webhook = $delivery->getWebhook();
        if (!$webhook->isActive()) {
            return;
        }

        $body      = $delivery->getPayloadJson();
        $timestamp = time();
        $signature = WebhookDispatcher::sign($webhook->getSigningSecret(), $body, $timestamp);

        $delivery->incrementAttempts();

        try {
            $response = $this->http->request('POST', $webhook->getUrl(), [
                'headers' => [
                    'Content-Type'           => 'application/json',
                    'User-Agent'             => 'Settlepay-Webhooks/1.0',
                    'X-Settlepay-Event'      => $delivery->getEvent(),
                    'X-Settlepay-Timestamp'  => (string) $timestamp,
                    'X-Settlepay-Signature'  => $signature,
                    'X-Settlepay-Delivery'   => (string) $delivery->getId(),
                ],
                'body'    => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            // Reading content forces the request to complete.
            $respBody = mb_substr((string) $response->getContent(false), 0, 1024);
            $delivery
                ->setLastStatusCode($status)
                ->setLastResponseBody($respBody);

            if ($status >= 200 && $status < 300) {
                $delivery->markDelivered();
                $webhook
                    ->setLastSuccessAt(new \DateTimeImmutable())
                    ->setLastFailureReason(null);
                $this->em->flush();
                return;
            }

            if ($status === 410) {
                $webhook
                    ->setIsActive(false)
                    ->setLastFailureAt(new \DateTimeImmutable())
                    ->setLastFailureReason('Endpoint returned 410 Gone — disabled');
                $delivery->setLastError('410 Gone');
                $this->em->flush();
                return;
            }

            $webhook
                ->setLastFailureAt(new \DateTimeImmutable())
                ->setLastFailureReason('HTTP ' . $status);

            if ($status >= 500 || $status === 408 || $status === 429) {
                $this->em->flush();
                throw new \RuntimeException("Webhook delivery failed with HTTP $status — will retry");
            }

            // Other 4xx: client error, don't retry.
            $delivery->setLastError('HTTP ' . $status);
            $this->em->flush();
        } catch (TransportException $e) {
            $delivery->setLastError(mb_substr($e->getMessage(), 0, 255));
            $webhook
                ->setLastFailureAt(new \DateTimeImmutable())
                ->setLastFailureReason(mb_substr($e->getMessage(), 0, 255));
            $this->em->flush();
            throw $e; // retry
        } catch (\Throwable $e) {
            $this->logger->error('Webhook delivery error', [
                'delivery_id' => $delivery->getId(),
                'exception'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

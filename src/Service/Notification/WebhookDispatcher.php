<?php

namespace App\Service\Notification;

use App\Entity\WebhookDelivery;
use App\Entity\Workspace;
use App\Message\DeliverWebhookMessage;
use App\Repository\WebhookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Public entry point for "an event happened, fan it out to subscribed
 * webhooks." Persists a {@see WebhookDelivery} row per subscriber and
 * queues a {@see DeliverWebhookMessage} for async delivery (with retries
 * via Messenger).
 */
class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookRepository $webhooks,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(Workspace $workspace, string $event, array $payload): void
    {
        $subscribers = $this->webhooks->findSubscribersFor($workspace, $event);
        if (!$subscribers) {
            return;
        }

        $envelope = [
            'event'      => $event,
            'created_at' => (new \DateTimeImmutable())->format(DATE_RFC3339),
            'data'       => $payload,
        ];
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $deliveries = [];
        foreach ($subscribers as $webhook) {
            $d = (new WebhookDelivery())
                ->setWebhook($webhook)
                ->setEvent($event)
                ->setPayloadJson($json);
            $this->em->persist($d);
            $deliveries[] = $d;
        }
        $this->em->flush(); // IDs assigned here

        foreach ($deliveries as $d) {
            $this->bus->dispatch(new DeliverWebhookMessage((int) $d->getId()));
        }
    }

    /**
     * HMAC-SHA256 over "<timestamp>.<body>" — the same scheme Stripe uses,
     * so receivers can copy/paste their existing verification code.
     */
    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}

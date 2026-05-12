<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

#[AsEventListener(event: MessageEvent::class)]
final class EmailHeadersListener
{
    public function __construct(
        private readonly string $mailerFromAddress,
    ) {}

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }
        $headers = $message->getHeaders();

        if (!$headers->has('Reply-To') && empty($message->getReplyTo())) {
            $message->replyTo($this->mailerFromAddress);
        }
        if (!$headers->has('List-Unsubscribe')) {
            $headers->addTextHeader('List-Unsubscribe', '<mailto:unsubscribe@settlepay.pro>');
        }
        if (!$headers->has('X-Auto-Response-Suppress')) {
            $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
        }
    }
}

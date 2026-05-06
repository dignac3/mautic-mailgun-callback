<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailgunCallbackBundle\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use MauticPlugin\MauticMailgunCallbackBundle\MauticMailgunCallbackBundle;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles Mailgun webhook callbacks sent to Mautic's /mailer/callback endpoint.
 *
 * Supported Mailgun events:
 *   - failed (severity=permanent) → DNC BOUNCED
 *   - complained                  → DNC UNSUBSCRIBED (spam complaint)
 *   - unsubscribed                → DNC UNSUBSCRIBED
 *
 * All other events (delivered, opened, clicked, failed/temporary, etc.) are
 * silently ignored so other transport subscribers can process them.
 *
 * Security: every request is validated against a HMAC-SHA256 signature using
 * the webhook signing key configured in MAILGUN_WEBHOOK_SIGNING_KEY. Requests
 * with an invalid or missing signature are rejected with HTTP 406.
 *
 * DSN for sending (no plugin code required, native Mautic/Symfony support):
 *   mailgun+api://KEY:DOMAIN@default?region=eu   ← recommended & need symfony/mailgun-mailer
 *   mailgun+https://KEY:DOMAIN@default?region=eu
 *   mailgun+smtp://USERNAME:PASSWORD@default?region=eu
 *
 * @see https://documentation.mailgun.com/docs/mailgun/user-manual/tracking-messages/#webhooks
 */
class MailgunWebhookSubscriber implements EventSubscriberInterface
{
    private const TYPE_FAILED_BOUNCE    = 'failed';
    private const TYPE_COMPLAINT        = 'complained';
    private const TYPE_UNSUBSCRIBED     = 'unsubscribed';


    public function __construct(
        private readonly TransportCallback $transportCallback,
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => ['onWebhook', 0],
        ];
    }

    public function onWebhook(TransportWebhookEvent $event): void
    {
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));

        if (!in_array($dsn->getScheme(), MauticMailgunCallbackBundle::SUPPORTED_MAILER_SCHEMES, true)) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST') || !str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
            return;
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || !isset($payload['signature'], $payload['event-data'])) {
            return;
        }

        $signingKey = (string) (getenv('MAILGUN_WEBHOOK_SIGNING_KEY') ?: $this->coreParametersHelper->get('mailgun_webhook_signing_key'));

        if ('' === $signingKey) {
            $this->logger->warning('MauticMailgunCallbackBundle: mailgun_webhook_signing_key is not configured, rejecting webhook');
            $event->setResponse(new Response('Mailgun webhook signing key not configured', Response::HTTP_NOT_ACCEPTABLE));

            return;
        }

        if (!$this->validateSignature($payload['signature'], $signingKey)) {
            $this->logger->warning('MauticMailgunCallbackBundle: Invalid webhook signature', [
                'ip' => $request->getClientIp(),
            ]);
            $event->setResponse(new Response('Invalid signature', Response::HTTP_NOT_ACCEPTABLE));

            return;
        }

        $this->processEvent($payload['event-data'], $event);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the Mailgun webhook signature (v2).
     *
     * @see https://documentation.mailgun.com/docs/mailgun/user-manual/tracking-messages/#securing-webhooks
     */
    private function validateSignature(mixed $signature, string $signingKey): bool
    {
        if (
            !is_array($signature)
            || !isset($signature['timestamp'], $signature['token'], $signature['signature'])
        ) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $signature['timestamp'].$signature['token'],
            $signingKey
        );

        return hash_equals($expected, (string) $signature['signature']);
    }

    private function processEvent(mixed $payload, TransportWebhookEvent $webhookEvent): void
    {
        if (!is_array($payload) || !isset($payload['event'])) {
            $this->logger->debug('MauticMailgunCallbackBundle: Missing event type in event-data, ignoring');

            return;
        }

        $mailgunEvent = $payload['event'];
        $email    = $payload['recipient'] ?? null;

        if (null === $email || '' === $email) {
            $this->logger->warning('MauticMailgunCallbackBundle: Missing recipient (email) in event-data', ['event' => $mailgunEvent]);
            $webhookEvent->setResponse(new Response('Missing recipient (email)', Response::HTTP_NOT_ACCEPTABLE));

            return;
        }

        switch ($mailgunEvent) {
            case self::TYPE_FAILED_BOUNCE:
                $this->handleFailed($payload, $email, $webhookEvent);
                break;

            case self::TYPE_COMPLAINT :
                $this->handleComplained($email, $webhookEvent);
                break;

            case self::TYPE_UNSUBSCRIBED :
                $this->handleUnsubscribed($email, $webhookEvent);
                break;

            default:
                $this->logger->debug('MauticMailgunCallbackBundle: Ignoring event', ['event' => $mailgunEvent]);

                return;
        }
    }

    private function handleFailed(array $payload, string $email, TransportWebhookEvent $webhookEvent): void
    {
        $severity = $payload['severity'] ?? '';

        // Only hard-bounce permanent failures → DNC.
        // Temporary failures (soft bounces) are retried by Mailgun automatically.
        if ('permanent' !== $severity) {
            $this->logger->debug('MauticMailgunCallbackBundle: Ignoring temporary failure', [
                'email' => $email,
                'severity'  => $severity,
            ]);
            $webhookEvent->setResponse(new Response('OK'));

            return;
        }

        $reason  = $this->extractFailureReason($payload);
        $hashId  = $this->extractHashId($payload);

        $this->logger->info('MauticMailgunCallbackBundle: Permanent bounce, adding DNC', [
            'email' => $email,
            'reason'    => $reason,
        ]);

        if (null !== $hashId) {
            $this->transportCallback->addFailureByHashId($hashId, $reason, DNC::BOUNCED);
        } else {
            $this->transportCallback->addFailureByAddress($email, $reason, DNC::BOUNCED);
        }

        $webhookEvent->setResponse(new Response('OK'));
    }

    private function handleComplained(string $email, TransportWebhookEvent $webhookEvent): void
    {
        $this->logger->info('MauticMailgunCallbackBundle: Spam complaint, adding DNC', ['email' => $email]);

        $this->transportCallback->addFailureByAddress(
            $email,
            'Spam complaint via Mailgun',
            DNC::UNSUBSCRIBED
        );

        $webhookEvent->setResponse(new Response('OK'));
    }

    private function handleUnsubscribed(string $email, TransportWebhookEvent $webhookEvent): void
    {
        $this->logger->info('MauticMailgunCallbackBundle: Unsubscribe via Mailgun, adding DNC', ['email' => $email]);

        $this->transportCallback->addFailureByAddress(
            $email,
            'Unsubscribed via Mailgun',
            DNC::UNSUBSCRIBED
        );

        $webhookEvent->setResponse(new Response('OK'));
    }

    /**
     * Extract a human-readable bounce reason from the delivery-status payload.
     */
    private function extractFailureReason(array $payload): string
    {
        $status = $payload['delivery-status'] ?? [];

        if (is_array($status)) {
            if (!empty($status['description'])) {
                return (string) $status['description'];
            }
            if (!empty($status['message'])) {
                return (string) $status['message'];
            }
        }

        if (!empty($payload['reason'])) {
            return (string) $payload['reason'];
        }

        return 'Permanent bounce via Mailgun';
    }

    /**
     * Extract the Mautic hash ID from Mailgun user-variables if present.
     *
     * When Mautic sends emails, it injects a hash ID (used to look up the
     * email stat) and can be retrieved
     *
     * @see \Mautic\EmailBundle\Helper\MailHashHelper
     */
    private function extractHashId(array $payload): ?string
    {
        $vars = $payload['user-variables'] ?? [];

        if (!is_array($vars)) {
            return null;
        }

        return isset($vars['hash_id']) ? (string) $vars['hash_id']
            : (isset($vars['hashId']) ? (string) $vars['hashId'] : null);
    }
}


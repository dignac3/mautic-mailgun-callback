<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailgunCallbackBundle\Tests\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use MauticPlugin\MauticMailgunCallbackBundle\EventListener\MailgunWebhookSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MailgunWebhookSubscriberTest extends TestCase
{
    private const SIGNING_KEY  = 'test-signing-key';
    private const MAILGUN_DSN  = 'mailgun+api://KEY:DOMAIN@default';

    /** @var MockObject&TransportCallback */
    private TransportCallback $callback;

    /** @var MockObject&CoreParametersHelper */
    private CoreParametersHelper $parameters;

    private MailgunWebhookSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->callback   = $this->createMock(TransportCallback::class);
        $this->parameters = $this->createMock(CoreParametersHelper::class);

        $this->subscriber = new MailgunWebhookSubscriber(
            $this->callback,
            $this->parameters,
            new NullLogger(),
        );
    }

    /**
     * Configure the CoreParametersHelper mock to return a valid Mailgun DSN
     * and optionally a signing key.
     */
    private function configureParameters(string $signingKey = self::SIGNING_KEY): void
    {
        $this->parameters->method('get')->willReturnMap([
            ['mailer_dsn', null, self::MAILGUN_DSN],
            ['mailgun_webhook_signing_key', null, $signingKey],
        ]);
    }

    // -------------------------------------------------------------------------
    // Routing
    // -------------------------------------------------------------------------

    public function testSubscribesToOnTransportWebhook(): void
    {
        $events = MailgunWebhookSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(EmailEvents::ON_TRANSPORT_WEBHOOK, $events);
    }

    // -------------------------------------------------------------------------
    // Signature validation
    // -------------------------------------------------------------------------

    public function testInvalidSignatureReturns406(): void
    {
        $this->configureParameters();
        $this->callback->expects($this->never())->method($this->anything());

        $payload = $this->buildPayload('failed', 'bounce@example.com', ['signature' => 'bad-sig']);
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);

        $this->assertNotNull($event->getResponse());
        $this->assertSame(Response::HTTP_NOT_ACCEPTABLE, $event->getResponse()?->getStatusCode());
    }

    public function testMissingSigningKeyReturns406(): void
    {
        $this->configureParameters('');
        $this->callback->expects($this->never())->method($this->anything());

        $event = $this->buildEvent($this->buildPayload('failed', 'bounce@example.com'));
        $this->subscriber->onWebhook($event);

        $this->assertSame(Response::HTTP_NOT_ACCEPTABLE, $event->getResponse()?->getStatusCode());
    }

    public function testNonJsonRequestIsIgnored(): void
    {
        $this->configureParameters();
        $this->callback->expects($this->never())->method($this->anything());

        $request = Request::create('/mailer/callback', 'POST', [], [], [], [], 'not-json');
        $request->headers->set('Content-Type', 'text/plain');
        $event = new TransportWebhookEvent($request);

        $this->subscriber->onWebhook($event);

        $this->assertNull($event->getResponse());
    }

    public function testPermanentBounceAddsDncBounced(): void
    {
        $this->configureParameters();

        $this->callback->expects($this->once())
            ->method('addFailureByAddress')
            ->with('bounce@example.com', $this->isType('string'), DNC::BOUNCED);

        $payload = $this->buildPayload('failed', 'bounce@example.com', [], 'permanent');
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);

        $this->assertSame(Response::HTTP_OK, $event->getResponse()?->getStatusCode());
    }

    public function testPermanentBounceUsesHashIdWhenAvailable(): void
    {
        $this->configureParameters();

        $this->callback->expects($this->once())
            ->method('addFailureByHashId')
            ->with('abc123', $this->isType('string'), DNC::BOUNCED);

        $this->callback->expects($this->never())->method('addFailureByAddress');

        $payload = $this->buildPayload('failed', 'bounce@example.com', [], 'permanent', ['hash_id' => 'abc123']);
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);
    }

    public function testTemporaryFailureIsIgnoredNoDnc(): void
    {
        $this->configureParameters();
        $this->callback->expects($this->never())->method($this->anything());

        $payload = $this->buildPayload('failed', 'bounce@example.com', [], 'temporary');
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);

        $this->assertSame(Response::HTTP_OK, $event->getResponse()?->getStatusCode());
    }

    public function testSpamComplaintAddsDncUnsubscribed(): void
    {
        $this->configureParameters();

        $this->callback->expects($this->once())
            ->method('addFailureByAddress')
            ->with('spam@example.com', $this->stringContains('Spam'), DNC::UNSUBSCRIBED);

        $payload = $this->buildPayload('complained', 'spam@example.com');
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);

        $this->assertSame(Response::HTTP_OK, $event->getResponse()?->getStatusCode());
    }

    public function testUnsubscribeAddsDncUnsubscribed(): void
    {
        $this->configureParameters();

        $this->callback->expects($this->once())
            ->method('addFailureByAddress')
            ->with('unsub@example.com', $this->stringContains('Unsubscribed'), DNC::UNSUBSCRIBED);

        $payload = $this->buildPayload('unsubscribed', 'unsub@example.com');
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);

        $this->assertSame(Response::HTTP_OK, $event->getResponse()?->getStatusCode());
    }

    public function testUnknownEventIsIgnoredNoResponse(): void
    {
        $this->configureParameters();
        $this->callback->expects($this->never())->method($this->anything());

        $payload = $this->buildPayload('delivered', 'user@example.com');
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);

        $this->assertNull($event->getResponse());
    }

    public function testClickEventIsIgnored(): void
    {
        $this->configureParameters();
        $this->callback->expects($this->never())->method($this->anything());

        $payload = $this->buildPayload('clicked', 'user@example.com');
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);

        $this->assertNull($event->getResponse());
    }

    public function testPayloadWithoutSignatureFieldIsIgnored(): void
    {
        $this->configureParameters();
        $this->callback->expects($this->never())->method($this->anything());

        $payload = json_encode(['event-data' => ['event' => 'failed']]);
        $request = Request::create('/mailer/callback', 'POST', [], [], [], [], $payload);
        $request->headers->set('Content-Type', 'application/json');
        $event = new TransportWebhookEvent($request);

        $this->subscriber->onWebhook($event);

        $this->assertNull($event->getResponse());
    }

    public function testMissingRecipientReturns406(): void
    {
        $this->configureParameters();
        $this->callback->expects($this->never())->method($this->anything());

        $payload = $this->buildPayload('failed', null, [], 'permanent');
        $event   = $this->buildEvent($payload);

        $this->subscriber->onWebhook($event);

        $this->assertSame(Response::HTTP_NOT_ACCEPTABLE, $event->getResponse()?->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a valid (correctly signed) Mailgun webhook payload.
     *
     * @param array<string, string> $signatureOverride Override signature fields (to test invalid sigs)
     * @param array<string, string> $userVariables     Mautic user-variables (e.g. hash_id)
     */
    private function buildPayload(
        string $mailgunEvent,
        ?string $recipient,
        array $signatureOverride = [],
        string $severity = 'permanent',
        array $userVariables = [],
    ): string {
        $timestamp = (string) time();
        $token     = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp.$token, self::SIGNING_KEY);

        $sig = array_merge(
            ['timestamp' => $timestamp, 'token' => $token, 'signature' => $signature],
            $signatureOverride,
        );

        $eventData = ['event' => $mailgunEvent];

        if (null !== $recipient) {
            $eventData['recipient'] = $recipient;
        }

        if ('failed' === $mailgunEvent) {
            $eventData['severity']        = $severity;
            $eventData['delivery-status'] = ['description' => 'Test bounce reason'];
        }

        if ([] !== $userVariables) {
            $eventData['user-variables'] = $userVariables;
        }

        return json_encode(['signature' => $sig, 'event-data' => $eventData], JSON_THROW_ON_ERROR);
    }

    private function buildEvent(string $jsonPayload): TransportWebhookEvent
    {
        $request = Request::create('/mailer/callback', 'POST', [], [], [], [], $jsonPayload);
        $request->headers->set('Content-Type', 'application/json');

        return new TransportWebhookEvent($request);
    }
}


<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Exception\InvalidWebhookException;
use App\Billing\Stripe\StripeWebhookDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An empty or example signing secret would let anybody forge Stripe events: every event is refused,
 * even one correctly signed with that secret.
 */
final class StripeWebhookSecretTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSecrets(): iterable
    {
        yield 'empty' => [''];
        yield 'former .env default' => ['whsec_changeme'];
        yield 'documentation placeholder' => ['whsec_CHANGE_ME_0123456789'];
        yield 'example' => ['whsec_example_0123456789'];
        yield 'not a signing secret' => ['sk_live_0123456789abcdef'];
        yield 'too short' => ['whsec_abc'];
    }

    #[DataProvider('unusableSecrets')]
    public function testAnUnusableSecretRejectsEveryEvent(string $secret): void
    {
        $decoder = new StripeWebhookDecoder($secret, 'price_m', 'price_y');
        $payload = '{"id":"evt_1","type":"invoice.paid","created":1800000000,"data":{"object":{}}}';
        $timestamp = time();

        $this->expectException(InvalidWebhookException::class);
        $this->expectExceptionMessageIsOrContains('STRIPE_WEBHOOK_SECRET');

        $decoder->decode($payload, \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, $secret)));
    }

    public function testARealLookingSecretIsAccepted(): void
    {
        self::assertTrue(StripeWebhookDecoder::isUsableSecret('whsec_'.str_repeat('a1B2', 8)));
    }
}

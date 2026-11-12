<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Exception\InvalidWebhookException;
use App\Billing\Service\StripeWebhookHandler;
use App\Billing\Stripe\BillingGateway;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public endpoint called by Stripe. No session, no CSRF: the `Stripe-Signature` header,
 * checked with STRIPE_WEBHOOK_SECRET, is the only authentication.
 */
final class StripeWebhookController
{
    #[Route('/webhooks/stripe', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request, BillingGateway $gateway, StripeWebhookHandler $handler, LoggerInterface $logger): JsonResponse
    {
        try {
            $event = $gateway->decodeWebhook($request->getContent(), $request->headers->get('Stripe-Signature') ?? '');
        } catch (InvalidWebhookException $exception) {
            $logger->notice('Stripe webhook rejected: {reason}', ['reason' => $exception->getMessage()]);

            return new JsonResponse(['error' => 'invalid_webhook'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['received' => true, 'outcome' => $handler->handle($event)->value]);
    }
}

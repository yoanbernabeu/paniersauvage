<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Exception\BillingGatewayException;
use App\Billing\Form\SolidarityDeclaration;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Billing\Stripe\BillingGateway;
use App\Billing\Stripe\CheckoutRequest;
use App\Shop\Entity\Shop;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Applies a declaration on honour for the solidarity plan:
 *
 * - 0 €: no Stripe at all. A running Stripe subscription is cancelled, the plan is active for
 *   12 months from today (renewed by a new declaration);
 * - more than 0 € with a running Stripe subscription: its price is changed in Stripe (from the next
 *   invoice, no proration); the webhook confirms the new amount;
 * - more than 0 € otherwise: a Stripe Checkout session at that monthly amount.
 */
final readonly class SolidarityPlanManager
{
    public function __construct(
        private ShopSubscriptionRepository $subscriptions,
        private BillingGateway $gateway,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array{email: string, success_url: string, cancel_url: string} $checkout
     *
     * @return string|null the Checkout URL to redirect to, or null when the change is already done
     *
     * @throws BillingGatewayException
     */
    public function apply(Shop $shop, SolidarityDeclaration $declaration, array $checkout): ?string
    {
        $shopId = $shop->getId() ?? throw new \LogicException('The shop must be saved first.');
        $now = $this->clock->now();
        $amountCents = $declaration->amountCents();
        if (!SolidarityPlan::isValidAmount($amountCents)) {
            throw new \InvalidArgumentException(\sprintf('Invalid solidarity amount: %d cents.', $amountCents));
        }

        $subscription = $this->subscriptions->findOneByShop($shop) ?? new ShopSubscription($shop);
        $runningStripeSubscription = $this->runningStripeSubscription($subscription);

        $url = null;
        if (0 === $amountCents) {
            if (null !== $runningStripeSubscription) {
                $this->gateway->cancelSubscription($runningStripeSubscription);
            }
            $subscription->activateFreeSolidarity($now);
        } elseif (null !== $runningStripeSubscription) {
            $this->gateway->changeSolidarityAmount($runningStripeSubscription, $shopId, $amountCents);
            $subscription->setPlan(SubscriptionPlan::Solidarity)->setAmountCents($amountCents);
        } else {
            $url = $this->gateway->createCheckoutSession(new CheckoutRequest(
                $shopId,
                SubscriptionPlan::Solidarity,
                $checkout['email'],
                $subscription->getStripeCustomerId(),
                $checkout['success_url'],
                $checkout['cancel_url'],
                $amountCents,
            ));
        }

        $subscription->declareSolidarity($declaration->criteria, $now);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $url;
    }

    /**
     * The id of a Stripe subscription that is still billing the shop, if any.
     */
    private function runningStripeSubscription(ShopSubscription $subscription): ?string
    {
        $id = $subscription->getStripeSubscriptionId();

        return null !== $id && \in_array($subscription->getStatus(), [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) ? $id : null;
    }
}

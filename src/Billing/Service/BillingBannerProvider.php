<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Instance\InstanceSettings;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Entity\Shop;
use App\Shop\Service\CurrentShop;
use Psr\Clock\ClockInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Decides whether the current producer must be warned about their subscription:
 * new sales are blocked (`allowsNewSales()` is false), the last payment failed (grace period
 * running), the free trial ends within 7 days, or a free solidarity period ends within 30 days.
 * Never on a sponsored instance (nothing to pay).
 */
final readonly class BillingBannerProvider
{
    /** The banner appears when the trial ends in less than this many days. */
    public const int TRIAL_WARNING_DAYS = 7;

    public const string INACTIVE_CONSEQUENCE = 'Tes ventes en cours vont à leur terme, mais aucune nouvelle vente ne peut s\'ouvrir ni se créer.';

    public function __construct(
        private CurrentShop $currentShop,
        private ShopSubscriptionRepository $subscriptions,
        private ClockInterface $clock,
        private FrenchFormatter $formatter,
        private InstanceSettings $instance,
    ) {
    }

    /** {% set banner = billing_banner() %} → BillingBanner, or null when there is nothing to say. */
    #[AsTwigFunction('billing_banner')]
    public function current(): ?BillingBanner
    {
        if ($this->instance->isSponsored()) {
            return null;
        }

        $shop = $this->currentShop->find();
        $subscription = $shop instanceof Shop ? $this->subscriptions->findOneByShop($shop) : null;

        return $subscription instanceof ShopSubscription ? $this->for($subscription, $this->clock->now()) : null;
    }

    public function for(ShopSubscription $subscription, \DateTimeImmutable $now): ?BillingBanner
    {
        if (!$subscription->allowsNewSales($now)) {
            return new BillingBanner(
                'destructive',
                match ($subscription->getStatus()) {
                    SubscriptionStatus::Trialing => 'Ton essai gratuit est terminé.',
                    SubscriptionStatus::PastDue => 'Ton abonnement est suspendu : le dernier prélèvement n\'a pas abouti.',
                    // Only a free solidarity period can be active and block new sales: it has ended.
                    SubscriptionStatus::Active => 'Ton palier solidaire gratuit est arrivé à échéance.',
                    SubscriptionStatus::Canceled => 'Ton abonnement est résilié.',
                },
                self::INACTIVE_CONSEQUENCE,
                match ($subscription->getStatus()) {
                    SubscriptionStatus::PastDue => 'Régulariser',
                    SubscriptionStatus::Active => 'Renouveler ma déclaration',
                    default => 'M\'abonner',
                },
            );
        }

        $solidarityEnd = $subscription->getCurrentPeriodEnd();
        if ($subscription->isSolidarityRenewalDue($now) && $solidarityEnd instanceof \DateTimeImmutable) {
            return new BillingBanner(
                'warning',
                \sprintf('Ton palier solidaire gratuit se termine le %s.', $this->formatter->dateLong($solidarityEnd, true)),
                'Renouvelle ta déclaration sur l\'honneur pour continuer à ouvrir et créer des ventes ensuite.',
                'Renouveler',
            );
        }

        $periodEnd = $subscription->getCurrentPeriodEnd();
        if (SubscriptionStatus::PastDue === $subscription->getStatus() && $periodEnd instanceof \DateTimeImmutable) {
            $deadline = $periodEnd->modify(\sprintf('+%d days', ShopSubscription::PAST_DUE_GRACE_DAYS));

            return new BillingBanner(
                'warning',
                'Ton dernier paiement n\'est pas passé.',
                \sprintf('Mets à jour ton moyen de paiement avant le %s pour que tes ventes continuent de s\'ouvrir.', $this->formatter->dateLong($deadline)),
                'Mettre à jour',
            );
        }

        $trialEndsAt = $subscription->getTrialEndsAt();
        if ($subscription->isTrialing() && $trialEndsAt instanceof \DateTimeImmutable && $trialEndsAt < $now->modify(\sprintf('+%d days', self::TRIAL_WARNING_DAYS))) {
            $days = $subscription->trialDaysLeft($now);

            return new BillingBanner(
                'warning',
                1 === $days ? 'Ton essai gratuit se termine dans moins de 24 heures.' : \sprintf('Ton essai gratuit se termine dans %d jours.', $days),
                'Abonne-toi pour continuer à ouvrir et créer des ventes ensuite.',
                'Voir les formules',
            );
        }

        return null;
    }
}

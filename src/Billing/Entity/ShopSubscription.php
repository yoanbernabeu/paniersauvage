<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\SolidarityCriterion;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Billing\Service\SolidarityPlan;
use App\Shop\Entity\Shop;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The SaaS subscription of a shop (Stripe Billing). This is what the producer pays to
 * Panier Sauvage; it has nothing to do with customer orders.
 */
#[ORM\Entity(repositoryClass: ShopSubscriptionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_shop_subscription_shop', columns: ['shop_id'])]
#[ORM\Index(name: 'idx_shop_subscription_stripe_customer', columns: ['stripe_customer_id'])]
#[ORM\Index(name: 'idx_shop_subscription_stripe_subscription', columns: ['stripe_subscription_id'])]
final class ShopSubscription
{
    public const int TRIAL_DAYS = 30;

    /** Days a `past_due` subscription keeps working after the end of the paid period. */
    public const int PAST_DUE_GRACE_DAYS = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: SubscriptionPlan::class)]
    private SubscriptionPlan $plan = SubscriptionPlan::Monthly;

    #[ORM\Column(length: 20, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::Trialing;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    /** Solidarity plan only: the monthly amount chosen, in cents (whole euros, 0 to 1000). */
    #[ORM\Column(nullable: true)]
    private ?int $amountCents = null;

    /** @var list<string> values of SolidarityCriterion ticked in the last declaration on honour */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $solidarityCriteria = [];

    /** Date of the last declaration on honour for the solidarity plan. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $solidarityDeclaredAt = null;

    /** The renewal reminder of the current free period has left (reset by each new declaration). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $solidarityReminderSentAt = null;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
    ) {
    }

    /**
     * The free trial every shop gets at sign-up, without a card.
     */
    public static function startTrial(Shop $shop, \DateTimeImmutable $now, int $days = self::TRIAL_DAYS): self
    {
        $subscription = new self($shop);
        $subscription->status = SubscriptionStatus::Trialing;
        $subscription->trialEndsAt = $now->modify(\sprintf('+%d days', $days));

        return $subscription;
    }

    /**
     * Reference rule of SPEC §7: may this shop open or create new sales?
     */
    public function allowsNewSales(\DateTimeImmutable $now): bool
    {
        return match ($this->status) {
            // A free solidarity period has no Stripe subscription to renew it: it ends with its period.
            SubscriptionStatus::Active => !$this->isFreeSolidarity()
                || ($this->currentPeriodEnd instanceof \DateTimeImmutable && $now < $this->currentPeriodEnd),
            SubscriptionStatus::Trialing => $this->trialEndsAt instanceof \DateTimeImmutable && $now < $this->trialEndsAt,
            SubscriptionStatus::PastDue => $this->currentPeriodEnd instanceof \DateTimeImmutable
                && $now < $this->currentPeriodEnd->modify(\sprintf('+%d days', self::PAST_DUE_GRACE_DAYS)),
            SubscriptionStatus::Canceled => false,
        };
    }

    /**
     * Records a declaration on honour for the solidarity plan (criteria and date). The plan itself
     * changes when it is activated: right away at 0 €, through Stripe otherwise.
     *
     * @param list<SolidarityCriterion> $criteria
     */
    public function declareSolidarity(array $criteria, \DateTimeImmutable $now): static
    {
        $this->solidarityCriteria = [];
        foreach ($criteria as $criterion) {
            if (!\in_array($criterion->value, $this->solidarityCriteria, true)) {
                $this->solidarityCriteria[] = $criterion->value;
            }
        }
        $this->solidarityDeclaredAt = $now;
        $this->solidarityReminderSentAt = null;

        return $this;
    }

    /**
     * The free (0 €) solidarity plan: active for 12 months from now, without Stripe. The Stripe
     * customer is kept (past invoices stay reachable in the portal).
     */
    public function activateFreeSolidarity(\DateTimeImmutable $now): static
    {
        $this->plan = SubscriptionPlan::Solidarity;
        $this->amountCents = 0;
        $this->status = SubscriptionStatus::Active;
        $this->trialEndsAt = null;
        $this->stripeSubscriptionId = null;
        $this->currentPeriodEnd = $now->modify(\sprintf('+%d months', SolidarityPlan::FREE_PERIOD_MONTHS));
        $this->solidarityReminderSentAt = null;

        return $this;
    }

    /**
     * Solidarity plan at 0 €, without any Stripe subscription: it ends with its 12-month period.
     */
    public function isFreeSolidarity(): bool
    {
        return SubscriptionPlan::Solidarity === $this->plan && null === $this->stripeSubscriptionId;
    }

    public function isSolidarity(): bool
    {
        return SubscriptionPlan::Solidarity === $this->plan;
    }

    /**
     * A free solidarity period that ends within the reminder window (or has ended).
     */
    public function isSolidarityRenewalDue(\DateTimeImmutable $now): bool
    {
        return $this->isFreeSolidarity()
            && SubscriptionStatus::Active === $this->status
            && $this->currentPeriodEnd instanceof \DateTimeImmutable
            && $this->currentPeriodEnd < $now->modify(\sprintf('+%d days', SolidarityPlan::REMINDER_DAYS));
    }

    public function isTrialing(): bool
    {
        return SubscriptionStatus::Trialing === $this->status;
    }

    public function isTrialExpired(\DateTimeImmutable $now): bool
    {
        return $this->isTrialing() && (!$this->trialEndsAt instanceof \DateTimeImmutable || $now >= $this->trialEndsAt);
    }

    /**
     * Whole days of trial left (0 once expired or when not trialing).
     */
    public function trialDaysLeft(\DateTimeImmutable $now): int
    {
        if (!$this->isTrialing() || !$this->trialEndsAt instanceof \DateTimeImmutable || $now >= $this->trialEndsAt) {
            return 0;
        }

        return (int) ceil(($this->trialEndsAt->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function getPlan(): SubscriptionPlan
    {
        return $this->plan;
    }

    public function setPlan(SubscriptionPlan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function setStatus(SubscriptionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;

        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?\DateTimeImmutable $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    public function setAmountCents(?int $amountCents): static
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    /**
     * @return list<SolidarityCriterion>
     */
    public function getSolidarityCriteria(): array
    {
        $criteria = [];
        foreach ($this->solidarityCriteria as $value) {
            $criterion = SolidarityCriterion::tryFrom($value);
            if (null !== $criterion) {
                $criteria[] = $criterion;
            }
        }

        return $criteria;
    }

    public function getSolidarityDeclaredAt(): ?\DateTimeImmutable
    {
        return $this->solidarityDeclaredAt;
    }

    public function getSolidarityReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->solidarityReminderSentAt;
    }

    public function setSolidarityReminderSentAt(?\DateTimeImmutable $solidarityReminderSentAt): static
    {
        $this->solidarityReminderSentAt = $solidarityReminderSentAt;

        return $this;
    }
}

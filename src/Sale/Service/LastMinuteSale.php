<?php

declare(strict_types=1);

namespace App\Sale\Service;

use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Sale\Event\LastMinuteSaleOpened;
use App\Sale\Exception\LastMinuteNotAllowed;
use App\Sale\Repository\SaleOfferRepository;
use App\Shared\Service\FrenchFormatter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Leftovers and last-minute sale (SPEC §21, lot F).
 *
 * Once a sale is closed and before its delivery, the producer sees what is left of the limited
 * offers and may put leftovers back on sale for a short while: a controlled reopening of the same
 * sale, not a new one. The status stays `closed` (no step back in the life cycle, SaleClosed is not
 * sent again), the orders already placed stay frozen, and the new orders land on the same delivery
 * sheet, production totals and stock counters as the others. Customers order only the offers put
 * back on sale (`SaleOffer::isLastMinute()`), within the quantity the producer gave: the limit of
 * each offer becomes what is reserved plus that quantity, under the lock of its row, and every
 * order then goes through ReservationService as usual.
 *
 * It opens once per sale (its e-mail to the consenting followers goes out once), at least
 * MIN_DURATION long, and ends at the latest MARGIN before the first delivery.
 */
final readonly class LastMinuteSale
{
    /** Shortest last-minute sale. */
    public const string MIN_DURATION = '+30 minutes';
    /** Latest end: this long before the first delivery starts. */
    public const string MARGIN = '-1 hour';
    /** Suggested end: this long before the first delivery starts. */
    public const string DEFAULT_MARGIN = '-2 hours';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SaleOfferRepository $offers,
        private SubscriptionGate $subscriptionGate,
        private EventDispatcherInterface $eventDispatcher,
        private FrenchFormatter $formatter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Limited offers with something left, in the order of the sale.
     *
     * @return list<SaleOffer>
     */
    public function leftovers(Sale $sale): array
    {
        return $sale->getOffers()->filter(static fn (SaleOffer $offer): bool => $offer->remaining() > 0)->getValues();
    }

    /**
     * Why the producer cannot open a last-minute sale now, or null when they can.
     */
    public function refusal(Sale $sale): ?string
    {
        if ($sale->getLastMinuteOpenedAt() instanceof \DateTimeImmutable) {
            return 'Tu as déjà ouvert une vente de dernière minute pour cette livraison.';
        }
        if (!$sale->isClosed()) {
            return 'La vente de dernière minute s\'ouvre une fois les commandes closes, avant la livraison.';
        }
        $latest = $this->latestEnd($sale);
        if (!$latest instanceof \DateTimeImmutable || $latest < $this->clock->now()->modify(self::MIN_DURATION)) {
            return 'C\'est trop tard : la livraison commence bientôt.';
        }
        if (!$this->subscriptionGate->allowsNewSales($sale->getShop())) {
            return 'Ton abonnement ne permet plus d\'ouvrir de vente.';
        }

        return null;
    }

    /**
     * Latest end of the last-minute sale (MARGIN before the first delivery), or null without delivery.
     */
    public function latestEnd(Sale $sale): ?\DateTimeImmutable
    {
        return $sale->getFirstDeliveryStart()?->modify(self::MARGIN);
    }

    /**
     * Suggested end: DEFAULT_MARGIN before the first delivery, or the latest end when that is too soon.
     */
    public function defaultEnd(Sale $sale): ?\DateTimeImmutable
    {
        $suggested = $sale->getFirstDeliveryStart()?->modify(self::DEFAULT_MARGIN);
        if (!$suggested instanceof \DateTimeImmutable || $suggested < $this->clock->now()->modify(self::MIN_DURATION)) {
            return $this->latestEnd($sale);
        }

        return $suggested;
    }

    /**
     * Puts leftovers back on sale until $until and tells the followers (LastMinuteSaleOpened).
     *
     * @param array<int, int|null> $quantities sale offer id => quantity put back on sale, in thousandths (0 or null: not offered)
     *
     * @throws LastMinuteNotAllowed when the sale, the end or the quantities do not allow it
     */
    public function open(Sale $sale, \DateTimeImmutable $until, array $quantities): void
    {
        $quantities = array_filter($quantities, static fn (?int $quantity): bool => $quantity > 0);
        ksort($quantities);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // SELECT … FOR UPDATE: a double submission opens it once.
            $this->entityManager->refresh($sale, LockMode::PESSIMISTIC_WRITE);
            $this->assertCanOpen($sale, $until, $quantities);

            foreach ($quantities as $offerId => $quantity) {
                // Locks the row and refreshes its counters: the limit is set on the current reservations.
                $offer = $this->offers->findForUpdate($offerId);
                if (!$offer instanceof SaleOffer || $offer->getSale() !== $sale) {
                    throw new LastMinuteNotAllowed('Un des produits ne fait pas partie de cette vente.');
                }
                if (!$offer->getProduct()->getUnit()->accepts($quantity)) {
                    throw new LastMinuteNotAllowed(\sprintf('« %s » se vend à l\'unité : indique un nombre entier.', $offer->getProduct()->getName()));
                }
                $offer->putBackOnSale($quantity);
            }

            $sale->openLastMinute($until, $this->clock->now());
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            if ($this->entityManager->isOpen()) {
                $this->entityManager->refresh($sale);
                foreach ($sale->getOffers() as $offer) {
                    $this->entityManager->refresh($offer);
                }
            }

            throw $exception;
        }

        $this->eventDispatcher->dispatch(new LastMinuteSaleOpened($sale));
    }

    /**
     * Stops taking last-minute orders now (nothing happens when it is not taking any).
     */
    public function end(Sale $sale): void
    {
        $sale->endLastMinute($this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * @param array<int, int> $quantities
     */
    private function assertCanOpen(Sale $sale, \DateTimeImmutable $until, array $quantities): void
    {
        $refusal = $this->refusal($sale);
        if (null !== $refusal) {
            throw new LastMinuteNotAllowed($refusal);
        }

        $now = $this->clock->now();
        $latest = $this->latestEnd($sale) ?? $now;
        if ($until < $now->modify(self::MIN_DURATION)) {
            throw new LastMinuteNotAllowed('Laisse au moins 30 minutes à tes clients pour commander.');
        }
        if ($until > $latest) {
            throw new LastMinuteNotAllowed(\sprintf('Termine la vente au plus tard le %s, une heure avant la livraison.', $this->formatter->dateTimeLong($latest)));
        }
        if ([] === $quantities) {
            throw new LastMinuteNotAllowed('Indique au moins un produit à remettre en vente.');
        }
    }
}

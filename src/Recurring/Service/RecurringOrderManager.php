<?php

declare(strict_types=1);

namespace App\Recurring\Service;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Service\OrderManager;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Enum\RecurringEndReason;
use App\Recurring\Event\RecurringOrderEnded;
use App\Recurring\Event\RecurringOrderStarted;
use App\Recurring\Event\RecurringOrderSuspended;
use App\Recurring\Exception\RecurringOrderNotAllowedException;
use App\Recurring\Model\RecurringOrderRequest;
use App\Recurring\Model\UpcomingDelivery;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Recurring\Repository\RecurringPlanRepository;
use App\Shared\Enum\Actor;
use App\Shared\Service\FrenchFormatter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Subscribes, changes, pauses, cancels, suspends and ends recurring orders. The e-mails go through
 * domain events (SPEC §6); no payment is ever recorded (SPEC §1).
 *
 * Who may do what:
 * - the subscriber (an account with an e-mail address: they must be told of each generated order,
 *   accept the terms and certify their age themselves) subscribes, changes what they receive for
 *   the next deliveries, pauses a delivery (RecurringCalendar) and cancels an open-ended
 *   subscription, the notice of the plan still applying; a season contract binds them until its end;
 * - the producer suspends, resumes or ends a subscription of their shop.
 */
final readonly class RecurringOrderManager
{
    public function __construct(
        private RecurringPlanRepository $plans,
        private RecurringOrderRepository $recurringOrders,
        private RecurringOrderGenerator $generator,
        private RecurringCalendar $calendar,
        private RecurringPlanCatalog $catalog,
        private OrderManager $orderManager,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private FrenchFormatter $formatter,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws RecurringOrderNotAllowedException when the plan does not take this subscription
     */
    public function subscribe(RecurringPlan $plan, Customer $customer, RecurringOrderRequest $request, bool $adultCertified): RecurringOrder
    {
        $now = $this->clock->now();

        if (!$customer->hasEmail() || null === $customer->getId()) {
            throw new RecurringOrderNotAllowedException('Un abonnement se prend depuis ton propre compte, avec ton adresse e-mail.');
        }
        if ($plan->containsAlcohol() && !$adultCertified) {
            throw new RecurringOrderNotAllowedException('La vente d\'alcool est interdite aux mineurs : certifie avoir 18 ans ou plus pour t\'abonner.');
        }
        $this->assertRequest($plan, $request);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Two customers taking the last place are served one after the other.
            $this->plans->lock($plan);

            if (!$this->catalog->isOpen($plan)) {
                throw new RecurringOrderNotAllowedException('Les inscriptions à ce panier sont fermées.');
            }
            if ($this->recurringOrders->findInProgress($plan, $customer) instanceof RecurringOrder) {
                throw new RecurringOrderNotAllowedException('Tu es déjà abonné à ce panier.');
            }
            if (null !== $plan->getCapacity() && $this->recurringOrders->countInProgress($plan) >= $plan->getCapacity()) {
                throw new RecurringOrderNotAllowedException('Ce panier est complet : il n\'y a plus de place pour l\'instant.');
            }

            $startsOn = $plan->getSeasonStartsOn() instanceof \DateTimeImmutable ? max($now, $plan->getSeasonStartsOn()) : $now;
            $recurringOrder = new RecurringOrder($plan, $customer, $request->deliveryPoint, $startsOn, $now);
            $this->apply($recurringOrder, $request);
            if ($adultCertified && $plan->containsAlcohol()) {
                $recurringOrder->certifyAdult($now);
            }

            $this->entityManager->persist($recurringOrder);
            $this->entityManager->flush();
            $connection->commit();
        } catch (UniqueConstraintViolationException) {
            $connection->rollBack();

            throw new RecurringOrderNotAllowedException('Tu es déjà abonné à ce panier.');
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $this->eventDispatcher->dispatch(new RecurringOrderStarted($recurringOrder));
        // The sales already open get their order right away.
        $this->generator->generateForRecurringOrder($recurringOrder);

        return $recurringOrder;
    }

    /**
     * Changes what the subscriber receives from the next generated order on. Orders already
     * generated are edited on their own page.
     *
     * @throws RecurringOrderNotAllowedException
     */
    public function update(RecurringOrder $recurringOrder, RecurringOrderRequest $request): void
    {
        if ($recurringOrder->isEnded()) {
            throw new RecurringOrderNotAllowedException('Cet abonnement est terminé.');
        }
        $this->assertRequest($recurringOrder->getPlan(), $request);

        $this->apply($recurringOrder, $request);
        $recurringOrder->touch($this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * Skips one delivery. Its order, when already generated, is cancelled (stock given back).
     *
     * @throws RecurringOrderNotAllowedException when the rules of the plan do not allow it
     */
    public function pause(RecurringOrder $recurringOrder, \DateTimeImmutable $day): void
    {
        $now = $this->clock->now();
        $delivery = $this->calendar->find($recurringOrder, $day, $now);

        if (!$delivery instanceof UpcomingDelivery || $delivery->paused) {
            throw new RecurringOrderNotAllowedException('Cette livraison ne fait pas partie de tes prochaines livraisons.');
        }
        if (!$delivery->canPause) {
            throw new RecurringOrderNotAllowedException($recurringOrder->canPauseAgain() ? \sprintf('Il est trop tard pour mettre en pause la livraison du %s.', $this->formatter->dateLong($delivery->date)) : 'Tu as déjà utilisé toutes les absences prévues par ton abonnement.');
        }

        if ($delivery->order instanceof Order) {
            try {
                $this->orderManager->cancelOrder($delivery->order, Actor::Customer);
            } catch (OrderNotAllowedException $exception) {
                throw new RecurringOrderNotAllowedException($exception->getMessage(), $exception->getCode(), previous: $exception);
            }
            $delivery->occurrence?->setOutcome(OccurrenceOutcome::Paused);
        }

        $recurringOrder->pause($delivery->date, $now);
        $this->entityManager->flush();
    }

    /**
     * @throws RecurringOrderNotAllowedException when the delivery can no longer be resumed
     */
    public function resume(RecurringOrder $recurringOrder, \DateTimeImmutable $day): void
    {
        $now = $this->clock->now();
        $delivery = $this->calendar->find($recurringOrder, $day, $now);

        if (!$delivery instanceof UpcomingDelivery || !$delivery->canResume) {
            throw new RecurringOrderNotAllowedException('Cette livraison ne peut plus être reprise ici : commande directement sur la page de la vente.');
        }

        $recurringOrder->unpause($delivery->date, $now);
        $this->entityManager->flush();
        // Its sale may be open already.
        $this->generator->generateForRecurringOrder($recurringOrder);
    }

    /**
     * Cancellation by the subscriber: immediate without notice, otherwise the deliveries of the
     * notice period stay due.
     *
     * @return \DateTimeImmutable|null the last delivery day kept, null when it ended at once
     *
     * @throws RecurringOrderNotAllowedException for a season contract, or when already ending
     */
    public function cancel(RecurringOrder $recurringOrder): ?\DateTimeImmutable
    {
        $plan = $recurringOrder->getPlan();
        $now = $this->clock->now();

        if ($plan->isSeason()) {
            throw new RecurringOrderNotAllowedException(\sprintf('Ton contrat de saison t\'engage jusqu\'au %s. Pour l\'arrêter avant, parles-en directement à %s.', $this->formatter->dateLong($recurringOrder->getEndsOn() ?? $now), $plan->getShop()->getName()));
        }
        if ($recurringOrder->isEnded() || $recurringOrder->getEndReason() instanceof RecurringEndReason) {
            throw new RecurringOrderNotAllowedException('Cet abonnement est déjà résilié.');
        }

        if (0 === $plan->getNoticeDays()) {
            $recurringOrder->end(RecurringEndReason::Customer, $now);
            $this->entityManager->flush();
            $this->eventDispatcher->dispatch(new RecurringOrderEnded($recurringOrder));

            return null;
        }

        $lastDay = $now->modify(\sprintf('+%d days', $plan->getNoticeDays()))->setTime(0, 0);
        $recurringOrder->cancelOn($lastDay, $now);
        $this->entityManager->flush();

        return $lastDay;
    }

    /**
     * @throws RecurringOrderNotAllowedException when it is not active
     */
    public function suspend(RecurringOrder $recurringOrder): void
    {
        if (!$recurringOrder->isActive()) {
            throw new RecurringOrderNotAllowedException('Seul un abonnement actif peut être suspendu.');
        }

        $recurringOrder->suspend($this->clock->now());
        $this->entityManager->flush();
        $this->eventDispatcher->dispatch(new RecurringOrderSuspended($recurringOrder));
    }

    /**
     * @throws RecurringOrderNotAllowedException when it is not suspended
     */
    public function resumeSubscription(RecurringOrder $recurringOrder): void
    {
        if (!$recurringOrder->isSuspended()) {
            throw new RecurringOrderNotAllowedException('Cet abonnement n\'est pas suspendu.');
        }

        $recurringOrder->resume($this->clock->now());
        $this->entityManager->flush();
        $this->generator->generateForRecurringOrder($recurringOrder);
    }

    /**
     * Ended by the producer, at once.
     *
     * @throws RecurringOrderNotAllowedException when already ended
     */
    public function endByProducer(RecurringOrder $recurringOrder): void
    {
        if ($recurringOrder->isEnded()) {
            throw new RecurringOrderNotAllowedException('Cet abonnement est déjà terminé.');
        }

        $recurringOrder->end(RecurringEndReason::Producer, $this->clock->now());
        $this->entityManager->flush();
        $this->eventDispatcher->dispatch(new RecurringOrderEnded($recurringOrder));
    }

    /**
     * Ends the subscriptions whose last delivery day has passed: end of a season contract (the
     * subscriber is told) or end of the notice of a cancellation.
     *
     * @return int number of subscriptions ended
     */
    public function endDue(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->recurringOrders->findDueToEnd($now) as $recurringOrder) {
            try {
                $recurringOrder->end(RecurringEndReason::ContractEnd, $now);
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                $this->logger->error('Recurring order #{id} could not be ended: {message}', [
                    'id' => $recurringOrder->getId(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);

                return $count;
            }

            $this->eventDispatcher->dispatch(new RecurringOrderEnded($recurringOrder));
            ++$count;
        }

        return $count;
    }

    private function apply(RecurringOrder $recurringOrder, RecurringOrderRequest $request): void
    {
        $plan = $recurringOrder->getPlan();
        $recurringOrder->setDeliveryPoint($request->deliveryPoint);
        $recurringOrder->setDeliveryAddress($request->deliveryPoint->isHome() ? $request->deliveryAddress : null);

        if ($plan->isBasket()) {
            $recurringOrder->setShares($request->shares);

            return;
        }

        foreach ($plan->getItems() as $item) {
            $recurringOrder->setQuantityOf($item->getProduct(), $request->quantities[(int) $item->getProduct()->getId()] ?? 0);
        }
    }

    /**
     * @throws RecurringOrderNotAllowedException
     */
    private function assertRequest(RecurringPlan $plan, RecurringOrderRequest $request): void
    {
        $point = $request->deliveryPoint;
        if ($point->isArchived() || !$plan->getSchedule()->getDeliveryPoints()->contains($point)) {
            throw new RecurringOrderNotAllowedException('Ce point de retrait n\'est pas proposé pour ce panier.');
        }
        if ($point->isHome() && '' === trim($request->deliveryAddress ?? '')) {
            throw new RecurringOrderNotAllowedException('Indique ton adresse pour la livraison à domicile.');
        }

        if ($plan->isBasket()) {
            if ($request->shares < 1 || $request->shares > $plan->getMaxShares()) {
                throw new RecurringOrderNotAllowedException(\sprintf('Choisis entre 1 et %d panier%s.', $plan->getMaxShares(), $plan->getMaxShares() > 1 ? 's' : ''));
            }

            return;
        }

        $chosen = false;
        foreach ($plan->getItems() as $item) {
            $product = $item->getProduct();
            $quantity = $request->quantities[(int) $product->getId()] ?? 0;
            if (0 === $quantity) {
                continue;
            }
            if ($quantity > $item->getQuantity() || !$product->acceptsOrderQuantity($quantity)) {
                throw new RecurringOrderNotAllowedException(\sprintf('Quantité de « %s » non proposée.', $product->getName()));
            }
            $chosen = true;
        }

        if (!$chosen) {
            throw new RecurringOrderNotAllowedException('Choisis au moins un produit.');
        }
    }
}

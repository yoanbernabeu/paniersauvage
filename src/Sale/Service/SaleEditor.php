<?php

declare(strict_types=1);

namespace App\Sale\Service;

use App\Ordering\Service\SaleOrders;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Exception\SaleEditConflict;
use App\Sale\Form\Model\SaleData;
use App\Sale\Repository\SaleOfferRepository;
use App\Sale\Repository\SaleRepository;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Entity\Shop;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Applies the (validated) sale form to the entities.
 */
final readonly class SaleEditor
{
    public function __construct(
        private SaleRepository $sales,
        private SaleOfferRepository $offers,
        private SaleCodeGenerator $codes,
        private SaleOrders $saleOrders,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private FrenchFormatter $formatter,
    ) {
    }

    /**
     * Creates a one-off sale, in the `scheduled` status.
     */
    public function create(Shop $shop, SaleData $data): Sale
    {
        $sale = new Sale(
            $shop,
            $this->codes->generate(),
            $data->deliveryDate ?? throw new \LogicException('The sale form was not validated.'),
            $data->opensAt ?? throw new \LogicException('The sale form was not validated.'),
            $data->deadlineAt ?? throw new \LogicException('The sale form was not validated.'),
            $this->clock->now(),
        );
        $this->apply($sale, $data);

        $this->entityManager->persist($sale);
        $this->entityManager->flush();

        return $sale;
    }

    /**
     * Saves the changes of an existing sale. Stock counters are re-read under a row lock, so a
     * quantity limit never goes below what customers reserved in the meantime (SPEC §1.2).
     *
     * Deliveries are locked the same way, then checked against their orders: a delivery chosen by
     * an order (even cancelled) stays, and once customers have ordered, the delivery date and the
     * time window of their delivery no longer change (they were told by e-mail).
     *
     * @throws SaleEditConflict when the changes cannot be saved; nothing is written
     */
    public function update(Sale $sale, SaleData $data): void
    {
        $schedule = $sale->getSchedule();
        $day = $data->deliveryDate?->format('Y-m-d');
        if ($schedule instanceof SaleSchedule && $day !== $sale->getDeliveryDate()->format('Y-m-d')) {
            foreach ($this->sales->findBySchedule($schedule) as $other) {
                if ($other !== $sale && $other->getDeliveryDate()->format('Y-m-d') === $day) {
                    throw new SaleEditConflict('Ta récurrence a déjà une vente livrée ce jour-là.');
                }
            }
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->assertDeliveriesCanChange($sale, $data);

            foreach ($data->offers as $row) {
                $offerId = $row->offer?->getId();
                if (null === $row->offer || null === $offerId) {
                    continue;
                }

                // Locks the row and refreshes its counters.
                $this->offers->findForUpdate($offerId);
                $reserved = $row->offer->getReservedQty();
                $name = $row->product->getName();

                if (!$row->selected && $reserved > 0) {
                    throw new SaleEditConflict(\sprintf('« %s » vient d\'être commandé : tu ne peux plus le retirer de la vente.', $name));
                }

                if ($row->selected && null !== $row->maxQty && $row->maxQty < $reserved) {
                    throw new SaleEditConflict(\sprintf('« %s » : déjà %s réservés, tu ne peux pas descendre en dessous.', $name, $this->formatter->quantity($reserved, $row->product->getUnit())));
                }
            }

            $this->apply($sale, $data);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * Must run inside the transaction: the deliveries are locked (`SELECT … FOR UPDATE`) so that
     * an order placed meanwhile either is counted here or waits for the commit.
     */
    private function assertDeliveriesCanChange(Sale $sale, SaleData $data): void
    {
        $saleId = $sale->getId() ?? throw new \LogicException('The sale must be persisted.');
        $this->entityManager->getConnection()->executeQuery('SELECT id FROM sale_delivery WHERE sale_id = ? FOR UPDATE', [$saleId]);

        $counts = $this->saleOrders->countByDelivery($sale);
        $hasActiveOrders = [] !== array_filter($counts, static fn (array $count): bool => $count['active'] > 0);

        $newDay = $data->deliveryDate?->format('Y-m-d');
        if ($hasActiveOrders && $newDay !== $sale->getDeliveryDate()->format('Y-m-d')) {
            throw new SaleEditConflict('Des clients ont déjà commandé : la date de livraison ne peut plus changer.');
        }

        foreach ($data->deliveries as $row) {
            $delivery = $row->delivery;
            if (null === $delivery) {
                continue;
            }

            $count = $counts[(int) $delivery->getId()] ?? ['all' => 0, 'active' => 0];
            $name = $row->point->getName();

            if (!$row->selected && $count['all'] > 0) {
                throw new SaleEditConflict(\sprintf('Des clients ont commandé pour « %s » : tu ne peux plus retirer cette livraison.', $name));
            }

            if ($row->selected && $count['active'] > 0 && (
                $row->startTime?->format('H:i') !== $delivery->getStartsAt()->format('H:i')
                || $row->endTime?->format('H:i') !== $delivery->getEndsAt()->format('H:i')
            )) {
                throw new SaleEditConflict(\sprintf('Des clients ont commandé pour « %s » : ses horaires ne peuvent plus changer.', $name));
            }
        }
    }

    private function apply(Sale $sale, SaleData $data): void
    {
        $deliveryDate = $data->deliveryDate ?? throw new \LogicException('The sale form was not validated.');
        $deadlineAt = $data->deadlineAt ?? throw new \LogicException('The sale form was not validated.');

        $sale->setTitle($data->title);
        $sale->setDeliveryDate($deliveryDate);
        $sale->setOpensAt($data->opensAt ?? throw new \LogicException('The sale form was not validated.'));
        $sale->setDeadlineAt($deadlineAt);
        $sale->setAllowCustomerEdit($data->allowCustomerEdit);
        $sale->setEditCutoffAt(null === $data->editCutoffHours ? null : $deadlineAt->modify(\sprintf('-%d hours', $data->editCutoffHours)));

        $position = 0;
        foreach ($data->deliveries as $row) {
            if (!$row->selected) {
                if (null !== $row->delivery) {
                    $sale->removeDelivery($row->delivery);
                }

                continue;
            }

            $startsAt = $this->at($deliveryDate, $row->startTime ?? throw new \LogicException('The sale form was not validated.'));
            $endsAt = $this->at($deliveryDate, $row->endTime ?? throw new \LogicException('The sale form was not validated.'));

            if (null === $row->delivery) {
                new SaleDelivery($sale, $row->point, $startsAt, $endsAt, $position);
            } else {
                $row->delivery->setStartsAt($startsAt)->setEndsAt($endsAt)->setPosition($position);
            }

            ++$position;
        }

        foreach ($data->offers as $row) {
            if (!$row->selected) {
                if (null !== $row->offer) {
                    $sale->removeOffer($row->offer);
                }

                continue;
            }

            $offer = $row->offer ?? new SaleOffer($sale, $row->product);
            $offer->setPriceCents($row->priceCents ?? throw new \LogicException('The sale form was not validated.'));
            $offer->setMaxQty($row->maxQty);
        }
    }

    private function at(\DateTimeImmutable $day, \DateTimeImmutable $time): \DateTimeImmutable
    {
        return $day->setTime((int) $time->format('H'), (int) $time->format('i'));
    }
}

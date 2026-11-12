<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Model\Amounts;
use App\Accounting\Model\DetailRow;
use App\Accounting\Model\ProductRow;
use App\Accounting\Model\SaleRow;
use App\Accounting\Model\SalesJournal;
use App\Accounting\Model\VatRateRow;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderStatus;
use App\Shop\Entity\Shop;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the monthly sales journal of a shop.
 *
 * Scope: orders with the status `delivered`, in sales whose delivery date falls in the month.
 * Confirmed, not delivered and cancelled orders are left out. Orders placed under the franchise en
 * base de TVA (Order::isVatExempt(), lines at 0 %) are kept apart: their own VAT row, product and
 * detail rows, so the journal says which sales carry no VAT by law. Amounts are split line by line
 * by {@see VatCalculator} and then only added up (see its rounding rule).
 */
final readonly class SalesJournalBuilder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VatCalculator $vatCalculator,
    ) {
    }

    public function build(Shop $shop, \DateTimeImmutable $month): SalesJournal
    {
        $from = $month->modify('first day of this month')->setTime(0, 0);

        $total = new Amounts();
        $byVatRate = $byProduct = $bySale = $details = [];
        $ordersBySale = [];

        foreach ($this->findDeliveredLines($shop, $from) as $line) {
            $order = $line->getOrder();
            $sale = $order->getSale();
            $saleId = spl_object_id($sale);
            // Managed entities are unique per identity: object ids are safe grouping keys here.
            $rate = $line->getVatRateBp();
            $exempt = $order->isVatExempt();
            $rateKey = $exempt ? 'franchise' : \sprintf('%05d', $rate);
            $gross = $line->getTotalCents();
            $net = $this->vatCalculator->netFromGross($gross, $rate);
            // Billed quantity: the actual weight once entered (OrderLine::getBilledQuantity()).
            $amounts = new Amounts($line->getBilledQuantity(), $gross, $net, $gross - $net);

            $total = $total->plus($amounts);
            $ordersBySale[$saleId][spl_object_id($order)] = true;

            $byVatRate[$rateKey] = new VatRateRow($rate, ($byVatRate[$rateKey]->amounts ?? new Amounts())->plus($amounts), $exempt);

            $productKey = \sprintf('%d-%s-%s', spl_object_id($line->getSaleOffer()->getProduct()), $rateKey, $line->getUnit()->value);
            // Lines come in chronological order: the most recent label wins.
            $byProduct[$productKey] = new ProductRow(
                $line->getLabel(),
                $rate,
                ($byProduct[$productKey]->amounts ?? new Amounts())->plus($amounts),
                $line->getUnit(),
                $exempt,
            );

            $bySale[$saleId] = new SaleRow(
                $sale->getDeliveryDate(),
                $sale->getTitle(),
                $sale->getCode(),
                \count($ordersBySale[$saleId]),
                ($bySale[$saleId]->amounts ?? new Amounts())->plus($amounts),
            );

            $detailKey = $saleId.'-'.$productKey;
            $details[$detailKey] = new DetailRow(
                $sale->getDeliveryDate(),
                $sale->getTitle(),
                $sale->getCode(),
                $line->getLabel(),
                $rate,
                ($details[$detailKey]->amounts ?? new Amounts())->plus($amounts),
                $line->getUnit(),
                $exempt,
            );
        }

        ksort($byVatRate);
        $byProduct = array_values($byProduct);
        $collator = new \Collator('fr_FR');
        usort($byProduct, static function (ProductRow $a, ProductRow $b) use ($collator): int {
            $byLabel = $collator->compare($a->label, $b->label);

            return \is_int($byLabel) && 0 !== $byLabel ? $byLabel : [$a->vatExempt, $a->vatRateBp] <=> [$b->vatExempt, $b->vatRateBp];
        });

        return new SalesJournal(
            $from,
            array_sum(array_map(\count(...), $ordersBySale)),
            $total,
            array_values($byVatRate),
            $byProduct,
            array_values($bySale),
            array_values($details),
        );
    }

    /**
     * @return list<OrderLine>
     */
    private function findDeliveredLines(Shop $shop, \DateTimeImmutable $from): array
    {
        $lines = $this->entityManager->createQueryBuilder()
            ->select('l', 'o', 's', 'so', 'p')
            ->from(OrderLine::class, 'l')
            ->join('l.order', 'o')
            ->join('o.sale', 's')
            ->join('l.saleOffer', 'so')
            ->join('so.product', 'p')
            ->andWhere('s.shop = :shop')
            ->andWhere('o.status = :delivered')
            ->andWhere('s.deliveryDate >= :from')
            ->andWhere('s.deliveryDate < :to')
            ->setParameter('shop', $shop)
            ->setParameter('delivered', OrderStatus::Delivered)
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', $from->modify('first day of next month'), 'date_immutable')
            ->orderBy('s.deliveryDate', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(\is_array($lines) ? $lines : [], static fn (mixed $line): bool => $line instanceof OrderLine));
    }
}

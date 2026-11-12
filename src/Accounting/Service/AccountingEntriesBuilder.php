<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Csv\CsvWriter;
use App\Accounting\Entity\AccountingSettings;
use App\Accounting\Enum\AccountingCounterpart;
use App\Accounting\Model\EntryLine;
use App\Accounting\Model\JournalEntry;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Shop\Entity\Shop;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accounting entries of the sales journal (VE) of a month, ready to import in an accounting software.
 *
 * Same scope as the monthly journal ({@see SalesJournalBuilder}): orders marked `delivered`, in
 * sales delivered in the month, at their real amount (actual weight once entered). One entry per
 * order, dated on the delivery day, its reference as piece number:
 *
 * - debit of the counterpart account (411 generic customer, or 530 cash) with the amount due;
 * - credit of the sales account with the net amount, one line per VAT rate;
 * - credit of the VAT account with the VAT collected, one line per rate.
 *
 * No payment is ever recorded (SPEC §1): the 411 line says what the customer owes, the accountant
 * letters it against the bank or cash receipts. Orders under the franchise en base de TVA
 * (Order::isVatExempt()) have no VAT line and carry the mention of article 293 B. Splits follow the
 * rounding rule of {@see VatCalculator}, line by line, so every entry is balanced to the cent.
 */
final readonly class AccountingEntriesBuilder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VatCalculator $vatCalculator,
        private CsvWriter $csv,
    ) {
    }

    /**
     * @return list<JournalEntry>
     */
    public function build(Shop $shop, \DateTimeImmutable $month, AccountingSettings $settings): array
    {
        $entries = [];
        foreach ($this->findDeliveredOrders($shop, $month->modify('first day of this month')) as $order) {
            $entry = $this->entry($order, $settings);
            if ($entry instanceof JournalEntry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function entry(Order $order, AccountingSettings $settings): ?JournalEntry
    {
        $exempt = $order->isVatExempt();
        /** @var array<int, array{net: int, vat: int}> $byRate */
        $byRate = [];
        $gross = 0;

        foreach ($order->getLines() as $line) {
            $lineGross = $line->getTotalCents();
            // Lines of an order under the franchise are frozen at 0 %.
            $rate = $line->getVatRateBp();
            $net = $this->vatCalculator->netFromGross($lineGross, $rate);
            $byRate[$rate] ??= ['net' => 0, 'vat' => 0];
            $byRate[$rate]['net'] += $net;
            $byRate[$rate]['vat'] += $lineGross - $net;
            $gross += $lineGross;
        }

        if (0 === $gross) {
            return null;
        }
        ksort($byRate);

        $reference = $order->getReference();
        $counterpart = $settings->getCounterpart();
        $lines = [new EntryLine(
            $settings->counterpartAccount(),
            AccountingCounterpart::Customer === $counterpart ? 'Clients' : 'Caisse',
            \sprintf('%s %s', AccountingCounterpart::Customer === $counterpart ? 'Client' : 'Caisse', $order->getCustomerDisplayName()).' - cde '.$reference,
            $gross,
            0,
        )];

        foreach ($byRate as $rate => $amounts) {
            $lines[] = new EntryLine(
                $settings->getSalesAccount(),
                'Ventes',
                $exempt
                    ? 'Ventes franchise TVA art. 293 B CGI - cde '.$reference
                    : \sprintf('Ventes TVA %s - cde %s', $this->rate($rate), $reference),
                0,
                $amounts['net'],
            );
        }

        foreach ($byRate as $rate => $amounts) {
            if ($exempt || 0 === $amounts['vat']) {
                continue;
            }

            $lines[] = new EntryLine(
                $settings->getVatAccount(),
                'TVA collectée',
                \sprintf('TVA collectée %s - cde %s', $this->rate($rate), $reference),
                0,
                $amounts['vat'],
            );
        }

        return new JournalEntry($order->getSale()->getDeliveryDate(), $reference, $lines, $exempt);
    }

    /**
     * 550 → "5,5 %", with plain spaces: accounting software often chokes on non-breaking ones.
     */
    private function rate(int $basisPoints): string
    {
        return $this->csv->rate($basisPoints).' %';
    }

    /**
     * @return list<Order>
     */
    private function findDeliveredOrders(Shop $shop, \DateTimeImmutable $from): array
    {
        $orders = $this->entityManager->createQueryBuilder()
            ->select('o', 's', 'l')
            ->from(Order::class, 'o')
            ->join('o.sale', 's')
            ->leftJoin('o.lines', 'l')
            ->andWhere('s.shop = :shop')
            ->andWhere('o.status = :delivered')
            ->andWhere('s.deliveryDate >= :from')
            ->andWhere('s.deliveryDate < :to')
            ->setParameter('shop', $shop)
            ->setParameter('delivered', OrderStatus::Delivered)
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', $from->modify('first day of next month'), 'date_immutable')
            ->orderBy('s.deliveryDate', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        $delivered = [];
        foreach (\is_array($orders) ? $orders : [] as $order) {
            if ($order instanceof Order) {
                $delivered[] = $order;
            }
        }

        return $delivered;
    }
}

<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Csv\CsvWriter;
use App\Accounting\Model\DeliveryGroup;
use App\Accounting\Model\DeliverySheet;
use App\Accounting\Model\ProductionLine;
use App\Accounting\Model\ProductionSheet;
use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Entity\Sale;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Enum\VatRegime;

/**
 * Prepares the three exports of a sale: production sheet, delivery sheet and order CSV.
 * The caller is responsible for checking that the sale belongs to the current shop.
 */
final readonly class SaleExportBuilder
{
    public const array ORDERS_CSV_HEADER = [
        'Vente', 'Code vente', 'Date de livraison', 'Commande', 'Statut', 'Nom', 'Prénom', 'E-mail', 'Téléphone',
        'Point de livraison', 'Adresse de livraison', 'Note', 'Produit', 'Quantité', 'Unité', 'Poids réel', 'Prix unitaire TTC',
        'Taux de TVA (%)', 'Total ligne TTC', 'Total commande TTC', 'Mention TVA',
    ];

    public function __construct(
        private OrderRepository $orders,
        private CsvWriter $csv,
        private FrenchFormatter $formatter,
    ) {
    }

    /**
     * Totals per product over every order that is not cancelled. Quantities are what was ordered
     * (what to prepare); the amounts follow the actual weight once it is known.
     */
    public function productionSheet(Sale $sale): ProductionSheet
    {
        $orders = $this->orders->findBySale($sale);
        /** @var array<int, array{label: string, unit: ProductUnit, quantity: int, orders: int, total: int}> $byOffer */
        $byOffer = [];
        $itemCount = $totalCents = 0;

        foreach ($orders as $order) {
            foreach ($order->getLines() as $line) {
                $key = spl_object_id($line->getSaleOffer());
                $byOffer[$key] ??= [
                    'label' => $line->getLabel(),
                    'unit' => $line->getUnit(),
                    'quantity' => 0,
                    'orders' => 0,
                    'total' => 0,
                ];
                $byOffer[$key]['quantity'] += $line->getQuantity();
                ++$byOffer[$key]['orders'];
                $byOffer[$key]['total'] += $line->getTotalCents();
                $itemCount += $line->getItemCount();
                $totalCents += $line->getTotalCents();
            }
        }

        $lines = array_map(
            static fn (array $row): ProductionLine => new ProductionLine($row['label'], $row['unit'], $row['quantity'], $row['orders'], $row['total']),
            array_values($byOffer),
        );
        $collator = new \Collator('fr_FR');
        usort($lines, static fn (ProductionLine $a, ProductionLine $b): int => self::compare($collator, $a->label, $b->label));

        return new ProductionSheet($sale, \count($orders), $itemCount, $totalCents, $lines);
    }

    /**
     * Orders that are not cancelled, grouped by delivery point and sorted by customer name.
     */
    public function deliverySheet(Sale $sale): DeliverySheet
    {
        $orders = $this->orders->findBySale($sale);
        $collator = new \Collator('fr_FR');
        usort($orders, static fn (Order $a, Order $b): int => self::compare($collator, self::sortKey($a), self::sortKey($b)));

        $groups = [];
        $totalCents = 0;
        foreach ($sale->getDeliveries() as $delivery) {
            $groupOrders = array_values(array_filter($orders, static fn (Order $order): bool => $order->getSaleDelivery() === $delivery));
            if ([] === $groupOrders) {
                continue;
            }

            $groupTotal = array_sum(array_map(static fn (Order $order): int => $order->getTotalCents(), $groupOrders));
            $totalCents += $groupTotal;
            $groups[] = new DeliveryGroup($delivery, $groupOrders, $groupTotal);
        }

        return new DeliverySheet($sale, \count($orders), $totalCents, $groups);
    }

    /**
     * One row per order line, every status included (the "Statut" column tells them apart).
     * "Quantité" is what was ordered, "Poids réel" what was weighed (products sold by weight);
     * the line total follows the actual weight once it is known.
     */
    public function ordersCsv(Sale $sale): string
    {
        $rows = [];
        foreach ($this->orders->findBySale($sale, includeCancelled: true) as $order) {
            foreach ($order->getLines() as $line) {
                $rows[] = [
                    $sale->getTitle(),
                    $sale->getCode(),
                    $this->formatter->dateShort($sale->getDeliveryDate()),
                    $order->getReference(),
                    $order->getStatus()->label(),
                    $order->getCustomerLastName(),
                    $order->getCustomerFirstName(),
                    $order->getCustomer()->getEmail(),
                    $order->getCustomerPhone(),
                    $order->getSaleDelivery()->getDeliveryPoint()->getName(),
                    $order->getDeliveryAddress(),
                    $order->getNote(),
                    $line->getLabel(),
                    $this->csv->quantity($line->getQuantity()),
                    $line->getUnit()->short(),
                    null === $line->getActualQuantity() ? '' : $this->csv->quantity($line->getActualQuantity()),
                    $this->csv->amount($line->getUnitPriceCents()),
                    $this->csv->rate($line->getVatRateBp()),
                    $this->csv->amount($line->getTotalCents()),
                    $this->csv->amount($order->getTotalCents()),
                    $order->isVatExempt() ? VatRegime::FRANCHISE_MENTION : '',
                ];
            }
        }

        return $this->csv->write(self::ORDERS_CSV_HEADER, $rows);
    }

    private static function sortKey(Order $order): string
    {
        $name = trim(($order->getCustomerLastName() ?? '').' '.($order->getCustomerFirstName() ?? ''));

        return '' === $name ? $order->getCustomerDisplayName() : $name;
    }

    private static function compare(\Collator $collator, string $a, string $b): int
    {
        $result = $collator->compare($a, $b);

        return \is_int($result) ? $result : strcmp($a, $b);
    }
}

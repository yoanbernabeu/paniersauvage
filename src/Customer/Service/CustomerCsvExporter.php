<?php

declare(strict_types=1);

namespace App\Customer\Service;

use App\Accounting\Csv\CsvWriter;
use App\Customer\Model\ShopCustomer;

/**
 * "Mes clients" as a CSV file the producer keeps ("tes clients restent les tiens"): one line per
 * customer, every cell protected against formula injection (CsvWriter).
 */
final readonly class CustomerCsvExporter
{
    private const array HEADER = [
        'Nom', 'Prénom', 'E-mail', 'Téléphone', 'Sans e-mail', 'Abonné', 'Annonces par e-mail', 'Abonné depuis',
        'Commandes', 'Dernière commande', 'Total retiré (€)', 'Absences',
    ];

    public function __construct(
        private CsvWriter $writer,
    ) {
    }

    /**
     * @param list<ShopCustomer> $customers
     */
    public function export(array $customers): string
    {
        return $this->writer->write(self::HEADER, array_map(fn (ShopCustomer $customer): array => [
            $customer->erased ? 'Client effacé' : $customer->lastName,
            $customer->firstName,
            $customer->email,
            $customer->phone,
            $customer->withoutEmail ? 'oui' : 'non',
            $customer->follower ? 'oui' : 'non',
            $customer->emailConsent ? 'oui' : 'non',
            $customer->followedAt?->format('d/m/Y'),
            $customer->orderCount,
            $customer->lastOrderDate?->format('d/m/Y'),
            $this->writer->amount($customer->deliveredCents),
            $customer->absences,
        ], $customers));
    }
}

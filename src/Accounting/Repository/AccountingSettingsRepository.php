<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\AccountingSettings;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountingSettings>
 */
final class AccountingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountingSettings::class);
    }

    /**
     * The settings of the shop, or the defaults (not persisted) when it has none yet.
     */
    public function forShop(Shop $shop): AccountingSettings
    {
        return $this->findOneBy(['shop' => $shop]) ?? new AccountingSettings($shop);
    }
}
